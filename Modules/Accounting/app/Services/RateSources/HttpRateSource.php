<?php

namespace Modules\Accounting\Services\RateSources;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * The network policy every source shares.
 *
 * Kept in one place because it is a hosting constraint rather than a per-source
 * detail: on shared hosting the command runs inside `max_execution_time` with
 * cron watching, so three sources that each hang for thirty seconds would take
 * the whole job down with them. Short timeouts, two retries, then give up and
 * let the other sources through.
 */
abstract class HttpRateSource implements RateSource
{
    /** Seconds to wait for a provider that has accepted the connection but stopped talking. */
    protected const TIMEOUT = 8;

    /** Seconds to wait for the connection itself. Deliberately shorter — a host that is down is down. */
    protected const CONNECT_TIMEOUT = 4;

    /** Attempts in total, not retries after the first. */
    protected const TRIES = 3;

    protected const RETRY_BACKOFF_MS = 250;

    /** Most sources need no key and are therefore always available. */
    public function unavailableReason(): ?string
    {
        return null;
    }

    /**
     * Overridden by sources that must add credentials.
     *
     * `throw: false` because a 500 from a provider is not exceptional enough to
     * unwind the stack — it is one of three sources having a bad day, and the
     * command turns it into a reported failure itself.
     */
    protected function request(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(static::TIMEOUT)
            ->connectTimeout(static::CONNECT_TIMEOUT)
            ->retry(static::TRIES, static::RETRY_BACKOFF_MS, throw: false);
    }

    /**
     * One GET, decoded, with every way it can go wrong named.
     *
     * @param  array<string, string>  $query
     * @return array<array-key, mixed>
     *
     * @throws RateSourceFailed
     */
    protected function get(string $url, array $query = []): array
    {
        try {
            $response = $this->request()->get($url, $query);
        } catch (ConnectionException $e) {
            throw RateSourceFailed::unreachable($this->name(), $e->getMessage());
        }

        if ($response->failed()) {
            throw RateSourceFailed::status($this->name(), $response->status());
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw RateSourceFailed::malformed($this->name(), 'the body did not decode as JSON');
        }

        return $body;
    }

    /**
     * Build a quote, reporting a nonsensical number as this source's failure.
     *
     * `Quote::of()` rejects anything that is not a positive number. Whose fault
     * that was matters to whoever reads the log, so the source's name is
     * attached here rather than letting a bare argument exception escape.
     *
     * @throws RateSourceFailed
     */
    protected function quote(
        string $base,
        string $quote,
        mixed $raw,
        string $rateType,
        Carbon|string $asOf,
    ): Quote {
        try {
            return Quote::of($base, $quote, $raw, $rateType, $asOf);
        } catch (\InvalidArgumentException $e) {
            throw RateSourceFailed::malformed($this->name(), $e->getMessage());
        }
    }
}
