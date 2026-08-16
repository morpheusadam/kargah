<?php

namespace Modules\Site\Services;

/**
 * Categories and tags on the website.
 *
 * ## Two taxonomies, not every taxonomy the site registers
 *
 * The same argument `PostTypes` makes: WordPress will report `post_format`,
 * `nav_menu`, `wp_theme` and whatever a plugin has added, and a panel offering
 * all of them is a worse wp-admin. Categories and tags are the two a person
 * files their writing under. A custom taxonomy is a line in {@see self::all()}
 * plus whatever its own fields need.
 *
 * ## Deleting a term always forces, and that is WordPress's rule
 *
 * `DELETE /wp/v2/categories/{id}` without `force` is refused with
 * `rest_trash_not_supported` — terms have no trash. So, as with attachments,
 * the only delete available is the permanent one and the copy says so.
 *
 * What it does *not* do is delete the posts filed under it. WordPress detaches
 * them, and a category's posts fall back to the default category rather than
 * disappearing. That is worth stating in the interface, because "delete
 * category" reads to most people like it might take the writing with it.
 *
 * ## Counting is free and is the point
 *
 * Every term comes back with a `count`, which is how the page can lead with the
 * thing worth acting on: the tags used once. A site accumulates them — a typo
 * here, a synonym there — and each one is an archive page with a single post on
 * it that a search engine will happily index as thin content.
 */
class SiteTaxonomy
{
    public const CATEGORY = 'category';

    public const TAG = 'post_tag';

    /**
     * @return array<string, array{label: string, plural: string, rest: string, icon: string, hierarchical: bool}>
     */
    public static function all(): array
    {
        return [
            self::CATEGORY => [
                'label' => 'Category',
                'plural' => 'Categories',
                'rest' => 'wp/v2/categories',
                'icon' => 'ki-folder',
                'hierarchical' => true,
            ],
            self::TAG => [
                'label' => 'Tag',
                'plural' => 'Tags',
                'rest' => 'wp/v2/tags',
                'icon' => 'ki-price-tag',
                'hierarchical' => false,
            ],
        ];
    }

    public static function has(string $taxonomy): bool
    {
        return array_key_exists($taxonomy, self::all());
    }

    public static function rest(string $taxonomy): string
    {
        return self::all()[$taxonomy]['rest'] ?? self::all()[self::CATEGORY]['rest'];
    }

    public static function label(string $taxonomy): string
    {
        return self::all()[$taxonomy]['label'] ?? ucfirst($taxonomy);
    }

    public static function plural(string $taxonomy): string
    {
        return self::all()[$taxonomy]['plural'] ?? ucfirst($taxonomy).'s';
    }

    public function __construct(private readonly WordPressSite $site) {}

    /**
     * One page of terms, most used first.
     *
     * `orderby=count` rather than by name, because a list of two hundred tags
     * sorted alphabetically tells you nothing and a list sorted by use tells
     * you what the site is actually about — and, at the far end, which terms
     * exist only because somebody mistyped one once.
     *
     * `hide_empty` is deliberately not sent. An unused term is exactly what
     * this page is for finding.
     *
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<array-key, mixed>>, total: int, pages: int}
     *
     * @throws SiteRequestFailed
     */
    public function list(string $taxonomy, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $query = array_filter([
            'context' => 'edit',
            'search' => $filters['search'] ?? null,
            'page' => $page,
            'per_page' => $perPage,
            'orderby' => 'count',
            'order' => 'desc',
        ], fn ($value): bool => $value !== null && $value !== '');

        $result = $this->site->paginate(self::rest($taxonomy), $query);

        /** @var list<array<array-key, mixed>> $items */
        $items = array_values(array_filter($result['items'], 'is_array'));

        return ['items' => $items, 'total' => $result['total'], 'pages' => $result['pages']];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function create(string $taxonomy, array $attributes): array
    {
        return $this->site->post(self::rest($taxonomy), $attributes);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function update(string $taxonomy, int $id, array $changes): array
    {
        return $this->site->post(self::rest($taxonomy).'/'.$id, $changes);
    }

    /**
     * Destroy a term. The posts filed under it survive.
     *
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function delete(string $taxonomy, int $id): array
    {
        return $this->site->delete(self::rest($taxonomy).'/'.$id, force: true);
    }

    /**
     * Terms nothing is filed under, and terms used exactly once.
     *
     * Both are worth surfacing and they are different problems. A term with no
     * posts is dead weight. A term with one post is an archive page carrying a
     * single item, which is the shape a search engine calls thin content and
     * which a site accumulates without anybody deciding to.
     *
     * @param  list<array<array-key, mixed>>  $items
     * @return array{unused: int, usedOnce: int}
     */
    public static function thin(array $items): array
    {
        $unused = 0;
        $usedOnce = 0;

        foreach ($items as $item) {
            $count = (int) ($item['count'] ?? 0);

            if ($count === 0) {
                $unused++;
            } elseif ($count === 1) {
                $usedOnce++;
            }
        }

        return ['unused' => $unused, 'usedOnce' => $usedOnce];
    }
}
