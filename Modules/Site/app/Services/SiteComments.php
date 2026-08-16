<?php

namespace Modules\Site\Services;

/**
 * Moderating the website's comments.
 *
 * ## The queue is the feature, the list is not
 *
 * A comment browser is something wp-admin does adequately. What makes this
 * worth a page is that moderation is the one job on a website that is
 * time-sensitive and repetitive: a held comment is invisible to its author
 * until somebody approves it, and spam left standing is what turns a comment
 * section into a liability. So the page opens on `hold` rather than on
 * everything, and the verbs are one click each.
 *
 * ## Spam and trash are different acts and both are kept
 *
 * WordPress distinguishes them and so does this. `spam` teaches the site's
 * filter and is what genuine spam deserves; `trash` is for a real comment that
 * should not be there. Collapsing the two into one "remove" button would make
 * the filter worse over time, quietly, in a way nobody would ever trace back
 * to the panel.
 *
 * ## Deleting is not offered
 *
 * `force` is deliberately absent from this class. Trash is reversible in
 * wp-admin and permanent deletion is not, and there is no daily moderation task
 * that needs the irreversible one. Somebody who genuinely wants a comment gone
 * forever can empty the trash on the site, having thought about it twice.
 */
class SiteComments
{
    public const REST = 'wp/v2/comments';

    /**
     * The statuses WordPress uses, and what each one means here.
     *
     * `approved` is spelled that way on the way out and `approve` on the way
     * in — WordPress is not consistent about it and the mismatch is a 400 that
     * reads like a permissions problem. Both spellings live here so that no
     * caller has to remember which is which.
     *
     * @return array<string, string>
     */
    public static function statuses(): array
    {
        return [
            'hold' => 'Waiting',
            'approve' => 'Approved',
            'spam' => 'Spam',
            'trash' => 'Trashed',
        ];
    }

    /**
     * What a listing may be filtered by.
     *
     * `all` is not a WordPress value — it is this class's way of sending no
     * status filter at all, which is what returns everything the user may see.
     *
     * @return array<string, string>
     */
    public static function filters(): array
    {
        return ['all' => 'Everything'] + self::statuses();
    }

    /**
     * The status WordPress reports for an approved comment.
     *
     * It answers `approved` and accepts `approve`. Normalising on read is what
     * keeps a badge from falling through to "unknown" for the most common
     * status on the site.
     */
    public static function normalise(string $status): string
    {
        return $status === 'approved' ? 'approve' : $status;
    }

    public function __construct(private readonly WordPressSite $site) {}

    /**
     * One page of comments, newest first, waiting ones by default.
     *
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<array-key, mixed>>, total: int, pages: int}
     *
     * @throws SiteRequestFailed
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $status = (string) ($filters['status'] ?? 'hold');

        $query = array_filter([
            'context' => 'edit',
            // `all` means "send no filter", which is not the same as sending
            // the word `all` — WordPress would answer 400.
            'status' => $status === 'all' ? null : $status,
            'search' => $filters['search'] ?? null,
            'page' => $page,
            'per_page' => $perPage,
            'orderby' => 'date_gmt',
            'order' => 'desc',
        ], fn ($value): bool => $value !== null && $value !== '');

        $result = $this->site->paginate(self::REST, $query);

        /** @var list<array<array-key, mixed>> $items */
        $items = array_values(array_filter($result['items'], 'is_array'));

        return ['items' => $items, 'total' => $result['total'], 'pages' => $result['pages']];
    }

    /**
     * Move a comment to a status.
     *
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function setStatus(int $id, string $status): array
    {
        return $this->site->post(self::REST.'/'.$id, ['status' => $status]);
    }

    /**
     * How many of these are still waiting on somebody.
     *
     * Counted over the page in hand, like `SiteMedia::missingAltText()`, and
     * the interface says "on this page" rather than implying a site-wide total.
     *
     * @param  list<array<array-key, mixed>>  $items
     */
    public static function waiting(array $items): int
    {
        $waiting = 0;

        foreach ($items as $item) {
            if (self::normalise((string) ($item['status'] ?? '')) === 'hold') {
                $waiting++;
            }
        }

        return $waiting;
    }

    /**
     * The comment's text, flattened to something a table cell can hold.
     *
     * WordPress returns `content` as a `{raw, rendered}` object and the rendered
     * half is HTML. Neither is printed as markup here: a comment is untrusted
     * text written by a stranger, and a moderation queue that rendered it would
     * be executing the exact thing it exists to let somebody reject. Tags are
     * stripped and the result is escaped by Blade as ordinary text.
     *
     * @param  array<array-key, mixed>  $comment
     */
    public static function excerpt(array $comment, int $limit = 220): string
    {
        $text = trim(strip_tags(SiteContent::text($comment['content'] ?? '')));

        // `html_entity_decode` after stripping, not before: an `&lt;script&gt;`
        // in the source would otherwise become a real tag for `strip_tags` to
        // find, which is the wrong order and the classic way to reintroduce
        // exactly what the stripping was for.
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(strip_tags($text));

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'…' : $text;
    }
}
