<?php

namespace Modules\Site\Services;

/**
 * Purging the website's page cache.
 *
 * ## The fact this class is shaped around
 *
 * **No major WordPress cache plugin exposes a purge endpoint over the REST
 * API.** Each one has a perfectly good programmatic entry point and every one
 * of them is a PHP hook meant to be called from inside WordPress:
 *
 * | Plugin           | How you purge everything      |
 * |------------------|-------------------------------|
 * | LiteSpeed Cache  | `do_action('litespeed_purge_all')` |
 * | WP Rocket        | `rocket_clean_domain()`       |
 * | W3 Total Cache   | `w3tc_flush_all()`            |
 *
 * Corroborated on 17 August 2026 from LiteSpeed's own API documentation and
 * their 2018 post on purging via hooks, WP Rocket's knowledge-base article on
 * `rocket_clean_domain()`, and W3 Total Cache's purge-log documentation.
 *
 * The consequence is that a REST client cannot purge anything on its own. The
 * `litespeed/v1` namespace `SiteSnapshot` detects proves the plugin is *there*;
 * it does not offer a route Kargah may call, because those routes exist for
 * QUIC.cloud's own integration rather than as a public API.
 *
 * ## So the site is given one small route, or it is told it has none
 *
 * {@see self::purgeSnippet()} is an mu-plugin that registers exactly one
 * endpoint, `kargah/v1/cache/purge`, and dispatches it to whichever plugin is
 * actually installed — falling back to core's `wp_cache_flush()` when none is,
 * which is honest rather than useless: an object cache is still a cache.
 *
 * That is a plugin, and this module has been careful not to need one. The
 * difference is stated rather than glossed: reading and writing content needs
 * nothing installed, because WordPress core exposes it. Purging a cache is not
 * exposed by anybody, so the choice is one small auditable file or no cache
 * control at all. The file is fourteen lines and reads them all.
 *
 * 🔴 **Never exercised against a real install.** Every request shape here is
 * proved with `Http::fake()`. The hook names are documented by their vendors;
 * that the mu-plugin below wires them up correctly is not something a fake can
 * establish, and the first real purge is what will prove it.
 */
class SiteCache
{
    /** The namespace the mu-plugin registers, and the thing Kargah looks for. */
    public const NAMESPACE = 'kargah/v1';

    public const PURGE_ROUTE = 'kargah/v1/cache/purge';

    /**
     * Whether the site has the route that makes purging possible.
     *
     * Read off the snapshot's namespace list rather than by calling the route
     * and seeing what happens, because "seeing what happens" for a purge
     * endpoint means purging the cache to find out whether you can.
     */
    public static function available(SiteSnapshot $snapshot): bool
    {
        return in_array(self::NAMESPACE, $snapshot->namespaces, strict: true);
    }

    /**
     * Purge everything.
     *
     * The response names which plugin acted, so the panel can say "LiteSpeed
     * Cache was purged" rather than "done" — on a site where the owner is not
     * certain what is installed, that sentence is the whole value of the
     * feature.
     *
     * @return array{purged: bool, driver: string, message: string}
     *
     * @throws SiteRequestFailed
     */
    public static function purgeAll(WordPressSite $site): array
    {
        $response = $site->post(self::PURGE_ROUTE, ['scope' => 'all']);

        return [
            'purged' => (bool) ($response['purged'] ?? false),
            'driver' => is_string($response['driver'] ?? null) ? $response['driver'] : 'unknown',
            'message' => is_string($response['message'] ?? null) ? $response['message'] : '',
        ];
    }

    /**
     * Purge one URL.
     *
     * Worth having separately from "everything" because they are different
     * risks. Purging one page after editing it costs the site nothing; purging
     * everything on a large site sends every visitor to an uncached page at
     * once, and on shared hosting that is how an afternoon becomes a 503. The
     * panel offers the narrow one first for that reason.
     *
     * @return array{purged: bool, driver: string, message: string}
     *
     * @throws SiteRequestFailed
     */
    public static function purgeUrl(WordPressSite $site, string $url): array
    {
        $response = $site->post(self::PURGE_ROUTE, ['scope' => 'url', 'url' => $url]);

        return [
            'purged' => (bool) ($response['purged'] ?? false),
            'driver' => is_string($response['driver'] ?? null) ? $response['driver'] : 'unknown',
            'message' => is_string($response['message'] ?? null) ? $response['message'] : '',
        ];
    }

    /**
     * The one route, ready to paste.
     *
     * `permission_callback` is `manage_options` rather than `edit_posts`. The
     * SEO snippet settles for the lower bar because writing a meta description
     * is an editorial act; emptying the page cache of a live site under load is
     * an operational one, and the two do not deserve the same key.
     *
     * `wp_cache_flush()` as the fallback is deliberate and is not a no-op: a
     * site with no page-cache plugin very often still has an object cache, and
     * flushing it is a real thing to have done. The response says which
     * happened so nobody is misled about how much was cleared.
     */
    public static function purgeSnippet(): string
    {
        return <<<'PHP'
        <?php
        /**
         * Plugin Name: Kargah cache control
         * Description: One route so Kargah can purge this site's page cache.
         *
         * Save as wp-content/mu-plugins/kargah-cache.php — mu-plugins, not the
         * theme's functions.php, which a theme update or switch will empty.
         */
        add_action('rest_api_init', function () {
            register_rest_route('kargah/v1', '/cache/purge', [
                'methods' => 'POST',
                'permission_callback' => fn () => current_user_can('manage_options'),
                'callback' => function (WP_REST_Request $request) {
                    $scope = $request->get_param('scope') === 'url' ? 'url' : 'all';
                    $url = (string) $request->get_param('url');

                    if (defined('LSCWP_V')) {
                        $scope === 'url' && $url !== ''
                            ? do_action('litespeed_purge_url', $url)
                            : do_action('litespeed_purge_all');

                        return ['purged' => true, 'driver' => 'LiteSpeed Cache', 'message' => 'Purged.'];
                    }

                    if (function_exists('rocket_clean_domain')) {
                        $scope === 'url' && $url !== '' && function_exists('rocket_clean_files')
                            ? rocket_clean_files([$url])
                            : rocket_clean_domain();

                        return ['purged' => true, 'driver' => 'WP Rocket', 'message' => 'Purged.'];
                    }

                    if (function_exists('w3tc_flush_all')) {
                        w3tc_flush_all();

                        return ['purged' => true, 'driver' => 'W3 Total Cache', 'message' => 'Purged everything.'];
                    }

                    wp_cache_flush();

                    return [
                        'purged' => true,
                        'driver' => 'WordPress object cache',
                        'message' => 'No page-cache plugin was found. The object cache was flushed.',
                    ];
                },
            ]);
        });
        PHP;
    }
}
