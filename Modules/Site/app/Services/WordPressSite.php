<?php

namespace Modules\Site\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * The website, as something Kargah can read and write.
 *
 * ## There is no second connection
 *
 * A WordPress site is already a `social_accounts` row — `Modules\Blog`'s
 * provider says so at length, and `Networks::WORDPRESS` already names the three
 * credentials this needs: `site_url`, `username`, `application_password`. So
 * this module connects nothing. It resolves that row, and everything it does is
 * done with the credential somebody already pasted on the connect page.
 *
 * The alternative — a `site_connections` table with its own encrypted column,
 * its own connect page and its own idea of whether the site is reachable — was
 * rejected because it would make one site into two rows that can disagree, and
 * the first disagreement is a person revoking the application password in
 * WordPress and Kargah reporting the site as connected on one page and broken
 * on another.
 *
 * ⚠️ **This widens what that credential is used for, and the connect page's own
 * copy currently promises otherwise.** `Networks::WORDPRESS['permissions']`
 * tells the reader Kargah can create posts and cannot "edit or delete anything
 * that was already on the site". That was true when the only driver was
 * `WordPressPublisher`. It is not true of this module, and the promise has to be
 * rewritten rather than quietly outgrown — see `SiteModuleTest`, which fails
 * while the old wording is still there.
 *
 * ## Application passwords, not OAuth
 *
 * WordPress has shipped application passwords in core since 5.6 and the REST
 * API since 4.7, so nothing here asks the owner to install a plugin before
 * Kargah can talk to their site. The password goes over HTTPS as HTTP Basic,
 * which is what WordPress's own `wp_authenticate_application_password` expects;
 * it is not a fallback and not a downgrade.
 *
 * 🔴 **Over plain HTTP it would be a credential in cleartext on the wire.**
 * {@see self::baseUrl()} refuses an `http://` site rather than shrugging, and
 * that refusal is deliberate friction: WordPress itself disables application
 * passwords on a site that is not served over TLS, so a site that needs the
 * exception has a broken certificate rather than a good reason.
 *
 * ## Timeouts
 *
 * Shorter than `HttpPublisher`'s upload budget and for the opposite reason. A
 * publish runs in a queue worker with cron watching; these requests run inside
 * a Livewire round trip with a person waiting, and a page that hangs for thirty
 * seconds has already failed even if the answer eventually arrives. Ten seconds
 * to first byte, five to connect, and no automatic retry on a write — retrying
 * a `POST /wp/v2/posts` that timed out after the site had already accepted it
 * is how one article becomes two.
 */
class WordPressSite
{
    /** Seconds to wait for a site that accepted the connection and then went quiet. */
    public const TIMEOUT = 10;

    /** Seconds for the connection itself. A host that is down is down. */
    public const CONNECT_TIMEOUT = 5;

    /**
     * Attempts for a read.
     *
     * Reads are safe to repeat, and one retry covers the single most common
     * failure on shared hosting: a cold PHP worker that drops the first
     * connection of the minute.
     */
    public const READ_TRIES = 2;

    public const RETRY_BACKOFF_MS = 300;

    public function __construct(private readonly SocialAccount $account) {}

    /**
     * The connected site, or null when there is not one.
     *
     * "Connected" is `SocialAccount::isConnected()`'s definition, which is the
     * one the accounts page draws: active, not soft-deleted, and carrying every
     * credential field the network declares. A row with a site URL and no
     * application password is not a site anything can be asked of, and treating
     * it as one only moves the failure to the first request.
     *
     * The newest row wins when there is more than one. Connecting a second
     * WordPress site replaces the credential on the same handle today — see
     * `⚡account-connect` — so more than one row means two genuinely different
     * sites, and the last one somebody set up is the better guess than the
     * first. Multi-site is a real feature and this is not it; it is a
     * deterministic answer instead of an arbitrary one.
     */
    public static function connected(): ?self
    {
        $account = SocialAccount::query()
            ->where('network', Networks::WORDPRESS)
            ->where('is_active', true)
            ->orderByDesc('connected_at')
            ->orderByDesc('id')
            ->get()
            ->first(fn (SocialAccount $account): bool => $account->isConnected());

        return $account === null ? null : new self($account);
    }

    /**
     * The connected site, or an exception naming why there is not one.
     *
     * For callers that cannot render a half-page — a command, a job — where the
     * null check would only be rewritten into the same throw.
     *
     * @throws SiteRequestFailed
     */
    public static function require(): self
    {
        return self::connected() ?? throw SiteRequestFailed::notConnected();
    }

    public function account(): SocialAccount
    {
        return $this->account;
    }

    /**
     * The site's home URL, normalised, with the scheme guaranteed.
     *
     * A trailing slash is stripped here rather than in every caller, and a
     * missing scheme is filled in as `https://` rather than refused: the connect
     * page's hint asks for one, people paste `example.com` anyway, and guessing
     * the secure scheme is both the safe guess and the one that fails loudly on
     * a site that genuinely has no certificate.
     *
     * @throws SiteRequestFailed
     */
    public function baseUrl(): string
    {
        $url = trim((string) $this->account->credential('site_url'));

        if ($url === '') {
            throw SiteRequestFailed::notConnected();
        }

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            $url = 'https://'.$url;
        }

        if (str_starts_with($url, 'http://')) {
            throw new SiteRequestFailed(
                'The site URL is plain HTTP. An application password sent over http:// travels in cleartext, '
                .'and WordPress disables application passwords on a site without TLS. Fix the certificate, then '
                .'change the URL to https:// on the connect page.',
            );
        }

