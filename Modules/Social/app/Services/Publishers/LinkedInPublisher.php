<?php

namespace Modules\Social\Services\Publishers;

use Illuminate\Http\Client\ConnectionException;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * LinkedIn, through the member share endpoint.
 *
 * Deliberately does **not** implement `IngestsNotifications`. LinkedIn has no
 * self-serve notifications API — reading a member's notifications needs partner
 * access that an individual cannot obtain — so `social:sync-notifications`
 * skips this network by name and the page says so, rather than showing an empty
 * feed that reads as 'nothing happened'.
 *
 * The id comes back in the `x-restli-id` header as often as in the body, and
 * older accounts get one and newer ones the other, so both are read.
 *
 * **Pictures: three calls per image, and the middle one is not to LinkedIn.**
 * `POST /v2/assets?action=registerUpload` declares an intent and answers with
 * two things — a one-shot upload URL on LinkedIn's media host, and the asset URN
 * the finished picture will have. The bytes then go to that URL as a plain
 * `PUT`, which answers `201` with an empty body; there is nothing to decode and
 * a method that insisted on JSON would fail the successful case. Only then does
 * the share itself go out, with `shareMediaCategory` switched from `NONE` to
 * `IMAGE` and the asset URN quoted in `media`.
 *
 * The URN is known before the bytes are sent, which is what makes this survivable
 * on shared hosting: there is no polling step and no second lookup, so an image
 * post is a fixed three requests rather than an indefinite wait. It is also why
 * the `recipe` matters — `feedshare-image` is the only one this driver asks for,
 * and asking for a video recipe would return an upload URL that expects chunks.
 */
class LinkedInPublisher extends HttpPublisher
{
    private const ENDPOINT = 'https://api.linkedin.com/v2/ugcPosts';

    /** The cheapest call that proves a token belongs to a member. */
    private const USERINFO = 'https://api.linkedin.com/v2/userinfo';

    /** Where an upload is declared before any bytes move. */
    private const REGISTER_UPLOAD = 'https://api.linkedin.com/v2/assets?action=registerUpload';

    /** The only recipe this driver asks for. A video recipe would want chunks. */
    private const IMAGE_RECIPE = 'urn:li:digitalmediaRecipe:feedshare-image';

    /** Where in the register response the one-shot upload URL lives. */
    private const UPLOAD_MECHANISM = 'com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest';

    /** The API version header LinkedIn rejects the request without. */
    private const PROTOCOL_VERSION = '2.0.0';

    public function network(): string
    {
        return Networks::LINKEDIN;
    }

    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        $token = $this->require($account, 'access_token');
        $author = $this->author($account);
        $media = $this->acceptableMedia($media);
        $body = $this->bodyWithin($body, $media);

        $request = $this->request()->withToken($token)->withHeaders([
            'X-Restli-Protocol-Version' => self::PROTOCOL_VERSION,
        ]);

        $shared = [
            'shareCommentary' => ['text' => $body],
            'shareMediaCategory' => 'NONE',
        ];

        $assets = $this->uploadAll($token, $author, $media);

        if ($assets !== []) {
            $shared['shareMediaCategory'] = 'IMAGE';
            $shared['media'] = $assets;
        }

        try {
            $response = $request->post(self::ENDPOINT, [
                'author' => $author,
                'lifecycleState' => 'PUBLISHED',
                'specificContent' => [
                    'com.linkedin.ugc.ShareContent' => $shared,
                ],
                'visibility' => [
                    'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
                ],
            ]);
        } catch (ConnectionException $e) {
            // The central redaction rather than the raw message. This driver
            // authenticates in a header, so nothing secret is in the URL today —
            // but a connection failure's message is written to
            // `post_targets.error` and rendered on a page, and the next endpoint
            // added here should not have to remember that. See
            // `HttpPublisher::cannotReach()` for why it is not a `str_replace`.
            throw $this->cannotReach(self::ENDPOINT, $e);
        }

