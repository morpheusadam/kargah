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
 */
class BlueskyPublisher extends HttpPublisher implements IngestsNotifications
{
    public const HOST = 'https://bsky.social';

    /** The public web front end, where a post record becomes a link a person can open. */
    private const APP_HOST = 'https://bsky.app';

    private const POST_COLLECTION = 'app.bsky.feed.post';

    public function network(): string
    {
        return Networks::BLUESKY;
    }

    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        $session = $this->session($account);

        $response = $this->send(
            $this->request()->withToken($session['token']),
            'post',
            self::HOST.'/xrpc/com.atproto.repo.createRecord',
            [
                'repo' => $session['did'],
                'collection' => self::POST_COLLECTION,
                'record' => [
                    '$type' => self::POST_COLLECTION,
                    'text' => $body,
                    // The protocol wants an ISO timestamp on the record itself;
                    // it is what the client sorts by, not the server's receipt.
                    'createdAt' => now()->toIso8601ZuluString(),
                ],
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
