<?php

namespace Modules\Social\Services\Publishers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * The network policy every publisher shares.
 *
 * Kept in one place because it is a hosting constraint rather than a per-network
 * detail: a post going to four networks runs inside `max_execution_time` with
 * cron watching, so four providers that each hang for thirty seconds would take
 * the job down with them. Short timeouts, two retries, then give up and let the
 * other targets through.
 *
 * The default `unavailableReason()` answers the case this machine is actually
 * in — no credentials configured. It says which fields are missing and that the
 * post was not sent, because 'not connected' on its own reads as a bug rather
 * than as something the reader can fix.
 */
abstract class HttpPublisher implements Publisher
{
    /** Seconds to wait for a network that has accepted the connection but stopped talking. */
    protected const TIMEOUT = 10;

    /** Seconds to wait for the connection itself. Deliberately shorter — a host that is down is down. */
    protected const CONNECT_TIMEOUT = 5;

    /** Attempts in total, not retries after the first. */
    protected const TRIES = 3;

    protected const RETRY_BACKOFF_MS = 400;

    /**
     * The same policy, relaxed for a request that carries bytes.
     *
     * A JSON post is a few hundred characters and ten seconds is generous. A
     * ten-megabyte image on a domestic upstream is not, and killing it at ten
     * seconds would fail every picture post from a normal connection while the
     * text-only ones sailed through. Thirty seconds, and **two** attempts
     * rather than three: re-sending ten megabytes because the first try timed
     * out is how one target eats the whole job's execution budget and takes the
     * post's other networks down with it.
     *
     * Four images at 30 s worst case is 120 s, which is why the composer caps
     * the count against `Networks::media()` before anything is queued rather
     * than letting a person attach twenty.
     */
    protected const UPLOAD_TIMEOUT = 30;

    protected const UPLOAD_TRIES = 2;

    public function unavailableReason(SocialAccount $account): ?string
    {
        if (! $account->is_active) {
            return $account->label().' is switched off in Kargah, so nothing was sent to it.';
        }

        $missing = [];

        foreach (Networks::credentialFields($this->network()) as $field) {
            if ($account->credential($field) === null) {
                $missing[] = Networks::all()[$this->network()]['credentials'][$field]['label'] ?? $field;
            }
        }

        if ($missing === []) {
            return null;
        }

        return $account->label().' credentials are not configured — '
            .implode(' and ', $missing).' '.(count($missing) === 1 ? 'is' : 'are')
            .' missing, so the post was not sent.';
    }

    /**
     * `throw: false` because a 500 from a network is not exceptional enough to
     * unwind the stack — it is one of four targets having a bad day, and the
     * publisher turns it into a recorded failure itself.
     */
    protected function request(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(static::TIMEOUT)
            ->connectTimeout(static::CONNECT_TIMEOUT)
            ->retry(static::TRIES, static::RETRY_BACKOFF_MS, throw: false);
    }

    /**
     * One request, decoded, with every way it can go wrong named.
     *
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     *
     * @throws PublishFailed
     */
    protected function send(PendingRequest $request, string $method, string $url, array $payload = []): array
    {
        try {
            /** @var Response $response */
            $response = $method === 'get'
                ? $request->get($url, $payload)
                : $request->post($url, $payload);
        } catch (ConnectionException $e) {
            throw PublishFailed::unreachable($this->network(), $e->getMessage());
        }

        if ($response->failed()) {
            throw PublishFailed::status($this->network(), $response->status(), $this->detailFrom($response));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw PublishFailed::malformed($this->network(), 'the body did not decode as JSON');
        }

        return $body;
    }

    /**
     * The same policy as `request()`, sized for a request that carries a file.
     *
     * Kept separate rather than parameterised so that the ordinary publish path
     * cannot accidentally acquire an upload's patience: a text post that hangs
     * for thirty seconds is a bug, and it should keep failing in ten.
     */
    protected function uploadRequest(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(static::UPLOAD_TIMEOUT)
            ->connectTimeout(static::CONNECT_TIMEOUT)
            ->retry(static::UPLOAD_TRIES, static::RETRY_BACKOFF_MS, throw: false);
    }

    /**
     * A multipart POST: form fields plus one or more files, decoded as JSON.
     *
     * Mastodon, Telegram and Discord all take a picture this way and no two of
     * them agree on anything else about it — the part name, whether the fields
     * are flat or a JSON blob in one of them, how many files one request may
     * carry. So this deliberately does the smallest shared thing (turn strings
     * into parts, send, decode) and leaves every one of those decisions to the
     * driver. There is no generic "upload media" here because there is no
     * generic media upload; see the note on `Publisher::publish()`.
     *
     * The files arrive as `[part name => [filename, bytes, mime]]` rather than
     * as `MediaItem`s because Telegram's media group names its parts `file0`,
     * `file1`, Discord's names them `files[0]`, and Mastodon sends exactly one
     * called `file`. The caller knows which; this does not.
     *
     * @param  array<string, array{0: string, 1: string, 2: string}>  $files  part name => [filename, bytes, mime]
     * @param  array<string, string>  $fields
     * @return array<array-key, mixed>
     *
     * @throws PublishFailed
     */
    protected function sendMultipart(PendingRequest $request, string $url, array $files, array $fields = []): array
    {
        foreach ($files as $part => [$filename, $bytes, $mime]) {
            $request = $request->attach($part, $bytes, $filename, ['Content-Type' => $mime]);
        }

        try {
            /** @var Response $response */
            $response = $request->post($url, $fields);
        } catch (ConnectionException $e) {
            throw PublishFailed::unreachable($this->network(), $e->getMessage());
        }

        if ($response->failed()) {
            throw PublishFailed::status($this->network(), $response->status(), $this->detailFrom($response));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw PublishFailed::malformed($this->network(), 'the upload response did not decode as JSON');
        }

        return $body;
    }

