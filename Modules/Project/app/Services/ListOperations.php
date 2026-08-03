<?php

namespace Modules\Project\Services;

use Illuminate\Support\Facades\DB;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Support\Position;

/**
 * The two bulk operations a list menu offers: sort this column, and empty it
 * into another one.
 *
 * Separate from `CardService` on purpose. That service is about **one** card —
 * where it sits, where it moves to, what happens to its mirrors — and every
 * method there works hard to write as little as possible, because a drag is
 * one write however long the list is. These two do the opposite: they rewrite
 * a whole column at once, and there is no clever way to sort a list that is
 * not "renumber all of it". Keeping them apart is what stops that trade-off
 * leaking into the drag path.
 *
 * Both operate on **placements**, not cards. A card mirrored into this list
 * from another board is in this column and gets sorted with everything else,
 * but its origin — where it actually lives — is untouched.
 */
final class ListOperations
{
    /**
     * The orders a list can be put into, keyed by the token the UI sends.
     *
     * Trello's own four. The values are what the ⋯ menu prints, so they read
     * as menu items rather than as identifiers.
     *
     * @var array<string, string>
     */
    public const SORTS = [
        'name' => 'Card name (A→Z)',
        'due' => 'Due date (soonest first)',
        'created-newest' => 'Date created (newest first)',
        'created-oldest' => 'Date created (oldest first)',
    ];

    public static function isSort(string $key): bool
    {
        return array_key_exists($key, self::SORTS);
    }

    /**
     * Put a list into one of the orders above, and say how many cards moved.
     *
     * Renumbering is the whole operation, so it is done in one pass with
     * `Position::spread()` rather than by nudging neighbours: the list ends up
     * evenly spaced, which incidentally leaves it in the best possible state
     * for the next few drags before it needs rebalancing again.
     *
     * The sort runs in SQL against the joined card, not in PHP, so a list of
     * 500 cards is one ordered read and one write per row rather than a load
     * of every card's attributes. Ties fall back to the position the cards
     * already had, so sorting twice by the same key is stable and sorting by
     * a column where everything is null changes nothing.
     */
    public function sort(BoardList $list, string $key): int
    {
        if (! self::isSort($key)) {
            return 0;
        }

        $query = CardPlacement::query()
            ->where('card_placements.board_list_id', $list->id)
            ->join('cards', 'cards.id', '=', 'card_placements.card_id')
            ->whereNull('cards.deleted_at')
            ->select('card_placements.id');

        match ($key) {
            'name' => $query->orderBy('cards.title'),
            // Nulls last, in both SQLite and MySQL: `x IS NULL` is 0 for a
            // real date and 1 for a missing one, so ordering by it first puts
            // the dated cards above the undated ones. `NULLS LAST` itself is
            // Postgres-only and would throw here.
            'due' => $query->orderByRaw('cards.due_on is null')->orderBy('cards.due_on'),
            'created-newest' => $query->orderByDesc('cards.created_at'),
            'created-oldest' => $query->orderBy('cards.created_at'),
            default => null,
        };

        $ids = $query->orderBy('card_placements.position')->pluck('card_placements.id');

        if ($ids->isEmpty()) {
            return 0;
        }

        $positions = Position::spread($ids->count());

        DB::transaction(function () use ($ids, $positions): void {
            foreach ($ids as $index => $id) {
                CardPlacement::query()
                    ->whereKey($id)
                    ->update(['position' => $positions[$index]]);
            }
        });

        return $ids->count();
    }

    /**
     * Move every card out of one list and into another, appended in the order
     * they were already in.
     *
     * Returns what happened, because both numbers matter to the person who
     * asked: `moved`, and `skipped` for the cards that could not go.
     *
     * A card is skipped when it is **already placed in the target list** — the
     * unique index on `(card_id, board_list_id)` would refuse the write, and
     * refusing it by name here is what lets the board say something true
     * instead of showing a constraint violation. That is not an edge case: it
     * is exactly what happens when a card is mirrored into both columns, which
     * is a thing mirrors are for.
     *
     * @return array{moved: int, skipped: int}
     */
    public function moveAllCards(BoardList $from, BoardList $to): array
    {
        if ($from->id === $to->id) {
            return ['moved' => 0, 'skipped' => 0];
        }

        $placements = CardPlacement::query()
            ->where('board_list_id', $from->id)
            ->orderBy('position')
            ->get();

        if ($placements->isEmpty()) {
            return ['moved' => 0, 'skipped' => 0];
        }

        // One query for the whole conflict set rather than one per card.
        $alreadyThere = CardPlacement::query()
            ->where('board_list_id', $to->id)
            ->whereIn('card_id', $placements->pluck('card_id'))
            ->pluck('card_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $last = CardPlacement::query()
            ->where('board_list_id', $to->id)
            ->orderByDesc('position')
            ->value('position');

        $cursor = $last === null ? null : Position::format((string) $last);
        $moved = 0;
        $skipped = 0;

        DB::transaction(function () use ($placements, $alreadyThere, &$cursor, &$moved, &$skipped, $to): void {
            foreach ($placements as $placement) {
                if (in_array((int) $placement->card_id, $alreadyThere, true)) {
                    $skipped++;

                    continue;
                }

                $cursor = Position::after($cursor);

                $placement->forceFill([
                    'board_list_id' => $to->id,
                    'position' => $cursor,
                ])->save();

                $moved++;
            }
        });

        return ['moved' => $moved, 'skipped' => $skipped];
    }
}
