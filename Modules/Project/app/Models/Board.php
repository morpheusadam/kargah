<?php

namespace Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Modules\Core\Concerns\Linkable;
use Modules\Core\Models\Company;
use Modules\Data\Contracts\AttachmentService;
use Modules\Project\Database\Factories\BoardFactory;
use Modules\Project\Support\Palette;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Board extends Model
{
    use HasFactory;
    use Linkable;
    use LogsActivity;
    use SoftDeletes;

    /** `background_type` values. A photo carries no `background_key` — see the migration. */
    public const BACKGROUND_COLOUR = 'colour';

    public const BACKGROUND_GRADIENT = 'gradient';

    public const BACKGROUND_PHOTO = 'photo';

    protected $fillable = [
        'slug',
        'name',
        'colour',
        'description',
        'company_id',
        'position',
        'archived_at',
        'created_by',
        'background_type',
        'background_key',
        'background_attachment_id',
        'background_text_tone',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
            'background_attachment_id' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function lists(): HasMany
    {
        return $this->hasMany(BoardList::class)->orderBy('position');
    }

    public function labels(): HasMany
    {
        return $this->hasMany(Label::class)->orderBy('position');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Every placement on every list of this board. A real relation. */
    public function placements(): HasManyThrough
    {
        return $this->hasManyThrough(CardPlacement::class, BoardList::class, 'board_id', 'board_list_id');
    }

    /**
     * The distinct cards this board shows. **This is not a relation.**
     *
     * It cannot be. Boards reach cards over three hops — board, list,
     * placement — and Eloquent's `hasManyThrough` spans two; and a card
     * mirrored onto two lists of the same board must count once, which no
     * relation type deduplicates. So it returns a plain query builder, and the
     * card ids come from a subquery, which is what makes it distinct.
     *
     * The consolation for the missing relation is that `$board->cards` as a
     * *property* throws a `LogicException` from Eloquent rather than silently
     * returning something wrong. Call it, do not read it.
     *
     * @return Builder<Card>
     */
    public function cards(): Builder
    {
        return Card::query()->whereIn(
            'id',
            CardPlacement::query()
                ->select('card_id')
                ->whereIn(
                    'board_list_id',
                    BoardList::query()->select('id')->where('board_id', $this->id),
                ),
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /* Starring, and what you looked at last -----------------------------------
     *
     * Both live on `board_user_states`, one row per (person, board) — see that
     * migration for why they share a table. Everything below writes through an
     * upsert against its unique index rather than reading first: a star is
     * clicked from a list of boards and a view is recorded on every page load,
     * so both have to cost one statement whether the row exists or not.
     *
     * Nothing here is visible to anyone else. A star is not a flag on the
     * board; it is a fact about the person looking at it.
     */

    public function userStates(): HasMany
    {
        return $this->hasMany(BoardUserState::class);
    }

    /**
     * A guest has starred nothing, which is why the argument is nullable —
     * `auth()->user()` can be null on a shared or feed-token view, and that
     * reads as "not starred" rather than as an error.
     */
    public function isStarredBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return BoardUserState::query()
            ->where('user_id', $user->id)
            ->where('board_id', $this->id)
            ->whereNotNull('starred_at')
            ->exists();
    }

    public function starFor(User $user): void
    {
        $this->writeUserState($user, ['starred_at' => now()]);
    }

    /**
     * Unstarring nulls the column; it does not delete the row. The row also
     * holds when this person last opened the board, and that is not the star's
     * to discard.
     */
    public function unstarFor(User $user): void
    {
        $this->writeUserState($user, ['starred_at' => null]);
    }

    /** Returns the state the board is now in, so a caller can render without asking again. */
    public function toggleStarFor(User $user): bool
    {
        $starred = ! $this->isStarredBy($user);

        $starred ? $this->starFor($user) : $this->unstarFor($user);

        return $starred;
    }

    /**
     * Record that this person has just looked at this board.
     *
     * One statement, always. This runs on every board render, so a
     * read-then-write would double the cost of opening a board to find out
     * something the write was going to overwrite anyway.
     */
    public function markViewedBy(User $user): void
    {
        $this->writeUserState($user, ['last_viewed_at' => now()]);
    }

    /**
     * The one write behind all four methods above.
     *
     * The inserted row carries **every** column, nulls included, while the
     * update list carries only the ones the caller named — which is what keeps
     * `starFor()` from wiping a view time and `markViewedBy()` from wiping a
     * star. `DB::table()` rather than the model, matching
     * `BoardListUserState::setCollapsed()`: an upsert wants plain columns, and
     * routing it through Eloquent only invites the timestamp machinery to add
     * an `updated_at` this table does not have.
     *
     * @param  array<string, mixed>  $values
     */
    private function writeUserState(User $user, array $values): void
    {
        DB::table('board_user_states')->upsert(
            [array_merge([
                'user_id' => $user->id,
                'board_id' => $this->id,
                'starred_at' => null,
                'last_viewed_at' => null,
                'created_at' => now(),
            ], $values)],
            ['user_id', 'board_id'],
            array_keys($values),
        );
    }

    /**
     * Boards in the order this person should see them: starred first, then the
     * order everything else already uses.
     *
     * The starred test is a **correlated subquery in the order-by**, not a
     * join. A `leftJoin` would work and would be no slower, but it drags the
     * other table's columns into a `select *` — where `board_user_states.id`
     * arrives after `boards.id` and Eloquent hydrates the later one, so every
     * board silently comes back wearing a state row's primary key. Avoiding
     * that needs a `select('boards.*')` that then quietly overwrites whatever
     * the caller had already selected. A subquery in the order-by touches
     * neither the select list nor the row count, so this scope composes onto
     * any query without the caller having to know it was applied.
     *
     * `coalesce(..., 0)` is load-bearing: a board this person has no row for
     * at all yields NULL, and an unstarred board with a row yields 0. Without
     * the coalesce those two sort into different groups and the position order
     * below them breaks in half.
     *
     * A null user gets the plain order — nothing is starred for nobody.
     */
    public function scopeStarredFirstFor(Builder $query, ?User $user): Builder
    {
        if ($user !== null) {
            $starred = 'coalesce((select case when s.starred_at is null then 0 else 1 end'
                .' from board_user_states as s'
                .' where s.board_id = boards.id and s.user_id = ?), 0)';

            // Selected as well as ordered by, so a caller that has to *draw*
            // the stars — the board picker does — reads them off the models it
            // already has instead of asking a second time. `addSelect` rather
            // than `select`, so `boards.*` survives; and a computed column
            // rather than the join this scope's docblock warns about, which
            // would drag `board_user_states.id` in after `boards.id` and have
            // Eloquent hydrate the wrong one as the key.
            $query
                ->addSelect(['boards.*'])
                ->selectRaw($starred.' as is_starred', [$user->id])
                ->orderByRaw($starred.' desc', [$user->id]);
        }

        return $query->orderBy('position')->orderBy('name');
    }

    /**
     * Whether `starredFirstFor()` marked this row starred.
     *
     * Only meaningful on a model loaded through that scope; anything else has
     * no such column and falls back to false rather than to a query, because a
     * silent per-row lookup is the thing the scope exists to avoid. Use
     * `isStarredBy()` when you have one board and no such collection.
     */
    public function wasLoadedStarred(): bool
    {
        return (bool) ($this->attributes['is_starred'] ?? false);
    }

    /**
     * The boards this person opened most recently, newest first.
     *
     * A plain inner join, because a board with no view time is not a candidate
     * — and `select('boards.*')` because of exactly the column collision the
     * scope above avoids by not joining at all. Archived boards are excluded:
     * "jump back to what you were doing" should not offer somewhere you closed.
     *
     * @return Collection<int, Board>
     */
    public static function recentlyViewedBy(?User $user, int $limit = 5): Collection
    {
        if ($user === null) {
            return new Collection;
        }

        return static::query()
            ->active()
            ->join('board_user_states as s', 's.board_id', '=', 'boards.id')
            ->where('s.user_id', $user->id)
            ->whereNotNull('s.last_viewed_at')
            ->select('boards.*')
            ->orderByDesc('s.last_viewed_at')
            ->limit($limit)
            ->get();
    }

    public function dotClass(): string
    {
        return Palette::dot($this->colour);
    }

    /* Background --------------------------------------------------------------
     *
     * Three kinds, one row. `background_key` is null for a photo and for a
     * fresh board that has not chosen one — both read the same way: nothing to
     * override, so the canvas keeps its default surface.
     */

    /**
     * The whole class string a canvas needs for a colour or gradient
     * background. Empty for a photo (that one is an inline `background-image`,
     * see `backgroundStyle()`) and for a board with nothing chosen yet.
     */
    public function backgroundClass(): string
    {
        if ($this->background_key === null) {
            return '';
        }

        return match ($this->background_type) {
            self::BACKGROUND_GRADIENT => Palette::hasGradient($this->background_key)
                ? Palette::gradientClass($this->background_key)
                : '',
            self::BACKGROUND_COLOUR => Palette::has($this->background_key)
                // The dot's own solid fill, reused rather than duplicated —
                // the same reasoning that keeps card covers on these keys too.
                ? Palette::dot($this->background_key)
                : '',
            default => '',
        };
    }

    /**
     * An inline `background-image` declaration for a photo background, or
     * null for anything else — including a photo type whose attachment has
     * since been deleted, which must read as "no background" rather than a
     * broken image request.
     */
    public function backgroundStyle(): ?string
    {
        $photo = $this->backgroundPhoto();

        if ($photo === null) {
            return null;
        }

        // `inline_url`, never `download_url`: the latter sends
        // `Content-Disposition: attachment`, which asks the browser to save
        // the picture rather than show it — right for a paperclip, wrong for
        // a background. Same bytes, same `auth`, no expiry either way.
        return "background-image:url('".e($photo['inline_url'])."');background-size:cover;background-position:center;";
    }

    /**
     * The stored attachment behind a photo background, resolved through the
     * contract on every read — never through `Modules\Data`'s own model. A
     * miss (the attachment was deleted, or the id was cleared) reads as no
     * photo rather than throwing, which is what keeps a board renderable
     * after its background photo is removed.
     *
     * @return array{download_url: string}|null
     */
    public function backgroundPhoto(): ?array
    {
        if ($this->background_type !== self::BACKGROUND_PHOTO || $this->background_attachment_id === null) {
            return null;
        }

        return app(AttachmentService::class)->find($this->background_attachment_id);
    }

    /** The whole class string the light/dark toggle resolves to. */
    public function textToneClass(): string
    {
        return Palette::textTone($this->background_text_tone);
    }

    /**
     * The list-column surface for the board canvas. Trello's own list columns
     * turn translucent over a photo or a vivid colour or gradient, so a card's
     * text keeps a stable surface to sit on rather than the raw background
     * showing straight through it. A board with nothing chosen yet keeps the
     * canvas's ordinary muted card surface — today's `bg-muted/40`.
     */
    public function canvasSurfaceClass(): string
    {
        if ($this->backgroundClass() === '' && $this->backgroundStyle() === null) {
            return 'bg-muted/40';
        }

        // Light background text means a vivid or dark background under it, so
        // the list surface goes dark-translucent to match; dark text means a
        // pale background, so the surface stays light.
        return $this->background_text_tone === 'light'
            ? 'bg-black/30 backdrop-blur-sm'
            : 'bg-white/80 backdrop-blur-sm';
    }

    /**
     * The tone a freshly chosen background should default to, before a person
     * overrides it. A photo defaults light because a busy image more often
     * wants a light overlay than a dark one; a colour or gradient has its own
     * recommended tone in `Palette`.
     */
    public function defaultTextToneFor(string $type, ?string $key): string
    {
        return match ($type) {
            self::BACKGROUND_GRADIENT => $key !== null ? Palette::gradientTextTone($key) : 'light',
            self::BACKGROUND_COLOUR => $key !== null ? Palette::defaultTextToneForColour($key) : 'light',
            default => 'light',
        };
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'colour', 'company_id', 'archived_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('board');
    }

    protected static function newFactory(): BoardFactory
    {
        return BoardFactory::new();
    }
}