    /**
     * Raw bytes as the whole request body, decoded as JSON.
     *
     * Bluesky's `uploadBlob` wants this and only this: the image is the body,
     * its MIME type is the `Content-Type` header, and there is no envelope of
     * any kind. Sending it as multipart produces a blob whose bytes are the
     * multipart framing, which uploads perfectly and renders as a broken image.
     *
     * @return array<array-key, mixed>
     *
     * @throws PublishFailed
     */
    protected function sendBytes(PendingRequest $request, string $url, string $bytes, string $mime): array
    {
        try {
            /** @var Response $response */
            $response = $request
                ->withBody($bytes, $mime)
                ->post($url);
        } catch (ConnectionException $e) {
            throw PublishFailed::unreachable($this->network(), $e->getMessage());
        }

        if ($response->failed()) {
            throw PublishFailed::status($this->network(), $response->status(), $this->detailFrom($response));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw PublishFailed::malformed($this->network(), 'the upload response did not decode as JSON');
        }

        return $body;
    }

    /**
     * Raw bytes to a `PUT` that answers with nothing worth reading.
     *
     * LinkedIn's registered upload is this shape: the URL came from a previous
     * call, the bytes go up, and a `201` with an empty body is the success. So
     * this returns void rather than an array — there is nothing to decode, and
     * a method that insisted on JSON would fail on the successful case.
     *
     * @throws PublishFailed
     */
    protected function putBytes(PendingRequest $request, string $url, string $bytes, string $mime): void
    {
        try {
            /** @var Response $response */
            $response = $request
                ->withBody($bytes, $mime)
                ->put($url);
        } catch (ConnectionException $e) {
            throw PublishFailed::unreachable($this->network(), $e->getMessage());
        }

        if ($response->failed()) {
            throw PublishFailed::status($this->network(), $response->status(), $this->detailFrom($response));
        }
    }

    /**
     * Refuse more pictures than this network takes, before any of them move.
     *
     * The composer checks the same catalogue while a person is attaching, which
     * is where the message is actually useful. This is the backstop for the
     * path that has no composer in it: a post scheduled last week, attached to
     * then, published by cron today after somebody added a fifth image on the
     * post page. Cheap, and it fails before the first byte leaves rather than
     * after three of four uploads have succeeded.
     *
     * @param  list<MediaItem>  $media
     * @return list<MediaItem>
     *
     * @throws PublishFailed
     */
    protected function acceptableMedia(array $media): array
    {
        if ($media === []) {
            return [];
        }

        $rules = Networks::media($this->network());

        if (count($media) > $rules['max_count']) {
            throw PublishFailed::rejected(
                $this->network(),
                'it takes at most '.$rules['max_count'].' '
                .($rules['max_count'] === 1 ? 'image' : 'images').' and this post has '.count($media),
            );
        }

        foreach ($media as $item) {
            if (! in_array($item->mime, $rules['mimes'], true)) {
                throw PublishFailed::rejected(
                    $this->network(),
                    'it does not accept '.$item->mime.' — “'.$item->name.'” cannot go to this network',
                );
            }

            if ($item->sizeBytes > $rules['max_bytes']) {
                throw PublishFailed::rejected(
                    $this->network(),
                    '“'.$item->name.'” is '.round($item->sizeBytes / 1048576, 1).' MB and the limit is '
                    .round($rules['max_bytes'] / 1048576, 1).' MB',
                );
            }
        }

        return $media;
    }

    /**
     * The copy this network will take given what is attached to it.
     *
     * Only Telegram shortens, and only when there is a picture — but every
     * driver asks, because the alternative is each driver remembering whether
     * it is the one that does.
     *
     * @param  list<MediaItem>  $media
     *
     * @throws PublishFailed
     */
    protected function bodyWithin(string $body, array $media): string
    {
        $limit = Networks::limitWithMedia($this->network(), $media !== []);

        if (mb_strlen($body) <= $limit) {
            return $body;
        }

        // Refused rather than truncated. The composer has a Trim to fit button
        // for exactly this and shows the count while you type; silently cutting
        // somebody's last sentence off at send time would publish something
        // they did not write.
        throw PublishFailed::rejected(
            $this->network(),
            'the copy is '.mb_strlen($body).' characters and '
            .($media === [] ? 'this network allows ' : 'a post with an image attached allows ').$limit,
        );
    }

    /**
     * Whatever the network said about the refusal, trimmed to fit an error cell.
     *
     * Networks disagree on where the reason lives, so this looks in the three
     * places they use and falls back to the raw body. The point is that the
     * person reading the failed post sees the provider's own words rather than
     * a status code on its own.
     */
    protected function detailFrom(Response $response): string
    {
        $body = $response->json();

        $detail = is_array($body)
            ? ($body['error_description'] ?? $body['error'] ?? $body['message'] ?? $body['description'] ?? null)
            : null;

        if (! is_string($detail) || trim($detail) === '') {
            $detail = trim($response->body());
        }

        return $detail === '' ? '' : mb_substr($detail, 0, 300);
    }

    /**
     * A credential the driver cannot work without.
     *
     * `unavailableReason()` has already been asked before `publish()` runs, so
     * reaching this and finding nothing means the account changed underneath
     * the job — reported as a refusal rather than a null dereference.
     *
     * @throws PublishFailed
     */
    protected function require(SocialAccount $account, string $field): string
    {
        $value = $account->credential($field);

        if ($value === null) {
            throw PublishFailed::rejected($this->network(), 'the '.$field.' credential went missing between queueing and sending');
        }

        return $value;
    }
}