        return rtrim($url, '/');
    }

    /**
     * A fully-qualified REST URL for a route.
     *
     * `?rest_route=` is deliberately not used as a fallback. It is the query
     * form WordPress serves when pretty permalinks are off, and supporting both
     * would mean guessing which one this install answers on every request. The
     * pretty form is what every modern install has; when it 404s, the site has
     * a real problem worth reporting rather than working around.
     */
    public function url(string $route): string
    {
        return $this->baseUrl().'/wp-json/'.ltrim($route, '/');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function get(string $route, array $query = []): array
    {
        return $this->decode($this->send('get', $route, $query, retry: true), $this->url($route));
    }

    /**
     * A read whose headers matter as much as its body.
     *
     * WordPress paginates with `X-WP-Total` and `X-WP-TotalPages` rather than
     * in the body, so a list page that only decoded JSON would have to fetch
     * every page to learn how many there were. Returned as a shape rather than
     * by handing back the `Response` so that no caller is tempted to reach past
     * this class for the rest of it.
     *
     * @param  array<string, mixed>  $query
     * @return array{items: array<array-key, mixed>, total: int, pages: int}
     *
     * @throws SiteRequestFailed
     */
    public function paginate(string $route, array $query = []): array
    {
        $response = $this->send('get', $route, $query, retry: true);

        $items = $this->decode($response, $this->url($route));

        return [
            'items' => $items,
            // A route that does not paginate sends neither header. Falling back
            // to the row count rather than to zero keeps "1 of 1" honest for
            // the settings and plugin endpoints, which return a bare object.
            'total' => (int) ($response->header('X-WP-Total') ?: count($items)),
            'pages' => (int) ($response->header('X-WP-TotalPages') ?: 1),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function post(string $route, array $payload = []): array
    {
        return $this->decode($this->send('post', $route, $payload, retry: false), $this->url($route));
    }

    /**
     * Delete, and say whether it was a trash or a shredder.
     *
     * `force` is passed through rather than defaulted, because the two are
     * different acts: without it WordPress moves a post to the trash, where it
     * can be restored; with it the row is gone. Every caller in this module
     * that deletes content passes `false` and offers restore, and the ones that
     * pass `true` — a media attachment, which WordPress refuses to trash — say
     * so in their own copy.
     *
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function delete(string $route, bool $force = false): array
    {
        $url = $this->url($route);

        try {
            $response = $this->request(retry: false)->delete($url, $force ? ['force' => true] : []);
        } catch (ConnectionException $e) {
            throw SiteRequestFailed::unreachable($url, $e->getMessage());
        }

        return $this->decode($response, $url);
    }

    /**
     * Upload bytes to the media library.
     *
     * Multipart is deliberately not used. WordPress's media endpoint accepts a
     * raw body with `Content-Disposition: attachment; filename="…"`, and the
     * multipart form it also accepts is the path that trips over
     * `upload_max_filesize` reported as a bare 500 on some shared hosts. Raw
     * body, longer timeout, one attempt — re-sending eight megabytes because
     * the first try timed out after the site had stored it is how one image
     * becomes two in the library.
     *
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function upload(string $filename, string $contents, string $mime): array
    {
        $url = $this->url('wp/v2/media');

        try {
            $response = $this->request(retry: false)
                ->timeout(60)
                ->withBody($contents, $mime)
                ->withHeaders(['Content-Disposition' => 'attachment; filename="'.$filename.'"'])
                ->post($url);
        } catch (ConnectionException $e) {
            throw SiteRequestFailed::unreachable($url, $e->getMessage());
        }

        return $this->decode($response, $url);
    }

    /**
     * What this install can actually do, read from the site rather than assumed.
     *
     * `GET /wp-json/` returns every registered namespace, which is how Kargah
     * finds out that Rank Math is installed (`rankmath/v1`) or that a cache
     * plugin exposes a purge route, without asking the owner to tell it and
     * without a version matrix that goes stale. A namespace that is not there
     * is a feature this module hides rather than a page that 404s when clicked.
     *
     * @return list<string>
     *
     * @throws SiteRequestFailed
     */
    public function namespaces(): array
    {
        $root = $this->get('');

        $namespaces = $root['namespaces'] ?? [];

        if (! is_array($namespaces)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn ($value): string => is_string($value) ? $value : '', $namespaces),
            fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * Who the credential belongs to, and what it is allowed to do.
     *
     * `context=edit` is the point of the call rather than a detail: without it
     * WordPress returns the public view of the user and omits `capabilities`
     * entirely, so a check that only asked for the name would report a
     * subscriber's password as a working connection and discover the truth on
     * the first write.
     *
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function whoami(): array
    {
        return $this->get('wp/v2/users/me', ['context' => 'edit']);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws SiteRequestFailed
     */
    private function send(string $method, string $route, array $payload, bool $retry): Response
    {
        $url = $this->url($route);

        try {
            return $method === 'get'
                ? $this->request($retry)->get($url, $payload)
                : $this->request($retry)->post($url, $payload);
        } catch (ConnectionException $e) {
            throw SiteRequestFailed::unreachable($url, $e->getMessage());
        }
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    private function decode(Response $response, string $url): array
    {
        if ($response->failed()) {
            throw SiteRequestFailed::refused($response, $url);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw SiteRequestFailed::malformed($url);
        }

        return $body;
    }

    /**
     * `throw: false` on the retry so a 500 comes back as a response this class
     * can read WordPress's own error message out of, rather than as a
     * `RequestException` whose message is the first 120 characters of the body.
     */
    private function request(bool $retry): PendingRequest
    {
        $request = Http::acceptJson()
            ->withBasicAuth(
                (string) $this->account->credential('username'),
                (string) $this->account->credential('application_password'),
            )
            ->timeout(self::TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT);

        return $retry
            ? $request->retry(self::READ_TRIES, self::RETRY_BACKOFF_MS, throw: false)
            : $request;
    }
}
