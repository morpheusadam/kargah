<?php

namespace Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Project\Database\Factories\CardPlacementFactory;

/**
 * Where a card sits, and in what order it sits there.
 *
 * One card may be placed in several lists — that is a mirror card, the same
 * work shown on the two boards it belongs to. Each placement carries its own
 * `position`, because a card's order in one list says nothing about its order
 * in another.
 *
 * Exactly one placement per card is the **origin**. That is where the card
 * lives: the archive restores to it, the drawer moves it, and deleting the list
 * holding it takes the card with it. Deleting a mirror is a display change and
 * touches nothing else.
 *
 * **No `LogsActivity` here, on purpose.** `CardService` already logs a move by
 * name, with both lists in the entry — which is the thing a person can read. An
 * attribute diff would add a second row per drag carrying a pair of
 * ten-decimal positions, and bury the feed under numbers nobody reads. The
 * reasoning is the same one in `Card::getActivitylogOptions()`, which
 * deliberately leaves `position` off the watched list.
 */
class CardPlacement extends Model
{
    use HasFactory;

    protected $table = 'card_placements';

    protected $fillable = [
        'card_id',
        'board_list_id',
        'position',
        'is_origin',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'decimal:10',
            'is_origin' => 'boolean',
        ];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(BoardList::class, 'board_list_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** The one placement per card that says where the card lives. */
    public function scopeOrigin(Builder $query): Builder
    {
        return $query->where('is_origin', true);
    }

    /** Every placement that is not the origin: the card shown somewhere else. */
    public function scopeMirrors(Builder $query): Builder
    {
        return $query->where('is_origin', false);
    }

    /**
     * The placements a board actually draws.
     *
     * An archived card leaves its origin list, exactly as it always did. It
     * stays on its mirrors, marked archived — you mirrored it somewhere because
     * it mattered there, and having it vanish silently is worse than having it
     * go grey. A soft-deleted card is drawn nowhere at all.
     */
    public function scopeOnCanvas(Builder $query): Builder
    {
        return $query
            ->whereHas('card')
            ->where(fn (Builder $q) => $q
                ->where('card_placements.is_origin', false)
                ->orWhereHas('card', fn ($card) => $card->whereNull('cards.archived_at')));
    }

    public function isOrigin(): bool
    {
        return (bool) $this->is_origin;
    }

    public function isMirror(): bool
    {
        return ! $this->isOrigin();
    }

    protected static function newFactory(): CardPlacementFactory
    {
        return CardPlacementFactory::new();
    }
}
