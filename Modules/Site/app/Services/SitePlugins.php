<?php

namespace Modules\Site\Services;

/**
 * The plugins installed on the website.
 *
 * ## Activating and deactivating, and nothing else
 *
 * `wp/v2/plugins` has shipped in core since WordPress 5.5 and will install from
 * the wordpress.org directory, update, and delete. This offers two of those and
 * refuses the rest, and the line is drawn where the consequences stop being
 * reversible from here:
 *
 * - **Installing** downloads and runs somebody else's code on the site, chosen
 *   by a slug typed into a box. A typo installs the wrong plugin; a compromised
 *   package installs a backdoor. That decision belongs on a screen showing the
 *   author, the install count, the last-updated date and the reviews — which is
 *   wp-admin's plugin browser, and reproducing it here would be a worse copy of
 *   a good screen.
 * - **Deleting** removes the files, and for most plugins takes their data with
 *   them. It is not undoable and there is no daily task that needs it.
 * - **Updating** is genuinely useful and genuinely dangerous: an update can
 *   white-screen a site, and the safe version of this feature needs a staging
 *   copy or at minimum a backup taken first. Kargah has backups
 *   (`Modules\Data`) but not of somebody else's WordPress install.
 *
 * Deactivating, by contrast, is the first thing anybody does when a site starts
 * misbehaving, and it is completely reversible. That is the verb worth having
 * away from wp-admin — particularly when the thing that broke is what makes
 * wp-admin slow to load.
 *
 * ## 🔴 It is possible to lock yourself out with this
 *
 * Deactivating a security or login plugin can change how the site
 * authenticates. Worse, on a site where an application password is the only way
 * Kargah gets in, deactivating something that provides REST authentication ends
 * this connection. Neither is predictable from a plugin's name, so the page
 * warns on the category it can detect and otherwise trusts the reader — a panel
 * that refused every plugin it could not vouch for would refuse all of them.
 */
class SitePlugins
{
    public const REST = 'wp/v2/plugins';

    /**
     * Fragments that suggest a plugin is load-bearing for getting in.
     *
     * Deliberately a short list of substrings rather than a curated registry of
     * plugin slugs. A registry goes stale the moment somebody installs a plugin
     * nobody thought of, and the failure mode of a missed match is the warning
     * not appearing — so the list errs toward matching broadly, and the warning
     * is worded as "check this" rather than as a refusal.
     *
     * @return list<string>
     */
    public static function riskyFragments(): array
    {
        return ['security', 'login', 'auth', 'firewall', 'wordfence', 'jetpack', 'rest', 'captcha', 'limit-login'];
    }

    /**
     * Whether deactivating this one could plausibly cut Kargah off or lock
     * somebody out.
     *
     * @param  array<array-key, mixed>  $plugin
     */
    public static function isRisky(array $plugin): bool
    {
        $haystack = strtolower(
            (string) ($plugin['plugin'] ?? '').' '.(string) ($plugin['name'] ?? ''),
        );

        foreach (self::riskyFragments() as $fragment) {
            if (str_contains($haystack, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public function __construct(private readonly WordPressSite $site) {}

    /**
     * Everything installed, active first.
     *
     * The endpoint does not paginate — WordPress returns every plugin in one
     * array — so there is no page to turn and no `X-WP-Total` to read. Sorting
     * happens here rather than being asked for, because the endpoint has no
     * `orderby` either.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws SiteRequestFailed
     */
    public function list(): array
    {
        $plugins = $this->site->get(self::REST);

        /** @var list<array<array-key, mixed>> $items */
        $items = array_values(array_filter($plugins, 'is_array'));

        usort($items, function (array $a, array $b): int {
            $activity = ((($b['status'] ?? '') === 'active') <=> (($a['status'] ?? '') === 'active'));

            return $activity !== 0
                ? $activity
                : strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        return $items;
    }

    /**
     * Turn one on or off.
     *
     * The identifier is WordPress's `plugin` field — `akismet/akismet`, the
     * file path without `.php` — and not the name or the slug. It contains a
     * slash, which has to survive into the URL rather than being treated as a
     * path segment, so it is encoded.
     *
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function setStatus(string $plugin, bool $active): array
    {
        return $this->site->post(
            self::REST.'/'.rawurlencode($plugin),
            ['status' => $active ? 'active' : 'inactive'],
        );
    }

    /**
     * How many are switched on.
     *
     * @param  list<array<array-key, mixed>>  $items
     */
    public static function activeCount(array $items): int
    {
        $active = 0;

        foreach ($items as $item) {
            if (($item['status'] ?? '') === 'active') {
                $active++;
            }
        }

        return $active;
    }
}
