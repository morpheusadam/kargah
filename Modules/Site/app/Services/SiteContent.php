<?php

namespace Modules\Site\Services;

use Modules\Site\Support\PostTypes;

/**
 * Reading and writing the things on the site that somebody wrote.
 *
 * A thin layer over {@see WordPressSite} rather than a repository with models
 * behind it, and that is the whole design decision worth defending: **nothing
 * here is mirrored into Kargah's database.**
 *
 * The alternative was tempting — a `site_posts` table, synced by cron, fast
 * lists, offline search. It was rejected because the site is not Kargah's data.
 * Somebody edits a page in wp-admin, a plugin rewrites a permalink, a scheduled
 * post goes live: every one of those makes a mirror wrong, and a panel that
 * confidently shows a stale copy of somebody's live website is worse than one
 * that is honestly slower. `Modules\Mailbox` mirrors IMAP for the opposite
 * reason and it is worth naming the difference: mail is an append-only stream
 * that nobody else edits, and a website is a mutable document with other
 * editors.
 *
 * The cost is real and paid deliberately: every list is a round trip, and there
 * is no cross-site search. What is cached is `SiteSnapshot` — the shape of the
 * install — because that changes when a plugin is activated rather than when a
 * paragraph is typed.
 *
 * ## `context=edit` on every read
 *
 * Without it WordPress returns the *rendered* title and content — HTML with
 * shortcodes expanded, filters applied, `<p>` tags inserted. Putting that in an
 * editor and saving it back is how a post slowly fills with the residue of its
 * own rendering. `context=edit` returns `raw`, which is what is actually stored,
 * and it is what every write here sends back.
 */
class SiteContent
{
    public function __construct(private readonly WordPressSite $site) {}

    /**
     * One page of a content type.
     *
     * `status` defaults to `any` rather than to `publish`, because a panel whose
     * list silently hid every draft would be a panel somebody loses work in.
     * `any` is WordPress's own keyword for "every status this user may see",
     * which is not the same as every status that exists — a contributor gets
     * their own drafts and not somebody else's, decided by the site rather than
     * here.
     *
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<array-key, mixed>>, total: int, pages: int}
     *
     * @throws SiteRequestFailed
     */
    public function list(string $type, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $query = array_filter([
            'context' => 'edit',
            'status' => $filters['status'] ?? 'any',
            'search' => $filters['search'] ?? null,
            'page' => $page,
            'per_page' => $perPage,
            'orderby' => 'modified',
            'order' => 'desc',
        ], fn ($value): bool => $value !== null && $value !== '');

        $result = $this->site->paginate(PostTypes::rest($type), $query);

        /** @var list<array<array-key, mixed>> $items */
        $items = array_values(array_filter($result['items'], 'is_array'));

        return ['items' => $items, 'total' => $result['total'], 'pages' => $result['pages']];
    }

    /**
     * One item, as it is stored rather than as it renders.
     *
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function find(string $type, int $id): array
    {
        return $this->site->get(PostTypes::rest($type).'/'.$id, ['context' => 'edit']);
    }

    /**
     * Write changes back.
     *
     * Only the keys handed in are sent. WordPress treats an absent field as
     * "leave it alone" and an empty one as "make it empty", so a save that
     * helpfully posted the whole item back would overwrite every field this
     * panel does not draw — a featured image, a template, a custom field — with
     * whatever the read happened to return. Sending the diff is the difference
     * between an editor and a wrecking ball.
     *
     * @param  array<string, mixed>  $changes
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function update(string $type, int $id, array $changes): array
    {
        if ($changes === []) {
            return $this->find($type, $id);
        }

        return $this->site->post(PostTypes::rest($type).'/'.$id, $changes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function create(string $type, array $attributes): array
    {
        return $this->site->post(PostTypes::rest($type), $attributes);
    }

    /**
     * Move to the trash, where it can be restored.
     *
     * `force` is deliberately never passed for a post or a page. WordPress's own
     * trash is a better undo than anything Kargah could build, it is where the
     * owner would look for the thing they deleted, and a panel that permanently
     * destroyed a page on one click would be a panel nobody trusts with their
     * site. Emptying the trash is a wp-admin job and stays one.
     *
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function trash(string $type, int $id): array
    {
        return $this->site->delete(PostTypes::rest($type).'/'.$id);
    }

    /**
     * Pull something back out of the trash.
     *
     * Restored to `draft` rather than to whatever it was before. WordPress does
     * not tell the REST API what the previous status was — `wp_untrash_post`
     * reads a meta key the API does not expose — so the choice is between a
     * guess and a safe answer. Draft is the safe answer: nothing silently
     * reappears on the live site because somebody clicked restore to look at it.
     *
     * @return array<array-key, mixed>
     *
     * @throws SiteRequestFailed
     */
    public function restore(string $type, int $id): array
    {
        return $this->site->post(PostTypes::rest($type).'/'.$id, ['status' => 'draft']);
    }

    /**
     * The plain-text value out of one of WordPress's `{raw, rendered}` fields.
     *
     * Every title, content and excerpt comes back as an object under
     * `context=edit` and as a bare string under some plugin's filter, so both
     * shapes are handled rather than assumed. `raw` is preferred over
     * `rendered` for the reason in the class docblock.
     */
    public static function text(mixed $field): string
    {
        if (is_string($field)) {
            return $field;
        }

        if (! is_array($field)) {
            return '';
        }

        foreach (['raw', 'rendered'] as $key) {
            if (is_string($field[$key] ?? null)) {
                return $field[$key];
            }
        }

        return '';
    }
}
