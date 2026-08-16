<?php

namespace Modules\Site\Services;

use Modules\Site\Support\PostTypes;

/**
 * The SEO fields on a post or page, when the site will let Kargah near them.
 *
 * ## The fact this class is shaped around
 *
 * **Rank Math does not register its post meta with `show_in_rest`.** Its data
 * lives in ordinary post meta — `rank_math_title`, `rank_math_description`,
 * `rank_math_focus_keyword` and the rest — and WordPress refuses to read or
 * write meta over the REST API unless something has whitelisted the key with
 * `register_meta(..., ['show_in_rest' => true])`. Rank Math's editor sidebar
 * does not need that because it is JavaScript running inside wp-admin with
 * nonce-authenticated admin-ajax behind it, so the plugin has never had a
 * reason to expose the keys.
 *
 * Corroborated on 17 August 2026 from Rank Math's own support desk
 * ("Unable to Update Meta Title/Description via REST API"), the WordPress.org
 * support forum thread "change Focus Keyword using REST API", and the existence
 * of `Devora-AS/rank-math-api-manager`, a plugin whose entire purpose is to do
 * this registration. Three independent sources saying the same thing is as
 * close to first-party as this gets without an install to try it on.
 *
 * 🔴 **Not yet exercised against a real Rank Math install.** The request shapes
 * below are proved with `Http::fake()` and nothing else, which is the same
 * standing as the twelve social drivers — see `NEXT-SESSION.md`. What is *not*
 * guessed is the failure path: a site that has not registered the keys silently
 * drops them from `meta` rather than erroring, which is why detection reads the
 * response instead of trusting the request.
 *
 * ## So the panel asks the site rather than assuming either way
 *
 * {@see self::editable()} looks at what came back. If the keys are there, the
 * fields are editable and this is an ordinary meta write. If they are not, the
 * page says exactly that and hands over {@see self::registrationSnippet()} — the
 * few lines that make them appear — rather than showing inputs that would
 * silently discard whatever was typed into them.
 *
 * That last part is the whole point. A save that appears to work and changes
 * nothing is the worst outcome available here, and it is the default one:
 * WordPress accepts the request, returns 200, and drops the unregistered keys
 * without comment.
 */
class SiteSeo
{
    /**
     * The fields this panel edits, and what each one is for.
     *
     * Deliberately six rather than everything Rank Math stores. These are the
     * ones that change what a search engine or a shared link shows, they map
     * one-to-one onto inputs somebody can reason about, and every one of them
     * is a plain string. Rank Math's schema builder, its redirections and its
     * per-post social overrides are all real features and all outside what a
     * meta write can honestly express.
     *
     * @return array<string, array{label: string, hint: string, limit: int|null, rows: int}>
     */
    public static function fields(): array
    {
        return [
            'rank_math_title' => [
                'label' => 'SEO title',
                'hint' => 'What search results show. Left empty, Rank Math falls back to the title template.',
                'limit' => 60,
                'rows' => 1,
            ],
            'rank_math_description' => [
                'label' => 'Meta description',
                'hint' => 'The grey text under the result. Not a ranking factor; it is what decides the click.',
                'limit' => 160,
                'rows' => 3,
            ],
            'rank_math_focus_keyword' => [
                'label' => 'Focus keyword',
                'hint' => 'What this page is meant to rank for. Comma-separated for more than one.',
                'limit' => null,
                'rows' => 1,
            ],
            'rank_math_canonical_url' => [
                'label' => 'Canonical URL',
                'hint' => 'Where the original lives, when this is a copy. Left empty, the page is its own canonical.',
                'limit' => null,
                'rows' => 1,
            ],
            'rank_math_facebook_title' => [
                'label' => 'Social title',
                'hint' => 'The title a shared link shows. Falls back to the SEO title.',
                'limit' => null,
                'rows' => 1,
            ],
            'rank_math_facebook_description' => [
                'label' => 'Social description',
                'hint' => 'The description a shared link shows. Falls back to the meta description.',
                'limit' => null,
                'rows' => 2,
            ],
        ];
    }