        if ($response->failed()) {
            throw PublishFailed::status($this->network(), $response->status(), $this->detailFrom($response));
        }

        // The header is authoritative when present; the body carries the same
        // urn for accounts on the older response shape.
        $id = $response->header('x-restli-id');

        if ($id === '') {
            $body = $response->json();
            $id = is_array($body) && is_string($body['id'] ?? null) ? $body['id'] : '';
        }

        if ($id === '') {
            throw PublishFailed::malformed($this->network(), 'the response carried no share urn');
        }

        return new PublishedPost($id, $this->webUrl($id));
    }

    public function verify(SocialAccount $account): string
    {
        $response = $this->send(
            $this->request()->withToken($this->require($account, 'access_token')),
            'get',
            self::USERINFO,
        );

        $name = $response['name'] ?? $response['sub'] ?? null;

        if (! is_string($name) || $name === '') {
            throw PublishFailed::malformed($this->network(), 'the token was accepted but no member came back');
        }

        return $name;
    }

    /**
     * Register, upload, and name each picture, in that order.
     *
     * @param  list<MediaItem>  $media
     * @return list<array{status: string, media: string}>
     *
     * @throws PublishFailed
     */
    private function uploadAll(string $token, string $author, array $media): array
    {
        $assets = [];

        foreach ($media as $item) {
            [$uploadUrl, $asset] = $this->register($token, $author, $item);

            // No `X-Restli-Protocol-Version` and no bearer scheme quirks here:
            // this URL is on LinkedIn's media host, is single use, and already
            // carries its own authorisation in the query string. The token is
            // still sent because LinkedIn's own documentation does.
            $this->putBytes(
                $this->uploadRequest()->withToken($token),
                $uploadUrl,
                $item->contents(),
                $item->mime,
            );

            $assets[] = [
                'status' => 'READY',
                'media' => $asset,
            ];
        }

        return $assets;
    }

    /**
     * Declare one upload and take the URL and the URN it answers with.
     *
     * @return array{0: string, 1: string} the one-shot upload URL and the asset URN
     *
     * @throws PublishFailed
     */
    private function register(string $token, string $author, MediaItem $item): array
    {
        $response = $this->send(
            $this->request()->withToken($token)->withHeaders([
                'X-Restli-Protocol-Version' => self::PROTOCOL_VERSION,
            ]),
            'post',
            self::REGISTER_UPLOAD,
            [
                'registerUploadRequest' => [
                    'recipes' => [self::IMAGE_RECIPE],
                    'owner' => $author,
                    'serviceRelationships' => [[
                        'relationshipType' => 'OWNER',
                        'identifier' => 'urn:li:userGeneratedContent',
                    ]],
                ],
            ],
        );

        $uploadUrl = $response['value']['uploadMechanism'][self::UPLOAD_MECHANISM]['uploadUrl'] ?? null;
        $asset = $response['value']['asset'] ?? null;

        if (! is_string($uploadUrl) || $uploadUrl === '' || ! is_string($asset) || $asset === '') {
            throw PublishFailed::malformed(
                $this->network(),
                'registering the upload of “'.$item->name.'” returned no upload URL, so the post was not created',
            );
        }

        return [$uploadUrl, $asset];
    }

    /**
     * The author urn, however the person entered it.
     *
     * People paste the bare id from the userinfo `sub` claim as often as the
     * full urn, and a share posted with the wrong shape is refused with a 403
     * that reads like the token expired.
     */
    private function author(SocialAccount $account): string
    {
        $urn = trim($this->require($account, 'member_urn'));

        return str_starts_with($urn, 'urn:li:') ? $urn : 'urn:li:person:'.$urn;
    }

    /** The public permalink LinkedIn builds from the share urn. */
    private function webUrl(string $urn): ?string
    {
        return str_contains($urn, ':')
            ? 'https://www.linkedin.com/feed/update/'.$urn
            : null;
    }
}
