<?php

namespace Modules\Social\Support;

use Illuminate\Support\Str;

/**
 * The `Authorization: OAuth …` header X still asks for, and the one rule that
 * decides whether it works.
 *
 * **Why 1.0a and not OAuth 2.0.** X's v2 write endpoints accept a user context
 * signed this way, and that turns out to be the only reason X is in Kargah at
 * all. OAuth 2.0 would mean a redirect, a callback URL this install may not
 * have, a PKCE exchange and a refresh token with a clock on it — a handshake,
 * and Kargah deliberately has nowhere to run one (see `⚡account-connect`).
 * OAuth 1.0a issues no expiry and no refresh: the developer portal generates an
 * access token and secret for your own account with one click, the four strings
 * are pasted into a form, and they stay good until somebody revokes or
 * regenerates them. That is exactly the shape of every other credential here.
 *
 * 🔴 **The rule that costs a day if you get it wrong.** The signature base
 * string is built from the `oauth_*` parameters plus the **query string**, and
 * from nothing else. The request body is included *only* when it is
 * `application/x-www-form-urlencoded`, and neither of X's two calls is: the
 * tweet is a JSON body and the media upload is multipart. So this class has no
 * parameter for a body, on purpose — there is no argument you could pass that
 * would make it sign one, and therefore no way to sign the wrong thing. A
 * signature that included the JSON is refused with HTTP 401 and a message that
 * says nothing about why, which is the single most common way a hand-written
 * OAuth 1.0a client fails.
 *
 * The corollary is worth stating too: `GET /2/users/me?user.fields=username`
 * **does** have to sign `user.fields=username`, because that is a query
 * parameter. Dropping it fails identically to including the body.
 *
 * **Encoding is RFC 3986 and `urlencode()` is not it.** PHP's `urlencode()`
 * writes a space as `+`; the spec wants `%20`, and every `rawurlencode()` here
 * is load bearing rather than a style preference. The same applies to the
 * signing key, where both secrets are encoded before they are joined even
 * though X's secrets happen to contain nothing that encodes.
 *
 * **The nonce and the timestamp can be injected**, which is what makes a
 * signature testable at all: pass them and the header is a pure function of its
 * inputs, so a test can assert an exact string and would catch a change to any
 * of the encoding rules above. Left out, the nonce comes from `Str::random()`
 * and the timestamp from `now()` — both of which the framework can freeze in a
 * test as well, which is why they are used in preference to `random_bytes()`
 * and `time()`.
 */
final class OAuth1
{
    private const SIGNATURE_METHOD = 'HMAC-SHA1';

    private const VERSION = '1.0';

    /**
     * A nonce only has to be unique per timestamp for one consumer key. Thirty
     * two characters of `Str::random()` is roughly 190 bits, which is unique
     * enough that the question never comes up.
     */
    private const NONCE_LENGTH = 32;

    public function __construct(
        private readonly string $consumerKey,
        private readonly string $consumerSecret,
        private readonly string $token,
        private readonly string $tokenSecret,
    ) {}

    /**
     * The whole header value, ready for `withHeaders(['Authorization' => …])`.
     *
     * Only the `oauth_*` parameters go in the header. The query parameters were
     * signed, and they travel in the URL where they already are — repeating
     * them here is refused.
     *
     * @param  array<string, string>  $query  the request's query parameters, if any
     */
    public function header(
        string $method,
        string $url,
        array $query = [],
        ?string $nonce = null,
        ?int $timestamp = null,
    ): string {
        $oauth = [
            'oauth_consumer_key' => $this->consumerKey,
            'oauth_nonce' => $nonce ?? Str::random(self::NONCE_LENGTH),
            'oauth_signature_method' => self::SIGNATURE_METHOD,
            'oauth_timestamp' => (string) ($timestamp ?? now()->getTimestamp()),
            'oauth_token' => $this->token,
            'oauth_version' => self::VERSION,
        ];

        $oauth['oauth_signature'] = $this->signature($method, $url, array_merge($query, $oauth));

        // Sorted so the header a given set of inputs produces is one string
        // rather than one of several. X does not care about the order; a test
        // asserting an exact header does.
        ksort($oauth, SORT_STRING);

        $parts = [];

        foreach ($oauth as $key => $value) {
            $parts[] = rawurlencode($key).'="'.rawurlencode($value).'"';
        }

        return 'OAuth '.implode(', ', $parts);
    }

    /**
     * `HMAC-SHA1` over the base string, keyed with both secrets.
     *
     * Public because it is the half worth asserting on directly — a test that
     * only checked the finished header would pass on a signature computed over
     * the wrong string as long as the wrapper still looked right.
     *
     * @param  array<string, string>  $params  the `oauth_*` parameters plus the query, never a body
     */
    public function signature(string $method, string $url, array $params): string
    {
        $key = rawurlencode($this->consumerSecret).'&'.rawurlencode($this->tokenSecret);

        return base64_encode(hash_hmac('sha1', $this->baseString($method, $url, $params), $key, true));
    }

    /**
     * `METHOD&url&params`, each of the three percent-encoded once.
     *
     * The URL is signed without its query string and the query is signed as
     * parameters, so `…/2/users/me?user.fields=username` and `…/2/users/me`
     * with `['user.fields' => 'username']` produce the same base string. Both
     * spellings are accepted for that reason: a caller cannot be quietly wrong
     * by passing the URL it already has.
     *
     * @param  array<string, string>  $params
     */
    public function baseString(string $method, string $url, array $params): string
    {
        [$url, $inline] = $this->split($url);

        $encoded = [];

        foreach (array_merge($inline, $params) as $key => $value) {
            $encoded[rawurlencode((string) $key)] = rawurlencode((string) $value);
        }

        // By encoded key, as bytes. The spec breaks a tie on the encoded value
        // next, which cannot happen here: a PHP array holds one value per key,
        // and X has no endpoint that takes a repeated parameter.
        ksort($encoded, SORT_STRING);

        $pairs = [];

        foreach ($encoded as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        return strtoupper($method).'&'.rawurlencode($url).'&'.rawurlencode(implode('&', $pairs));
    }

    /**
     * A URL split into the part that gets signed and the parameters that do.
     *
     * Hand-rolled rather than `parse_str()`, which is not a small preference:
     * `parse_str()` rewrites `.` and ` ` in a parameter name to `_`, so
     * `user.fields` would arrive as `user_fields`, sign perfectly, and be
     * refused by X with a 401 that names neither the parameter nor the reason.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function split(string $url): array
    {
        $mark = strpos($url, '?');

        if ($mark === false) {
            return [$url, []];
        }

        $params = [];

        foreach (explode('&', substr($url, $mark + 1)) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

            $params[rawurldecode($key)] = rawurldecode($value);
        }

        return [substr($url, 0, $mark), $params];
    }
}
