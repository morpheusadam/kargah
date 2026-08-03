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
        'due_on',
        'completed_at',
        'archived_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
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
     * How the due date reads on the front of the card.
     *
     * 'overdue' and 'soon' are what the board colours by, and 'soon' means the
     * next seven days — the same window the filter panel offers.
     */
    public function dueState(): ?string
    {
        if ($this->due_on === null) {
            return null;
        }

        $today = now()->startOfDay();

        if ($this->completed_at !== null) {
            return 'done';
        }

        if ($this->due_on->lt($today)) {
            return 'overdue';
        }

        return $this->due_on->lte($today->copy()->addDays(7)) ? 'soon' : 'later';
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
            ->logOnly(['title', 'description', 'due_on', 'customer_id', 'company_id', 'archived_at', 'completed_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('card');
    }

    protected static function newFactory(): CardFactory
    {
        return CardFactory::new();
    }
}
