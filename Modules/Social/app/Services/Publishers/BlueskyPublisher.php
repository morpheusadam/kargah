<?php

namespace Modules\Social\Services\Publishers;

use Illuminate\Support\Carbon;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * Bluesky, over the AT protocol.
 *
 * Two calls rather than one: an app password buys a short-lived session token,
 * and the post is a record written into the account's own repository. The
 * session is not cached between jobs on purpose — Kargah has no daemon, so a
 * cached token would live in the database and be one more secret to protect for
 * the sake of saving a request that happens a few times a day.
 *
 * Only an app password is accepted, and the connect page says so. A Bluesky
 * account password grants everything including deleting the account; an app
 * password can be revoked on its own and is the only credential worth storing.
 *
 * **Pictures: upload a blob, embed what comes back.** `uploadBlob` takes the
 * raw file as the entire request body — no multipart, no field name, the MIME
 * type in the `Content-Type` header — and answers with a blob reference. That
 * reference, verbatim, goes into an `app.bsky.embed.images` embed on the record.
 * Sending the file as multipart instead uploads perfectly and stores the
 * multipart framing as the image, which renders as a broken picture rather than
 * as an error, so the raw-body transport here is load bearing.
 *
 * The blob reference is passed through untouched rather than reassembled from
 * its `$type`, `ref` and `size` fields. It is a content-addressed structure the
 * server built and will verify; rebuilding it in PHP would be four chances to
 * change one byte for no benefit.
 *
 * **`alt` is sent empty and that is a known gap.** The lexicon requires the key,
 * Kargah has nowhere to type alt text yet, and a filename is not a description —
 * `screenshot-2026-08-04.png` read aloud is worse than silence. A per-image alt
 * field in the composer is the fix, and it is a composer feature rather than a
 * driver one.
 */
class BlueskyPublisher extends HttpPublisher implements IngestsNotifications
{
    public const HOST = 'https://bsky.social';

    /** The public web front end, where a post record becomes a link a person can open. */
    private const APP_HOST = 'https://bsky.app';

    private const POST_COLLECTION = 'app.bsky.feed.post';

    private const IMAGES_EMBED = 'app.bsky.embed.images';

    public function network(): string
    {
        return Networks::BLUESKY;
    }

    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        $media = $this->acceptableMedia($media);
        $body = $this->bodyWithin($body, $media);

        $session = $this->session($account);

        $record = [
            '$type' => self::POST_COLLECTION,
            'text' => $body,
            // The protocol wants an ISO timestamp on the record itself;
            // it is what the client sorts by, not the server's receipt.
            'createdAt' => now()->toIso8601ZuluString(),
        ];

        $images = $this->uploadAll($session['token'], $media);

        if ($images !== []) {
            $record['embed'] = [
                '$type' => self::IMAGES_EMBED,
                'images' => $images,
            ];
        }

        $response = $this->send(
            $this->request()->withToken($session['token']),
            'post',
            self::HOST.'/xrpc/com.atproto.repo.createRecord',
            [
                'repo' => $session['did'],
                'collection' => self::POST_COLLECTION,
                'record' => $record,
            ],
        );

        $uri = $response['uri'] ?? null;

        if (! is_string($uri) || $uri === '') {
            throw PublishFailed::malformed($this->network(), 'the response carried no record URI');
        }

        return new PublishedPost($uri, $this->webUrl($session['handle'], $uri));
    }

    /**
     * Signing in *is* the check here.
     *
     * The AT protocol has no cheaper identity call than `createSession`, and
     * the session it returns is thrown away — which is the honest cost of
     * confirming an app password before a scheduled post depends on it.
     */
    public function verify(SocialAccount $account): string
    {
        return '@'.$this->session($account)['handle'];
    }

    public function notifications(SocialAccount $account, int $limit = 40): array
    {
        $session = $this->session($account);

        $response = $this->send(
            $this->request()->withToken($session['token']),
            'get',
            self::HOST.'/xrpc/app.bsky.notification.listNotifications',
            ['limit' => $limit],
        );

        $items = [];

        foreach ($response['notifications'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $uri = $item['uri'] ?? null;

            // The record URI is the only globally stable id Bluesky gives, so
            // an item without one is skipped rather than given an invented key.
            if (! is_string($uri) || $uri === '') {
                continue;
            }

            $handle = $item['author']['handle'] ?? null;

            $items[] = new InboundNotification(
                remoteId: $uri,
                kind: InboundNotification::kindFrom((string) ($item['reason'] ?? '')),
                actorHandle: is_string($handle) && $handle !== '' ? '@'.$handle : null,
                excerpt: InboundNotification::excerptFrom($item['record']['text'] ?? null),
                url: is_string($handle) ? $this->webUrl($handle, $uri) : null,
                occurredAt: $this->timeOf($item['indexedAt'] ?? null),
            );
        }

        return $items;
    }

    /**
     * Every image, as blobs the record can embed.
     *
     * The session is already open when this runs, so each upload reuses the
     * token rather than signing in again per picture — four images on a post
     * would otherwise cost four extra `createSession` calls, and Bluesky rate
     * limits those far more tightly than uploads.
     *
     * @param  list<MediaItem>  $media
     * @return list<array{alt: string, image: array<array-key, mixed>}>
     *
     * @throws PublishFailed
     */
    private function uploadAll(string $token, array $media): array
    {
        $images = [];

        foreach ($media as $item) {
            $response = $this->sendBytes(
                $this->uploadRequest()->withToken($token),
                self::HOST.'/xrpc/com.atproto.repo.uploadBlob',
                $item->contents(),
                $item->mime,
            );

            $blob = $response['blob'] ?? null;

            if (! is_array($blob) || $blob === []) {
                throw PublishFailed::malformed(
                    $this->network(),
                    'the upload of “'.$item->name.'” carried no blob reference, so the post was not created',
                );
            }

            $images[] = [
                // Required by the lexicon and empty until the composer has an
                // alt-text field — see the class docblock.
                'alt' => '',
                'image' => $blob,
            ];
        }

        return $images;
    }

    /**
     * Trade the app password for a session.
     *
     * @return array{token: string, did: string, handle: string}
     *
     * @throws PublishFailed
     */
    private function session(SocialAccount $account): array
    {
        $response = $this->send(
            $this->request(),
            'post',
            self::HOST.'/xrpc/com.atproto.server.createSession',
            [
                'identifier' => ltrim($this->require($account, 'identifier'), '@'),
                'password' => $this->require($account, 'app_password'),
            ],
        );

        $token = $response['accessJwt'] ?? null;
        $did = $response['did'] ?? null;

        if (! is_string($token) || $token === '' || ! is_string($did) || $did === '') {
            throw PublishFailed::malformed($this->network(), 'the sign-in response carried no session token');
        }

        $handle = $response['handle'] ?? null;

        return [
            'token' => $token,
            'did' => $did,
            'handle' => is_string($handle) && $handle !== '' ? $handle : $did,
        ];
    }

    /**
     * The human-readable link for a record URI.
     *
     * `at://did:plc:abc/app.bsky.feed.post/3kxyz` becomes
     * `https://bsky.app/profile/<handle>/post/3kxyz`. The last segment is the
     * record key and the only part of the URI the web front end wants.
     */
    private function webUrl(string $handle, string $uri): ?string
    {
        $rkey = mb_strrchr($uri, '/');

        if ($rkey === false || $rkey === '/') {
            return null;
        }

        return self::APP_HOST.'/profile/'.ltrim($handle, '@').'/post/'.ltrim($rkey, '/');
    }

    private function timeOf(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
