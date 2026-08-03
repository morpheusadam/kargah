<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Project\Database\Factories\BoardListFactory;
use Modules\Project\Support\Palette;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * A column. Named `BoardList` because `List` is a reserved word in PHP.
 */
class BoardList extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    protected $table = 'board_lists';

    protected $fillable = [
        'board_id',
        'name',
        'position',
        'archived_at',
        'created_by',
        'colour',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'decimal:10',
            'archived_at' => 'datetime',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /** Every card placed in this list, in this list's own order. */
    public function placements(): HasMany
    {
        return $this->hasMany(CardPlacement::class, 'board_list_id')->orderBy('position');
    }

    /**
     * The cards in this list, through their placements.
     *
     * Ordered by the **qualified** column: `cards` no longer has a `position`
     * of its own, and an unqualified `order by position` here is a trap waiting
     * for somebody to add one back.
     */
    public function cards(): BelongsToMany
    {
        return $this->belongsToMany(Card::class, 'card_placements')
            ->withPivot(['id', 'position', 'is_origin'])
            ->orderBy('card_placements.position');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * The header colour's whole class string, or `null` when the list has none
     * — the default for every list, and the state nothing here forces a
     * choice out of.
     */
    public function headerColourClass(): ?string
    {
        return $this->colour === null ? null : Palette::tone($this->colour);
    }

    /**
     * A card may never be left with no placement at all.
     *
     * Deleting a list takes its placements with it — the schema cascade does
     * that on a hard delete, and a soft delete would otherwise leave rows
     * pointing at a column nobody draws. Either way a card whose *only*
     * placement was in this list would become invisible and unreachable: off
     * every board, and absent from the archive, which reads a card through its
     * origin list. So it is soft-deleted here instead, which is the same thing
     * that happens to it from the archive's own delete button.
     *
     * A card mirrored here but living elsewhere loses only the mirror.
     *
     * Note this fires on the model, not on a mass `whereIn(...)->delete()`.
     * The two callers that delete lists in bulk write the same thing by hand;
     * this is the guarantee for everything that does not.
     */
    protected static function booted(): void
    {
        static::deleting(function (BoardList $list): void {
            $cardIds = CardPlacement::query()
                ->where('board_list_id', $list->id)
                ->pluck('card_id');

            if ($cardIds->isEmpty()) {
                return;
            }

            CardPlacement::query()->where('board_list_id', $list->id)->delete();

            Card::query()
                ->whereIn('id', $cardIds)
                ->whereDoesntHave('placements')
                ->delete();
        });
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'board_id', 'archived_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('list');
    }

    protected static function newFactory(): BoardListFactory
    {
        return BoardListFactory::new();
    }
}
