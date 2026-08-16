<?php

namespace Modules\Site\Support;

/**
 * The content types this module manages, and what each one is called.
 *
 * ## Two, not every type the site registers
 *
 * WordPress will happily tell you about `attachment`, `nav_menu_item`,
 * `wp_block`, `wp_template` and whatever a plugin has added, and a panel that
 * offered all of them would be a worse wp-admin rather than a better one.
 * Posts and pages are what somebody writes; media has its own page because
 * uploading bytes is a different act from typing; the rest are machinery.
 *
 * A custom post type registered with `show_in_rest` is deliberately not
 * discovered and listed. It is not that it could not be — `GET /wp/v2/types`
 * returns them — it is that its fields, its taxonomies and its idea of a status
 * are all unknown, so the editor drawn for it would be a guess. Adding one is a
 * line in this file plus whatever its own fields need, which is the honest cost.
 *
 * ## The capability names are here rather than derived
 *
 * `edit_posts` and `edit_pages` do not follow from the REST base by any rule
 * WordPress guarantees — they are what core happens to call them, and a type
 * whose capabilities were mapped differently would break a clever derivation
 * silently. Written down, they can be wrong in one visible place.
 */
class PostTypes
{
    public const POST = 'post';

    public const PAGE = 'page';

    /**
     * @return array<string, array{label: string, plural: string, rest: string, capability: string, icon: string}>
     */
    public static function all(): array
    {
        return [
            self::POST => [
                'label' => 'Post',
                'plural' => 'Posts',
                'rest' => 'wp/v2/posts',
                'capability' => 'edit_posts',
                'icon' => 'ki-notepad',
            ],
            self::PAGE => [
                'label' => 'Page',
                'plural' => 'Pages',
                'rest' => 'wp/v2/pages',
                'capability' => 'edit_pages',
                'icon' => 'ki-document',
            ],
        ];
    }

    public static function has(string $type): bool
    {
        return array_key_exists($type, self::all());
    }

    /** The REST base for a type, falling back to posts for anything unknown. */
    public static function rest(string $type): string
    {
        return self::all()[$type]['rest'] ?? self::all()[self::POST]['rest'];
    }

    public static function label(string $type): string
    {
        return self::all()[$type]['label'] ?? ucfirst($type);
    }

    public static function plural(string $type): string
    {
        return self::all()[$type]['plural'] ?? ucfirst($type).'s';
    }

    public static function capability(string $type): string
    {
        return self::all()[$type]['capability'] ?? 'edit_posts';
    }

    /**
     * The statuses a person can put something into from here.
     *
     * `future` is absent, and its absence is the same decision
     * `WordPressPublisher` records: Kargah has its own scheduler, and handing
     * WordPress a publish date as well puts two schedulers in charge of one
     * article. `trash` is absent because trashing is a `DELETE`, not a status
     * somebody picks from a dropdown — offering it here would let the same act
     * happen two ways with two different confirmations.
     *
     * `private` is present because it is genuinely useful and has no other way
     * in: a page that exists at its URL for logged-in editors and 404s for
     * everyone else is how a site stages something.
     *
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            'publish' => 'Published',
            'draft' => 'Draft',
            'pending' => 'Pending review',
            'private' => 'Private',
        ];
    }

    /**
     * Every status a listing can *show*, which is a longer list than the one
     * above: a person needs to find the scheduled post and the trashed one even
     * though neither is a state this panel puts something into.
     *
     * @return array<string, string>
     */
    public static function filterableStatuses(): array
    {
        return self::statuses() + [
            'future' => 'Scheduled',
            'trash' => 'Trashed',
        ];
    }
}
