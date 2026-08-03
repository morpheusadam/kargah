<?php

namespace Modules\Social\Services\Publishers;

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
 */
class LinkedInPublisher extends HttpPublisher
{
    private const ENDPOINT = 'https://api.linkedin.com/v2/ugcPosts';

    /** The cheapest call that proves a token belongs to a member. */
    private const USERINFO = 'https://api.linkedin.com/v2/userinfo';

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

        $request = $this->request()->withToken($token)->withHeaders([
            'X-Restli-Protocol-Version' => self::PROTOCOL_VERSION,
        ]);

        try {
            $response = $request->post(self::ENDPOINT, [
                'author' => $author,
                'lifecycleState' => 'PUBLISHED',
                'specificContent' => [
                    'com.linkedin.ugc.ShareContent' => [
                        'shareCommentary' => ['text' => $body],
                        'shareMediaCategory' => 'NONE',
                    ],
                ],
                'visibility' => [
                    'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
                ],
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw PublishFailed::unreachable($this->network(), $e->getMessage());
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
