<?php

namespace Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Concerns\Linkable;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;
use Modules\Data\Contracts\AttachmentService;
use Modules\Project\Database\Factories\CardFactory;
use Modules\Project\Support\Palette;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Card extends Model
{
    use HasFactory;
    use Linkable;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'customer_id',
        'company_id',
        'start_on',
        'due_on',
        'completed_at',
        'archived_at',
        'created_by',
        'cover_type',
        'cover_colour',
        'cover_attachment_id',
        'cover_size',
    ];

    protected function casts(): array
    {
        return [
            'start_on' => 'date',
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Assigns the per-board card number the moment a card gets its origin
     * placement — the earliest point a card's board is actually known, since
     * `cards` itself carries no board reference (see the card-placements
     * decision in DECISIONS.md). Listening on `CardPlacement`'s own event
     * rather than on `Card::creating` is what a normal Observer would do from
     * `CardPlacement::boot()`, but that file is owned by another agent's work
     * in flight; registering the listener here, from the model this task
     * owns, reaches the same moment without touching it.
     *
     * `CardService::append()` always creates the `Card` row before the
     * `CardPlacement` row, in the same transaction, so `Card::booted()` has
     * always run — and this listener is always registered — before the first
     * placement of a request is written. `CardFactory` follows the same
     * order. A mirror placement (`is_origin = false`) is skipped: only the
     * list a card lives in numbers it.
     *
     * The counter lives on `boards.next_card_number` and is read-then-written
     * inside its own `DB::transaction()`, with `lockForUpdate()` on the read.
     * On MySQL that is a real row lock, which is what makes two placements
     * committing at the same moment resolve to two different numbers rather
     * than a lost update; `MAX(number) + 1` would not have that guarantee,
     * because two transactions can read the same max before either commits.
     */
    protected static function booted(): void
    {
        parent::booted();

        CardPlacement::created(function (CardPlacement $placement): void {
            if (! $placement->is_origin) {
                return;
            }

            $card = $placement->card ?? Card::query()->find($placement->card_id);

            if ($card === null || $card->number !== null) {
                return;
            }

            $boardId = BoardList::query()->whereKey($placement->board_list_id)->value('board_id');

            if ($boardId === null) {
                return;
            }

            $number = DB::transaction(function () use ($boardId): int {
                // `whereKey()` is an Eloquent Builder method; `DB::table()` returns the
                // base query builder, where an unrecognised `where*` name falls through
                // to the dynamic-where magic method and silently queries a column
                // called `key` that does not exist, matching nothing. `where('id', …)`
                // is deliberate here, not a style choice.
                $current = (int) (DB::table('boards')->where('id', $boardId)->lockForUpdate()->value('next_card_number') ?? 1);

                DB::table('boards')->where('id', $boardId)->update(['next_card_number' => $current + 1]);

                return $current;
            });

            $card->forceFill([
                'number' => $number,
                'slug' => Str::slug($card->title) ?: 'card-'.$card->id,
            ])->save();
        });
    }

    /**
     * Every list this card is placed in, origin first by its own order.
     *
     * `position` lives here rather than on the card: a mirror has its own place
     * in its own list, so there is no single number that could sit on the card.
     */
    public function placements(): HasMany
    {
        return $this->hasMany(CardPlacement::class)->orderBy('position');
    }

    /** The one placement that says where the card actually lives. */
    public function originPlacement(): HasOne
    {
        return $this->hasOne(CardPlacement::class)->where('is_origin', true);
    }

    /** The card shown somewhere other than where it lives. */
    public function mirrorPlacements(): HasMany
    {
        return $this->hasMany(CardPlacement::class)->where('is_origin', false)->orderBy('position');
    }

    /**
     * The list the card lives in — its origin placement's list.
     *
     * Kept as a relation, and kept eager-loadable, because `CardReader` reads
     * `with('list.board')` and other modules consume the arrays it returns. A
     * `hasOneThrough` constrained on the through table is the honest shape of
     * "one hop through the join to the list that owns the card"; the where
     * clause is qualified because the query joins two tables and `is_origin`
     * would otherwise be whichever the database guessed.
     */
    public function list(): HasOneThrough
    {
        return $this->hasOneThrough(
            BoardList::class,
            CardPlacement::class,
            'card_id',
            'id',
            'id',
            'board_list_id',
        )->where('card_placements.is_origin', true);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'card_label');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'card_members');
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(Checklist::class)->orderBy('position');
    }

    /**
     * Every item across every checklist on the card.
     *
     * Exists so the board can count ticks with two `withCount` subqueries
     * rather than loading every checklist and item to render one "3/9" chip.
     */
    public function checklistItems(): HasManyThrough
    {
        return $this->hasManyThrough(ChecklistItem::class, Checklist::class, 'card_id', 'checklist_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(CardComment::class)->orderBy('created_at');
    }

    /**
     * Who has voted for this card, oldest vote first.
     *
     * Kept as a plain `hasMany` on `CardVote` rather than a `belongsToMany` to
     * `User`, because the board front wants the number and nothing else:
     * `withCount('votes')` on this relation is one subquery per card query,
     * which is what makes a vote chip on the card face cost nothing. The
     * drawer, which does want the names, eager-loads `votes.user`.
     */
    public function votes(): HasMany
    {
        return $this->hasMany(CardVote::class)->orderBy('created_at');
    }

    /**
     * Whether one person has already voted.
     *
     * Reads the loaded collection when there is one — the drawer has it — and
     * falls back to a query when there is not, so this is safe to call from
     * anywhere without knowing what was eager-loaded.
     */
    public function hasVoteFrom(?int $userId): bool
    {
        if ($userId === null) {
            return false;
        }

        return $this->relationLoaded('votes')
            ? $this->votes->contains('user_id', $userId)
            : $this->votes()->where('user_id', $userId)->exists();
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

    /**
     * The one method anything — a badge, the filter panel, Butler's due-date
     * automation once it exists — should ask rather than reading
     * `completed_at` for itself.
     */
    public function isComplete(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * How the due date reads on the front of the card.
     *
     * Trello's full scale is five states: grey beyond 24 hours, yellow inside
     * 24 hours, red at the moment it is due, pink once it has passed, green
     * once complete. `due_on` is a date with no time of day, so "inside 24
     * hours" is read at that same grain — tomorrow — rather than literally
     * counting hours; a due date is either today, tomorrow, or later.
     *
     * 🔴 This widens what the method returns. Two other pages read it:
     * `⚡boards.blade.php`'s filter panel treats `['overdue', 'soon']` as "due
     * in the next week", which used to include a card due today because
     * `'soon'` was the only non-overdue state short of a week out — it now
     * needs `'due'` added to that list to keep meaning what its label says.
     * Accounting's `⚡client-show.blade.php` colours `'overdue'` red and
     * `'soon'` amber and everything else plain — a card due today moves from
     * amber to plain there until it is updated to handle `'due'` as well.
     * Neither file is this task's to change; both are reported back.
     */
    public function dueState(): ?string
    {
        if ($this->due_on === null) {
            return null;
        }

        if ($this->isComplete()) {
            return 'done';
        }

        $today = now()->startOfDay();

        if ($this->due_on->lt($today)) {
            return 'overdue';
        }

        if ($this->due_on->eq($today)) {
            return 'due';
        }

        return $this->due_on->lte($today->copy()->addDay()) ? 'soon' : 'later';
    }

    /**
     * The `Palette` key the due-date badge should render in, for whoever
     * draws it — the card drawer here, and the board card front elsewhere.
     * Centralised so both read the same mapping rather than re-deriving it.
     */
    public function dueBadgeColour(): ?string
    {
        return match ($this->dueState()) {
            'done' => 'success',
            'due' => 'destructive',
            'overdue' => 'pink',
            'soon' => 'warning',
            'later' => 'neutral',
            default => null,
        };
    }

    /** Ticked and total across every checklist on the card. */
    public function checklistProgress(): array
    {
        $items = $this->checklists->flatMap->items;

        return [$items->where('is_done', true)->count(), $items->count()];
    }

    /**
     * The cover this card should show, resolved to what a renderer needs and
     * nothing more — a plain array, never the attachment itself.
     *
     * An image cover names an attachment id rather than a URL, because the
     * URL belongs to Data and this is the one place that reaches for it. That
     * is also what makes a deleted attachment harmless: `AttachmentService::
     * find()` returns null for a soft-deleted or missing row, this method
     * reports "no cover" for it, and the card stays renderable rather than
     * carrying a picture that no longer exists. Nothing here rewrites the
     * stale column — a card whose cover attachment comes back (restored from
     * the archive) is covered again without anything having to remember to
     * reattach it.
     *
     * Read by this card's own drawer and by the board card front, which is
     * why it lives here rather than in a Livewire component either of them
     * would have to duplicate — the same reason `dueBadgeColour()` does.
     *
     * @return null|array{type: 'colour'|'image', size: 'half'|'full', colour: ?string, url: ?string}
     */
    public function coverPresentation(): ?array
    {
        $size = $this->cover_size === 'full' ? 'full' : 'half';

        if ($this->cover_type === 'colour' && $this->cover_colour !== null && Palette::has($this->cover_colour)) {
            return ['type' => 'colour', 'size' => $size, 'colour' => $this->cover_colour, 'url' => null];
        }

        if ($this->cover_type === 'image' && $this->cover_attachment_id !== null) {
            $attachment = app(AttachmentService::class)->find((int) $this->cover_attachment_id);

            if ($attachment === null) {
                return null;
            }

            // `inline_url`, not `download_url` — the latter carries
            // `Content-Disposition: attachment`, which asks the browser to save
            // a picture the card only wants to show.
            return ['type' => 'image', 'size' => $size, 'colour' => null, 'url' => $attachment['inline_url']];
        }

        return null;
    }

    /**
     * Whether the card front should hide its badges — the due date, the
     * checklist count, the comment count — in favour of the cover picture.
     * Only a *full* cover that actually resolves does this; a half cover, or
     * a full cover pointing at a deleted attachment, leaves the badges shown.
     */
    public function coverHidesBadges(): bool
    {
        $cover = $this->coverPresentation();

        return $cover !== null && $cover['size'] === 'full';
    }

    /**
     * Attribute changes go to the activity feed on their own.
     *
     * `CardService` logs the things an attribute diff cannot describe — a move
     * names both lists, a copy names its original. Everything else is a field
     * changing value, and that is what this covers: renaming a card, setting a
     * due date, attaching it to a customer, archiving it.
     *
     * `position` is deliberately absent, and now lives on `CardPlacement`
     * rather than here. It changes on every drag and its before/after would
     * bury the feed in decimals nobody reads — which is also why the placement
     * model carries no activity log of its own.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'description', 'start_on', 'due_on', 'customer_id', 'company_id', 'archived_at', 'completed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('card');
    }

    protected static function newFactory(): CardFactory
    {
        return CardFactory::new();
    }
}
