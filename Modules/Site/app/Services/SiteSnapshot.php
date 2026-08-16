<?php

namespace Modules\Site\Services;

use Illuminate\Support\Facades\Cache;

/**
 * What the site is, in one object, cheap enough to draw a page from.
 *
 * ## Why this is cached and the lists are not
 *
 * Every page in this module talks to somebody else's server over the internet.
 * A list of posts is *asked for* — a person clicked a page of results and is
 * willing to wait for it, and showing them a cached page of posts after they
 * edited one would be a bug. This object is different: it is the identity of
 * the connection and the shape of the install, it is needed by every page in
 * the module to decide what to even draw, and it changes when somebody
 * installs a plugin rather than when they write a paragraph.
 *
 * So: the answer is cached, the lists are not, and the cache is keyed on the
 * account row so that re-pasting a credential for a different site cannot be
 * served the previous site's capabilities.
 *
 * ## Five minutes
 *
 * Long enough that clicking between the module's pages costs one round trip
 * rather than one per page. Short enough that somebody who has just activated
 * Rank Math and come back to Kargah to use it is not told for an hour that it
 * is not installed. Anything that changes the site from *inside* Kargah — a
 * connection re-check, a plugin toggle — busts the key itself rather than
 * waiting, which is what makes the five minutes tolerable.
 *
 * ## Failure is a value here, not an exception
 *
 * `WordPressSite` throws, because a list page with no list has nothing to draw.
 * This class catches, because the overview page's entire job is to *report* the
 * state of the connection, and a page that fatals when the connection is broken
 * reports nothing. {@see self::$error} carries the sentence; every other field
 * is null or empty beside it.
 *
 * The failure is **not** cached. A five-minute memory of "your site is down" is
 * five minutes of a person fixing it and being told it is still broken.
 */
class SiteSnapshot
{
    /**
     * @param  list<string>  $namespaces  REST namespaces the install registers
     * @param  list<string>  $roles  the credential owner's WordPress roles
     * @param  array<string, bool>  $capabilities  the subset this module cares about
     */
    private function __construct(
        public readonly bool $connected,
        public readonly ?string $siteUrl = null,
        public readonly ?string $name = null,
        public readonly ?string $description = null,
        public readonly ?string $userName = null,
        public readonly array $roles = [],
        public readonly array $capabilities = [],
        public readonly array $namespaces = [],
        public readonly ?string $error = null,
    ) {}

    /**
     * The capabilities worth asking about, and what each one unlocks here.
     *
     * A deliberately short list. WordPress ships dozens and reporting all of
     * them would be a wall of jargon; these four are the ones that decide
     * whether a page in this module can do its job, so they are the ones the
     * overview can explain in a sentence each.
     */
    public const CAPABILITIES = [
        'edit_posts' => 'Write and edit posts',
        'edit_pages' => 'Write and edit pages',
        'upload_files' => 'Upload to the media library',
        'manage_categories' => 'Manage categories and tags',
    ];

    /** Rank Math registers this namespace when it is active. */
    public const RANK_MATH_NAMESPACE = 'rankmath/v1';

    /**
     * Namespaces that mean a cache plugin is present and answering.
     *
     * A list rather than one string because the host decides this, not the
     * owner: Hostinger ships LiteSpeed, other hosts ship WP Rocket or W3 Total
     * Cache, and a site behind Cloudflare has a fourth answer that is not a
     * plugin at all. Everything here is checked and whatever is found is what
     * the cache page offers.
     */
    public const CACHE_NAMESPACES = [
        'litespeed/v1',
        'wp-rocket/v1',
        'w3tc/v1',
    ];

    public static function disconnected(): self
    {
        return new self(connected: false);
    }

    public static function failed(string $siteUrl, string $error): self
    {
        return new self(connected: false, siteUrl: $siteUrl, error: $error);
    }

    /**
     * Ask the site, or hand back what it said within the last five minutes.
     *
     * @throws never
     */
    public static function of(?WordPressSite $site, bool $fresh = false): self
    {
        if ($site === null) {
            return self::disconnected();
        }

        $key = self::key($site);

        if ($fresh) {
            Cache::forget($key);
        }

        /** @var self|null $cached */
        $cached = Cache::get($key);

        if ($cached instanceof self) {
            return $cached;
        }

        $snapshot = self::fetch($site);

        // Only a good answer is remembered. See the class docblock.
        if ($snapshot->connected) {
            Cache::put($key, $snapshot, now()->addMinutes(5));
        }

        return $snapshot;
    }

    public static function forget(WordPressSite $site): void
    {
        Cache::forget(self::key($site));
    }

    private static function key(WordPressSite $site): string
    {
        return 'site:snapshot:'.$site->account()->getKey();
    }

    private static function fetch(WordPressSite $site): self
    {
        try {
            $url = $site->baseUrl();
        } catch (SiteRequestFailed $e) {
            return self::failed('', $e->getMessage());
        }

        try {
            $root = $site->get('');
            $me = $site->whoami();
        } catch (SiteRequestFailed $e) {
            return self::failed($url, $e->getMessage());
        }

        $capabilities = [];

        foreach (array_keys(self::CAPABILITIES) as $capability) {
            // WordPress sends `capabilities` as a map of name => true and omits
            // the ones the user does not have, so a missing key is a `false`
            // rather than an unknown. `true` is checked loosely because the API
            // has sent `1` for it as long as it has sent it at all.
            $capabilities[$capability] = (bool) (($me['capabilities'] ?? [])[$capability] ?? false);
        }

        return new self(
            connected: true,
            siteUrl: $url,
            name: is_string($root['name'] ?? null) ? $root['name'] : null,
            description: is_string($root['description'] ?? null) ? $root['description'] : null,
            userName: is_string($me['name'] ?? null) ? $me['name'] : null,
            roles: array_values(array_filter(
                array_map(
                    fn ($role): string => is_string($role) ? $role : '',
                    is_array($me['roles'] ?? null) ? $me['roles'] : [],
                ),
                fn (string $role): bool => $role !== '',
            )),
            capabilities: $capabilities,
            namespaces: array_values(array_filter(
                array_map(
                    fn ($value): string => is_string($value) ? $value : '',
                    is_array($root['namespaces'] ?? null) ? $root['namespaces'] : [],
                ),
                fn (string $value): bool => $value !== '',
            )),
        );
    }

    public function hasRankMath(): bool
    {
        return in_array(self::RANK_MATH_NAMESPACE, $this->namespaces, strict: true);
    }

    /** Which cache plugin answered, or null when none did. */
    public function cacheNamespace(): ?string
    {
        foreach (self::CACHE_NAMESPACES as $namespace) {
            if (in_array($namespace, $this->namespaces, strict: true)) {
                return $namespace;
            }
        }

        return null;
    }

    /**
     * Capabilities the credential is missing, by their readable name.
     *
     * @return list<string>
     */
    public function missingCapabilities(): array
    {
        $missing = [];

        foreach (self::CAPABILITIES as $capability => $label) {
            if (($this->capabilities[$capability] ?? false) === false) {
                $missing[] = $label;
            }
        }

        return $missing;
    }
}
