<?php

namespace Modules\Site\Services;

/**
 * The website's own settings, the ones WordPress exposes over REST.
 *
 * ## Core exposes eighteen and this offers nine
 *
 * `GET /wp/v2/settings` returns everything core registered with
 * `show_in_rest`, which includes several nobody should change from a panel —
 * `default_ping_status`, `use_smilies`, `start_of_week` — and several whose
 * consequences are severe and invisible from here. Two are refused outright:
 *
 * - **`page_on_front` and `show_on_front`** decide what the home page is. Set
 *   wrongly they blank the front of the site, and the value that makes them
 *   safe is a page id this panel would have to look up and validate. That is a
 *   real feature; it is not a text input.
 * - **`permalink_structure` is not in core's REST settings at all.** WordPress
 *   deliberately never exposed it, because changing it invalidates every URL on
 *   the site at once and the redirects that would save you are a plugin's job.
 *   Nothing here can offer it, and pretending otherwise would be the single
 *   most destructive control this module could ship.
 *
 * ## Everything is sent as a diff
 *
 * `POST /wp/v2/settings` with one key changes one setting. Sending the whole
 * bag back would rewrite settings this panel does not draw with whatever the
 * read returned, which for a site whose plugins have registered their own
 * settings is a way to undo somebody's configuration silently.
 */
class SiteSettings
{
    public const REST = 'wp/v2/settings';

    /**
     * The settings this panel offers, in the order they are drawn.
     *
     * `type` is what the form draws, not what WordPress stores — `posts_per_page`
     * is an integer to WordPress and a number input here, and the cast happens
     * on the way out so a browser's string never reaches the API.
     *
     * @return array<string, array{label: string, hint: string, type: string}>
     */
    public static function fields(): array
    {
        return [
            'title' => [
                'label' => 'Site title',
                'hint' => 'Shown in the browser tab, in search results and wherever the theme prints it.',
                'type' => 'text',
            ],
            'description' => [
                'label' => 'Tagline',
                'hint' => 'One line under the title. WordPress ships “Just another WordPress site” and most sites never change it.',
                'type' => 'text',
            ],
            'email' => [
                'label' => 'Admin email',
                'hint' => 'Where WordPress sends its own notices. Changing it sends a confirmation to the new address before it takes effect.',
                'type' => 'email',
            ],
            'timezone' => [
                'label' => 'Timezone',
                'hint' => 'A name like Europe/Istanbul, not an offset — an offset does not know about daylight saving and a scheduled post will drift by an hour twice a year.',
                'type' => 'text',
            ],
            'date_format' => [
                'label' => 'Date format',
                'hint' => 'PHP date characters, for example F j, Y.',
                'type' => 'text',
            ],
            'time_format' => [
                'label' => 'Time format',
                'hint' => 'PHP date characters, for example H:i.',
                'type' => 'text',
            ],
            'posts_per_page' => [
                'label' => 'Posts per page',
                'hint' => 'How many appear on an archive before it paginates.',
                'type' => 'number',
            ],
            'default_comment_status' => [
                'label' => 'Comments on new posts',
                'hint' => 'open or closed. Only affects posts written after the change.',
                'type' => 'text',
            ],
            'language' => [
                'label' => 'Site language',
                'hint' => 'A WordPress locale such as en_US or fa_IR. The language pack has to be installed on the site already.',
                'type' => 'text',
            ],
        ];
    }

    public function __construct(private readonly WordPressSite $site) {}

    /**
     * Every setting the site will show us, with the panel's own fields present
     * even when WordPress omitted one.
     *
     * @return array<string, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function read(): array
    {
        $settings = $this->site->get(self::REST);

        $values = [];

        foreach (array_keys(self::fields()) as $key) {
            $value = $settings[$key] ?? '';

            $values[$key] = is_scalar($value) ? (string) $value : '';
        }

        return $values;
    }

    /**
     * Write back only what differs.
     *
     * @param  array<string, string>  $values
     * @param  array<string, string>  $original
     * @return array{changed: array<string, mixed>, response: array<array-key, mixed>}
     *
     * @throws SiteRequestFailed
     */
    public function write(array $values, array $original): array
    {
        $changes = [];

        foreach (self::fields() as $key => $field) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            if (($original[$key] ?? '') === $values[$key]) {
                continue;
            }

            // A number input hands back a string. WordPress registered
            // `posts_per_page` as an integer and answers 400 for "10".
            $changes[$key] = $field['type'] === 'number' ? (int) $values[$key] : $values[$key];
        }

        if ($changes === []) {
            return ['changed' => [], 'response' => []];
        }

        // The changes are handed back keyed rather than as a list of names, so
        // `notApplied()` can compare values rather than only know which were
        // touched.
        return [
            'changed' => $changes,
            'response' => $this->site->post(self::REST, $changes),
        ];
    }

    /**
     * 🔴 Settings the site did not keep.
     *
     * The same guard `SiteSeo::rejected()` provides and for a related reason:
     * WordPress answers 200 for a settings write whose value it then refuses or
     * transforms. `email` is the honest case rather than a bug — changing the
     * admin address sends a confirmation link and leaves the old value in place
     * until somebody clicks it, so the response legitimately disagrees with what
     * was sent, and the panel has to say so rather than claim a change that has
     * not happened yet.
     *
     * Compares by string value on purpose. `posts_per_page` goes out as an int
     * and comes back as an int, `title` goes out and comes back as a string,
     * and a strict comparison across the two would report every numeric setting
     * as pending on a site that stored it perfectly.
     *
     * @param  array<string, mixed>  $sent  the changes as they were sent, keyed
     * @param  array<array-key, mixed>  $response
     * @return list<string>
     */
    public static function notApplied(array $sent, array $response): array
    {
        $pending = [];

        foreach ($sent as $key => $value) {
            // A key the response omits says nothing either way — WordPress
            // returns the whole settings object, so this only happens for a
            // setting a plugin has since unregistered.
            if (! array_key_exists($key, $response)) {
                continue;
            }

            $stored = $response[$key];

            if (! is_scalar($stored)) {
                continue;
            }

            if ((string) $stored !== (string) $value) {
                $pending[] = $key;
            }
        }

        return $pending;
    }
}
