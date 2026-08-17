<?php

namespace Modules\Social\Services\Curation\Sources;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * The network policy every source shares.
 *
 * Kept in one place because it is a hosting constraint rather than a per-source
 * detail, exactly as `Modules\Accounting\Services\RateSources\HttpRateSource`
 * has it: this command reads forty-odd endpoints inside one cron minute on
 * shared hosting, so a handful that each hang for thirty seconds would take the
 * whole run down. Short timeouts, two retries, then give up and let the rest
 * through.
 *
 * 🔴 **The user agent has to look like a browser.** With a bot-shaped one a
 * large share of publishers answer 403 — Cloudflare and its like do not care
 * that the request is polite. This was measured on the pipeline this is ported
 * from, and it is the difference between a feed working and a feed appearing to
 * be permanently empty. Sites behind a JavaScript challenge stay unreadable
 * either way and are expected to.
 */
abstract class HttpSource implements Source
{
    /**
     * Seconds to wait on an endpoint that accepted the connection then went quiet.
     *
     * Longer than the rate sources allow, because a news site's own feed handler
     * is frequently slower than a bank's API and there are forty of them rather
     * than three — but still inside the budget of a single cron minute.
     */
    protected const TIMEOUT = 10;

    /** Seconds for the connection itself. Shorter on purpose: a host that is down is down. */
    protected const CONNECT_TIMEOUT = 5;

    /** Attempts in total, not retries after the first. */
    protected const TRIES = 2;

    protected const RETRY_BACKOFF_MS = 300;

    /**
     * Headers that make this look like somebody reading the site.
     *
     * Public because the cover-image resolver needs the identical set — it
     * fetches the same article pages this reads the feeds of, and a different
     * user agent there would mean a page that parsed for one and 403'd for the
     * other. One definition, deliberately shared, rather than the same string
     * typed twice and drifting.
     */
    public const BROWSER = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            .'(KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
    ];

    /** Most sources need no credential and are therefore always available. */
    public function unavailableReason(): ?string
    {
        return null;
    }

    /** Most sources publish often enough for the general window. */
    public function maxAgeHours(): ?float
    {
        return null;
    }

    /**
     * `throw: false` because an upstream 500 is not exceptional enough to unwind
     * the stack — it is one of forty sources having a bad morning, and the
     * methods below turn it into a named failure themselves.
     */
    protected function request(): PendingRequest
    {
        return Http::withHeaders(static::BROWSER)
            ->timeout(static::TIMEOUT)
            ->connectTimeout(static::CONNECT_TIMEOUT)
            ->retry(static::TRIES, static::RETRY_BACKOFF_MS, throw: false);
    }

    /**
     * One GET, decoded as JSON, with every way it can go wrong named.
     *
     * @param  array<string, string|int>  $query
     * @return array<array-key, mixed>
     *
     * @throws SourceFailed
     */
    protected function getJson(string $url, array $query = []): array
    {
        $response = $this->fetchResponse($url, $query, json: true);

        $body = $response->json();

        if (! is_array($body)) {
            throw SourceFailed::malformed($this->label(), 'the body did not decode as JSON');
        }

        return $body;
    }

    /**
     * One GET, returned as the raw body, for the sources that are XML.
     *
     * Deliberately not decoded here. `simplexml_load_string()` reports its own
     * errors and `RssFeed` needs to turn those into a `SourceFailed` naming the
     * feed, which it cannot do if the parse already happened somewhere else.
     *
     * @throws SourceFailed
     */
    protected function getBody(string $url): string
    {
        $body = $this->fetchResponse($url, [], json: false)->body();

        if (trim($body) === '') {
            throw SourceFailed::malformed($this->label(), 'the response was empty');
        }

        return $body;
    }

    /**
     * @param  array<string, string|int>  $query
     *
     * @throws SourceFailed
     */
    private function fetchResponse(string $url, array $query, bool $json): \Illuminate\Http\Client\Response
    {
        $request = $json ? $this->request()->acceptJson() : $this->request();

        try {
            $response = $request->get($url, $query);
        } catch (ConnectionException $e) {
            throw SourceFailed::unreachable($this->label(), $e->getMessage());
        }

        if ($response->failed()) {
            throw SourceFailed::status($this->label(), $response->status());
        }

        return $response;
    }

    /**
     * A publication date in UTC, or null when the value is not one.
     *
     * Null rather than an exception, and the caller drops the story. Feeds put
     * genuinely unparseable rubbish in date fields often enough that treating it
     * as a source-level failure would cost the other twenty items in the same
     * feed for the sake of one.
     *
     * `Carbon::parse()` handles both shapes that turn up here — ISO 8601 from
     * JSON APIs and Atom, RFC 2822 from RSS `<pubDate>` — so there is no need to
     * guess which one arrived.
     */
    protected function parseTime(?string $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->setTimezone('UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Feed markup reduced to the plain text a summariser can use.
     *
     * Four things, in this order, and the order matters. Tags become spaces
     * before entities are decoded, so that `&lt;b&gt;` in an escaped snippet does
     * not turn into markup that the tag strip has already run past. Runs of
     * horizontal whitespace collapse. Then the boilerplate lines that `hnrss` and
     * its imitators append to every single item — "Article URL:", "Points:" — are
     * dropped, because they are identical on every story and therefore pure noise
     * both to the model and to the cluster signature.
     */
    protected function clean(string $raw): string
    {
        $text = preg_replace('/<[^>]+>/', ' ', $raw) ?? $raw;
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $text = preg_replace('/[ \t]*\R[ \t]*/u', "\n", $text) ?? $text;
        $text = preg_replace('/[ \t]{2,}/u', ' ', $text) ?? $text;

        $text = preg_replace(
            '/^[ \t]*(Article URL|Comments URL|Points|# Comments)\s*:.*$/mu',
            '',
            $text,
        ) ?? $text;

        return trim(preg_replace('/\n{2,}/u', "\n", $text) ?? $text);
    }

    /**
     * The host that actually published something, without `www.`.
     *
     * Hacker News and Lobsters are link aggregators: the story belongs to
     * somebody else and a post that credits the aggregator reads as though the
     * aggregator wrote it. Null for a self-post, which has no other publisher.
     */
    protected function publisherOf(?string $url): ?string
    {
        $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

        if ($host === '') {
            return null;
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
