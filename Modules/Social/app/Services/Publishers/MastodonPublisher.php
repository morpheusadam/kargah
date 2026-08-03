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
 *
 * **Pictures: upload each, then quote the ids.** `POST /api/v2/media` takes one
 * file per call as multipart and answers with an attachment that has an id;
 * `media_ids` on the status is what turns those ids into a post. Four images
 * therefore cost five requests, which is why the count is capped in the
 * catalogue rather than left open.
 *
 * The v2 endpoint answers **202** rather than 200 when it has accepted a file
 * it has not finished processing, and an id from a 202 is still valid — posting
 * a status that names it makes Mastodon wait for processing before publishing.
 * For a still image that wait is imperceptible. For a video it is not, and that
 * is one of the concrete reasons video is out of scope here: doing it properly
 * means polling `/api/v1/media/:id` until it stops answering 206, inside a job
 * that has a `max_execution_time` to answer to.
 *
 * The idempotency key covers the text, not the pictures. Two runs of the same
 * post therefore re-upload the images and then collapse onto the same status,
 * which wastes an upload in a case that only happens when a worker was killed —
 * a good trade against keying on bytes and having an edited image silently
 * publish the old one.
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
        $media = $this->acceptableMedia($media);
        $body = $this->bodyWithin($body, $media);

        $payload = ['status' => $body];

        $mediaIds = $this->uploadAll($account, $token, $media);

        if ($mediaIds !== []) {
            $payload['media_ids'] = $mediaIds;
        }

        $response = $this->send(
            $this->request()->withToken($token)->withHeaders([
                'Idempotency-Key' => $this->idempotencyKey($account, $body),
            ]),
            'post',
            $this->endpoint($account, '/api/v1/statuses'),
            $payload,
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
     * Every image, uploaded one call at a time, in order.
     *
     * Order is the whole reason this is a loop rather than anything cleverer:
     * `media_ids` is a sequence and the timeline renders it in the order given,
     * so the ids have to come back in the order the pictures were attached.
     *
     * @param  list<MediaItem>  $media
     * @return list<string>
     *
     * @throws PublishFailed
     */
    private function uploadAll(SocialAccount $account, string $token, array $media): array
    {
        $ids = [];

        foreach ($media as $item) {
            $response = $this->sendMultipart(
                $this->uploadRequest()->withToken($token),
                $this->endpoint($account, '/api/v2/media'),
                ['file' => [$item->filename(), $item->contents(), $item->mime]],
            );

            $id = $response['id'] ?? null;

            if (! is_scalar($id) || (string) $id === '') {
                throw PublishFailed::malformed(
                    $this->network(),
                    'the upload of “'.$item->name.'” carried no media id, so the post was not created',
                );
            }

            $ids[] = (string) $id;
        }

        return $ids;
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
