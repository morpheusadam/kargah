<?php

namespace Modules\Social\Services\Publishers;

use Illuminate\Support\Carbon;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * Mastodon, through the instance the account lives on.
 *
 * The simplest of the four and the reason it is here: one bearer token, one
 * POST, and a response that already contains the id and the public url. There
 * is no central host, so the instance is a credential rather than a constant.
 *
 * An `Idempotency-Key` derived from the account and the exact text is sent with
 * every status. Mastodon honours it for an hour, which means a job that sends
 * successfully and is then killed before it can record the id does not put a
 * second copy on the timeline when the stale claim is picked up again. The
 * database claim is still the primary guarantee; this closes the window the
 * database cannot see into.
 */
class MastodonPublisher extends HttpPublisher implements IngestsNotifications
{
    public function network(): string
    {
        return Networks::MASTODON;
    }

    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        $token = $this->require($account, 'access_token');

        $response = $this->send(
            $this->request()->withToken($token)->withHeaders([
                'Idempotency-Key' => $this->idempotencyKey($account, $body),
            ]),
            'post',
            $this->endpoint($account, '/api/v1/statuses'),
            ['status' => $body],
        );

        $id = $response['id'] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            throw PublishFailed::malformed($this->network(), 'the response carried no status id');
        }

        $url = $response['url'] ?? $response['uri'] ?? null;

        return new PublishedPost((string) $id, is_string($url) ? $url : null);
    }

    public function verify(SocialAccount $account): string
    {
        $response = $this->send(
            $this->request()->withToken($this->require($account, 'access_token')),
            'get',
            $this->endpoint($account, '/api/v1/accounts/verify_credentials'),
        );

        $handle = $response['acct'] ?? null;

        if (! is_string($handle) || $handle === '') {
            throw PublishFailed::malformed($this->network(), 'the token was accepted but no account came back');
        }

        return '@'.ltrim($handle, '@');
    }

    public function notifications(SocialAccount $account, int $limit = 40): array
    {
        $token = $this->require($account, 'access_token');

        $response = $this->send(
            $this->request()->withToken($token),
            'get',
            $this->endpoint($account, '/api/v1/notifications'),
            ['limit' => $limit],
        );

        $items = [];

        foreach ($response as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? null;

            // No id, no idempotency. Skipped rather than given a made-up one,
            // which would write a fresh row on every run.
            if (! is_scalar($id) || (string) $id === '') {
                continue;
            }

            $items[] = new InboundNotification(
                remoteId: (string) $id,
                kind: InboundNotification::kindFrom((string) ($item['type'] ?? '')),
                actorHandle: $this->handleOf($item),
                excerpt: InboundNotification::excerptFrom($item['status']['content'] ?? null),
                url: is_string($item['status']['url'] ?? null) ? $item['status']['url'] : null,
                occurredAt: $this->timeOf($item['created_at'] ?? null),
            );
        }

        return $items;
    }

    /**
     * The instance base, with a trailing slash and any stray path removed.
     *
     * People paste `https://mastodon.social/` and `mastodon.social/@me` alike,
     * and a url built by concatenation onto either produces a 404 that reads
     * like the network rejected the post.
     */
    private function endpoint(SocialAccount $account, string $path): string
    {
        $instance = rtrim($this->require($account, 'instance'), '/');

        if (! str_starts_with($instance, 'http://') && ! str_starts_with($instance, 'https://')) {
            $instance = 'https://'.$instance;
        }

        $host = parse_url($instance, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw PublishFailed::rejected($this->network(), 'the instance URL is not a host Kargah can reach');
        }

        return 'https://'.$host.$path;
    }

    /** @param array<array-key, mixed> $item */
    private function handleOf(array $item): ?string
    {
        $handle = $item['account']['acct'] ?? null;

        return is_string($handle) && $handle !== '' ? '@'.ltrim($handle, '@') : null;
    }

    private function timeOf(mixed $raw): ?Carbon
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            // A timestamp Kargah cannot read is worth less than the row it is
            // attached to; the feed sorts undated items last rather than losing them.
            return null;
        }
    }

    /**
     * Stable for the same account and the same text, and nothing else.
     *
     * Deliberately not the target id: the point is to survive a retry of the
     * same content, and the id is already what the database claim keys on.
     */
    private function idempotencyKey(SocialAccount $account, string $body): string
    {
        return hash('sha256', $account->getKey().'|'.$body);
    }
}
