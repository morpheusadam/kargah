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
        'board_list_id',
        'title',
        'description',
        'position',
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
            'position' => 'decimal:10',
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(BoardList::class, 'board_list_id');
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
     * `position` is deliberately absent. It changes on every drag and its
     * before/after would bury the feed in decimals nobody reads.
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
