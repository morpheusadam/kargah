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
use Modules\Project\Database\Factories\CardFactory;
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