    /**
     * Whether the site is actually exposing these keys.
     *
     * Read off the item that came back rather than off the snapshot, because
     * `show_in_rest` is registered per key: a site that has whitelisted the
     * title and not the description is a real state, and one that reported
     * "Rank Math is installed" would be no help at all in it.
     *
     * Presence, not truthiness. An empty string is a field that exists and has
     * not been filled in — which is the state most posts are in, and treating
     * it as "not editable" would hide the editor on exactly the pages that need
     * it most.
     *
     * @param  array<array-key, mixed>  $item
     */
    public static function editable(array $item): bool
    {
        $meta = $item['meta'] ?? null;

        if (! is_array($meta)) {
            return false;
        }

        foreach (array_keys(self::fields()) as $key) {
            if (array_key_exists($key, $meta)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The values as they stand, with every field present.
     *
     * Missing keys come back as empty strings rather than being absent, so the
     * form binds to a complete array and a field the site has not whitelisted
     * draws as empty rather than as undefined.
     *
     * @param  array<array-key, mixed>  $item
     * @return array<string, string>
     */
    public static function read(array $item): array
    {
        $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];

        $values = [];

        foreach (array_keys(self::fields()) as $key) {
            $value = $meta[$key] ?? '';

            // Rank Math stores every one of these as a string, but WordPress
            // returns a registered meta key as an array when it was registered
            // with `single => false`. Taking the first element rather than
            // printing `Array` is the difference between a wrong value and a
            // broken page.
            if (is_array($value)) {
                $value = $value[0] ?? '';
            }

            $values[$key] = is_scalar($value) ? (string) $value : '';
        }

        return $values;
    }

    /**
     * Write the fields back.
     *
     * Only what changed is sent, for the reason `SiteContent::update()` gives:
     * WordPress treats an absent meta key as "leave it alone" and an empty one
     * as "make it empty", so posting the whole bag back would clear a field
     * somebody had set in wp-admin and this panel had merely failed to read.
     *
     * @param  array<string, string>  $values
     * @param  array<string, string>  $original
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public static function write(WordPressSite $site, string $type, int $id, array $values, array $original): array
    {
        $changed = [];

        foreach (array_keys(self::fields()) as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            if (($original[$key] ?? '') !== $values[$key]) {
                $changed[$key] = $values[$key];
            }
        }

        if ($changed === []) {
            return [];
        }

        return $site->post(PostTypes::rest($type).'/'.$id, ['meta' => $changed]);
    }

    /**
     * 🔴 Whether the write actually took.
     *
     * The failure this guards is silent: WordPress answers 200 and drops an
     * unregistered meta key without a word, so a save can look successful and
     * change nothing. Comparing what was asked for against what came back is
     * the only way to tell from outside the site, and it is cheap because the
     * update response already carries the item.
     *
     * @param  array<string, string>  $sent
     * @param  array<array-key, mixed>  $response
     * @return list<string>  the keys the site did not keep
     */
    public static function rejected(array $sent, array $response): array
    {
        $stored = self::read(is_array($response) ? $response : []);

        $rejected = [];

        foreach ($sent as $key => $value) {
            if (($stored[$key] ?? '') !== $value) {
                $rejected[] = $key;
            }
        }

        return $rejected;
    }

    /**
     * The few lines that make the fields writable, ready to paste.
     *
     * An mu-plugin rather than `functions.php`, and the difference matters
     * enough to put in the copy: a theme's `functions.php` is emptied by the
     * next theme update or switch, and the symptom is SEO fields that worked
     * for a month and then quietly stopped saving. `mu-plugins` survives both.
     *
     * `auth_callback` is `edit_posts` rather than `true`. Left open, any
     * authenticated user could write these keys through the REST API — which is
     * precisely the arbitrary-meta-write that `show_in_rest` defaults to off to
     * prevent, and copying a snippet out of a panel is no reason to hand it out.
     */
    public static function registrationSnippet(): string
    {
        $keys = implode("\n", array_map(
            fn (string $key): string => "        '".$key."',",
            array_keys(self::fields()),
        ));

        return <<<PHP
        <?php
        /**
         * Plugin Name: Rank Math fields over REST
         * Description: Lets an application password read and write Rank Math's SEO fields.
         *
         * Save as wp-content/mu-plugins/rank-math-rest.php — mu-plugins, not the
         * theme's functions.php, which a theme update or switch will empty.
         */
        add_action('init', function () {
            \$keys = [
        {$keys}
            ];

            foreach (\$keys as \$key) {
                foreach (['post', 'page'] as \$type) {
                    register_post_meta(\$type, \$key, [
                        'type' => 'string',
                        'single' => true,
                        'show_in_rest' => true,
                        'auth_callback' => fn () => current_user_can('edit_posts'),
                    ]);
                }
            }
        });
        PHP;
    }
}
