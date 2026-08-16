<?php

namespace Modules\Site\Services;

/**
 * The website's media library.
 *
 * ## Uploads go straight through, they are not staged in Kargah
 *
 * A file picked here is streamed to `wp/v2/media` and never written to Kargah's
 * own disk. The alternative — store it, queue a job, upload it later — buys
 * retry-on-failure and costs two copies of every image, a second place for the
 * owner's files to leak from, and a window in which Kargah believes the library
 * contains something the site has never seen. `Modules\Data` is where files
 * belonging to Kargah live; a picture destined for somebody's website is not
 * one of those.
 *
 * ## Deleting is permanent here, and that is WordPress's decision
 *
 * Posts and pages are trashed rather than deleted, because WordPress has a
 * trash for them and it is a better undo than anything this panel could build.
 * Attachments have none: `DELETE /wp/v2/media/{id}` without `force` is refused
 * outright with `rest_trash_not_supported`. So the only delete available is the
 * permanent one, and the page says so in those words rather than offering a
 * button that reads like the one next to it and does something worse.
 *
 * ## Why the library is filtered by mime type and not by "images only"
 *
 * A media library holds PDFs somebody links from a page and a font the theme
 * loads, and hiding them would make this a picture browser rather than the
 * library. The filter is offered instead, defaulting to everything.
 */
class SiteMedia
{
    public const REST = 'wp/v2/media';

    public function __construct(private readonly WordPressSite $site) {}

    /**
     * One page of the library, newest first.
     *
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<array-key, mixed>>, total: int, pages: int}
     *
     * @throws SiteRequestFailed
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 24): array
    {
        $query = array_filter([
            'context' => 'edit',
            'search' => $filters['search'] ?? null,
            // WordPress calls the top-level group `media_type` and takes only
            // `image`, `video`, `audio`, `application`, `file`. Anything else is
            // a 400, so an unknown value is dropped rather than forwarded.
            'media_type' => in_array($filters['media_type'] ?? '', ['image', 'video', 'audio', 'application'], true)
                ? $filters['media_type']
                : null,
            'page' => $page,
            'per_page' => $perPage,
            'orderby' => 'date',
            'order' => 'desc',
        ], fn ($value): bool => $value !== null && $value !== '');

        $result = $this->site->paginate(self::REST, $query);

        /** @var list<array<array-key, mixed>> $items */
        $items = array_values(array_filter($result['items'], 'is_array'));

        return ['items' => $items, 'total' => $result['total'], 'pages' => $result['pages']];
    }

    /**
     * Put bytes in the library.
     *
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function upload(string $filename, string $contents, string $mime): array
    {
        return $this->site->upload($filename, $contents, $mime);
    }

    /**
     * Change what an attachment says about itself.
     *
     * Alt text is the field that matters and the reason this method exists.
     * It is the one piece of a media library that is simultaneously an
     * accessibility requirement, an SEO signal and the thing nobody fills in —
     * so the panel puts it one click from the picture rather than three screens
     * away, and {@see self::missingAltText()} counts what is left.
     *
     * @param  array<string, mixed>  $changes
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function update(int $id, array $changes): array
    {
        if ($changes === []) {
            return $this->site->get(self::REST.'/'.$id, ['context' => 'edit']);
        }

        return $this->site->post(self::REST.'/'.$id, $changes);
    }

    /**
     * Destroy an attachment.
     *
     * `force` is not optional and not a parameter. WordPress refuses a trash on
     * an attachment — `rest_trash_not_supported` — so a delete here is always
     * permanent, and making that a flag somebody could pass as false would only
     * create a call that always fails.
     *
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function delete(int $id): array
    {
        return $this->site->delete(self::REST.'/'.$id, force: true);
    }

    /**
     * How many of these have no alternative text.
     *
     * Counted over the page in hand rather than by asking the site for a total,
     * because WordPress has no query for "attachments whose `_wp_attachment_image_alt`
     * is empty" and inventing one would mean walking the whole library on every
     * page load. A count of what is on screen is honest about its own scope, and
     * the page says "on this page" rather than implying a site-wide figure.
     *
     * @param  list<array<array-key, mixed>>  $items
     */
    public static function missingAltText(array $items): int
    {
        $missing = 0;

        foreach ($items as $item) {
            if (($item['media_type'] ?? '') !== 'image') {
                continue;
            }

            if (trim((string) ($item['alt_text'] ?? '')) === '') {
                $missing++;
            }
        }

        return $missing;
    }

    /**
     * The thumbnail to draw, preferring a small one.
     *
     * WordPress nests every generated size under `media_details.sizes`. Falling
     * back through thumbnail → medium → the full-size original matters on a
     * library page: without it a grid of twenty-four photographs downloads
     * twenty-four full-resolution originals, which on somebody's phone is tens
     * of megabytes to draw a contact sheet.
     *
     * @param  array<array-key, mixed>  $item
     */
    public static function thumbnail(array $item): ?string
    {
        $sizes = $item['media_details']['sizes'] ?? [];

        if (is_array($sizes)) {
            foreach (['thumbnail', 'medium'] as $size) {
                $url = $sizes[$size]['source_url'] ?? null;

                if (is_string($url) && $url !== '') {
                    return $url;
                }
            }
        }

        $source = $item['source_url'] ?? null;

        return is_string($source) && $source !== '' ? $source : null;
    }
}
