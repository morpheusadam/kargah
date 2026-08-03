<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\Linkable;
use Modules\Core\Models\Company;
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

    protected $fillable = [
        'slug',
        'name',
        'colour',
        'description',
        'company_id',
        'position',
        'archived_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
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

    public function dotClass(): string
    {
        return Palette::dot($this->colour);
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
