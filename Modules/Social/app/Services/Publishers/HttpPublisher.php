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
