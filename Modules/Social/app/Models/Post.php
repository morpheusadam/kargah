<?php

namespace Modules\Social\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Models\Company;
use Modules\Social\Database\Factories\PostFactory;

/**
 * One thought, on its way to one or more networks.
 *
 * **The status here is a summary, not the truth.** The truth is on
 * `post_targets`, one row per place the post is going, because a post that
 * reaches Mastodon and is refused by LinkedIn is neither published nor failed.
 * `PostPublisher` recomputes this column from the targets after every attempt;
 * nothing else should write it, and nothing should decide whether to send by
 * reading it.
 *
 * The one thing this column *is* load bearing for is the scheduler: `due()`
 * asks for posts whose `scheduled_for` has passed and which are still waiting,
 * and `status` is the cheap half of the index that answers it.
 *
 * ---
 *
 * ## `posts.media` is dead. Do not read it and do not write it.
 *
 * The column exists in the table and is deliberately absent from `$fillable`
 * and from `casts()`, which is the whole of the decision: it cannot be filled
 * by accident, it does not appear on a model instance as an array waiting to be
 * used, and anything that wants it has to go out of its way — at which point
 * this paragraph is the thing it finds.
 *
 * **What replaced it.** A post's images are attachment rows, reached through
 * `Modules\Data\Contracts\AttachmentService` with this post as the target and
 * `Modules\Social\Services\PostMedia` as the one place in Social that asks.
 * That is where the composer writes them, where the publisher reads them, and
 * where the post page lists them.
 *
 * **Why not both.** A JSON column and an attachment table cannot disagree until
 * the day they do: delete a file from Data's Files page and the row is gone
 * while the JSON still names it, and a publisher reading the JSON would then
 * send a post referring to a picture that no longer exists — or worse, quietly
 * send four images where the person can see three. "The database is the source
 * of truth, not the UI" cuts both ways; two databases of the same fact is not a
 * source of truth, it is a race.
 *
 * **Why it was not dropped.** Because dropping it on SQLite would risk the
 * table. SQLite has no `DROP COLUMN` that Laravel can rely on across supported
 * versions, so the schema builder rebuilds `posts` — and `post_targets.post_id`
 * references `posts.id` with `ON DELETE CASCADE`, which a rebuild fires. Inside
 * a migration transaction `PRAGMA foreign_keys` is a no-op, so the guard that
 * would have stopped it is not running. The realistic outcome of tidying this
 * column away is every `post_targets` row in the install silently deleted:
 * every delivery record, every remote id, every published status the retry
 * design depends on being forward-only. A nullable column nobody can fill is a
 * far cheaper wrong than that, and this note is what makes it safe.
 */
class Post extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    public const PUBLISHING = 'publishing';

    public const PUBLISHED = 'published';

    public const PARTLY_FAILED = 'partly_failed';

    public const FAILED = 'failed';

    // `media` is absent on purpose and is not an oversight — see the class
    // docblock. Images live on attachment rows.
    protected $fillable = [
        'body',
        'status',
        'scheduled_for',
        'published_at',
        'company_id',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Posts the scheduler should hand to a job now.
     *
     * Two conditions, not one. A post sitting on `scheduled` past its time is
     * the ordinary case. A post sitting on `publishing` long past its time is a
     * worker that was killed between claiming the post and finishing it — the
     * targets it never reached would otherwise wait forever, so the stale
     * window lets a later tick pick the post up again. Re-dispatching is safe
     * either way: the targets that succeeded are already `published`, and
     * `PostTarget::scopeClaimable()` will not hand them back.
     */
    public function scopeDue(Builder $query, ?int $staleMinutes = null): Builder
    {
        $stale = now()->subMinutes($staleMinutes ?? (int) config('social.stale_claim_minutes', 15));

        return $query
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now())
            ->where(fn (Builder $q) => $q
                ->where('status', self::SCHEDULED)
                ->orWhere(fn (Builder $stalled) => $stalled
                    ->where('status', self::PUBLISHING)
                    ->where('updated_at', '<', $stale)))
            ->orderBy('scheduled_for');
    }

    public function scopeQueued(Builder $query): Builder
    {
        return $query->whereIn('status', [self::SCHEDULED, self::PUBLISHING]);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('status', [self::FAILED, self::PARTLY_FAILED]);
    }

    /** The first line or so, for a list that has no room for the whole thing. */
    public function excerpt(int $characters = 160): string
    {
        $body = trim(preg_replace('/\s+/u', ' ', $this->body) ?? '');

        return mb_strlen($body) > $characters
            ? mb_substr($body, 0, $characters - 1).'…'
            : $body;
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /** Whether anything is still outstanding, which is what the retry button asks. */
    public function hasOutstandingTargets(): bool
    {
        return $this->targets->contains(fn (PostTarget $target): bool => ! $target->isPublished());
    }

    protected static function newFactory(): PostFactory
    {
        return PostFactory::new();
    }
}
