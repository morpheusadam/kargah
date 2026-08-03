<?php

namespace Modules\Project\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Support\Position;

/**
 * Everything that changes where a card sits.
 *
 * The one rule worth stating: a drag writes one row. The list keeps its order
 * in a fractional column, so a card dropped between two others takes the
 * midpoint of its neighbours and nothing else moves. Renumbering a 500-card
 * list on every drag is the behaviour this exists to avoid.
 *
 * Since mirror cards, the row being written is a **placement**, not a card. A
 * card may be placed in several lists and each placement carries its own
 * position, so "where the card is" is only ever a question about one placement.
 * Everything below therefore reasons about placements; the card-level methods
 * that remain — `append`, `archive`, `restore` — are the ones that genuinely
 * concern the card itself.
 */
class CardService
{
    /**
     * Move one placement to a position in a list.
     *
     * Moving a mirror moves only that mirror; the origin does not notice. And a
     * card cannot be placed in the same list twice, so a move into a list the
     * card is already in is refused by name rather than left to the unique
     * index — a caught constraint violation is not an error message.
     *
     * `$visibleIndex` is what the browser reported: the index the card landed
     * on among the cards *it can see*. A filter may be hiding rows between
     * them, so the index is resolved against the visible ordering and then
     * bracketed by real positions — never treated as an offset into the table.
     *
     * @param  list<int>  $visibleIds  Ordered *placement* ids the browser had on
     *                                 screen for the target list, before the drop.
     *
     * @throws PlacementConflict
     */
    public function move(CardPlacement $placement, BoardList $toList, int $visibleIndex, array $visibleIds = []): CardPlacement
    {
        $clash = $this->placementIn($placement->card_id, $toList);

        if ($clash !== null && $clash->id !== $placement->id) {
            throw PlacementConflict::alreadyPlaced($toList);
        }

        return DB::transaction(function () use ($placement, $toList, $visibleIndex, $visibleIds) {
            $fromList = $placement->list;

            $neighbours = $this->neighbours($placement, $toList, $visibleIndex, $visibleIds);

            if (Position::needsRebalance($neighbours['before'], $neighbours['after'])) {
                // The gap between two neighbours cannot be halved for ever.
                // This is the only path that writes more than one row, and it
                // is reached roughly once every forty insertions in the same
                // place — not once per drag.
                $this->rebalance($toList);

                $neighbours = $this->neighbours($placement->fresh(), $toList, $visibleIndex, $visibleIds);
            }

            $placement->board_list_id = $toList->id;
            $placement->position = Position::between($neighbours['before'], $neighbours['after']);
            $placement->save();

            $card = $placement->card;

            activity('card')
                ->performedOn($card)
                ->causedBy(auth()->user())
                ->event('card.moved')
                ->withProperties([
                    'from_list' => $fromList?->name,
                    'to_list' => $toList->name,
                    'position' => (string) $placement->position,
                    'mirror' => $placement->isMirror(),
                ])
                ->log($fromList && $fromList->id !== $toList->id
                    ? 'moved from '.$fromList->name.' to '.$toList->name
                    : 'reordered in '.$toList->name);

            return $placement;
        });
    }

    /**
     * The two real positions the placement must land between.
     *
     * @param  list<int>  $visibleIds
     * @return array{before: ?string, after: ?string}
     */
    private function neighbours(CardPlacement $placement, BoardList $toList, int $visibleIndex, array $visibleIds): array
    {
        $order = $this->orderedPositions($toList, exceptPlacementId: $placement->id);

        // Restrict to what the browser actually had on screen, in its order. An
        // ordinal into this array is not an ordinal into the table: a filter
        // may be hiding rows between any two of them.
        $visible = $visibleIds === []
            ? $order->values()->all()
            : collect($visibleIds)
                ->reject(fn ($id): bool => (int) $id === $placement->id)
                ->map(fn ($id): ?string => $order->get((int) $id))
                ->filter()
                ->values()
                ->all();

        $before = $visibleIndex > 0 ? ($visible[$visibleIndex - 1] ?? null) : null;
        $after = $visible[$visibleIndex] ?? null;

        // Dropped above everything visible: go above everything real, so a card
        // hidden by the filter does not end up on top of it.
        if ($before === null && $after === null) {
            $extreme = $this->extremes($toList, $placement->id);

            return $visibleIndex <= 0
                ? ['before' => null, 'after' => $extreme['min']]
                : ['before' => $extreme['max'], 'after' => null];
        }

        if ($before === null) {
            $min = $this->extremes($toList, $placement->id)['min'];

            // Nothing real sits above the first visible card, so the top of the
            // list is genuinely free.
            return ['before' => null, 'after' => $min ?? $after];
        }

        if ($after === null) {
            $max = $this->extremes($toList, $placement->id)['max'];

            return ['before' => $max ?? $before, 'after' => null];
        }

        return ['before' => $before, 'after' => $after];
    }

    /** @return Collection<int, string> placement id => position */
    private function orderedPositions(BoardList $list, ?int $exceptPlacementId = null): Collection
    {
        return CardPlacement::query()
            ->where('board_list_id', $list->id)
            ->when($exceptPlacementId, fn ($q) => $q->where('id', '!=', $exceptPlacementId))
            ->onCanvas()
            ->orderBy('position')
            ->pluck('position', 'id')
            ->map(fn ($position): string => Position::format((string) $position));
    }

    /** @return array{min: ?string, max: ?string} */
    private function extremes(BoardList $list, ?int $exceptPlacementId = null): array
    {
        $positions = $this->orderedPositions($list, $exceptPlacementId)->values();

        return [
            'min' => $positions->first(),
            'max' => $positions->last(),
        ];
    }

    /**
     * Spread a list's placements evenly again.
     *
     * Writes every row, which is exactly why it is not on the drag path. Run it
     * twice and the second run writes the values the first one already wrote.
     */
    public function rebalance(BoardList $list): int
    {
        $placements = CardPlacement::query()
            ->where('board_list_id', $list->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id']);

        $positions = Position::spread($placements->count());

        DB::transaction(function () use ($placements, $positions) {
            foreach ($placements as $index => $placement) {
                CardPlacement::query()->whereKey($placement->id)->update(['position' => $positions[$index]]);
            }
        });

        return $placements->count();
    }

    /** Create a card, and the origin placement that says where it lives. */
    public function append(BoardList $list, string $title, array $attributes = []): Card
    {
        $card = DB::transaction(function () use ($list, $title, $attributes): Card {
            $card = Card::query()->create([
                'title' => trim($title),
                'created_by' => auth()->id(),
                ...$attributes,
            ]);

            $this->place($card, $list, isOrigin: true);

            return $card;
        });

        activity('card')
            ->performedOn($card)
            ->causedBy(auth()->user())
            ->event('card.created')
            ->log('added to '.$list->name);

        return $card;
    }

    /**
     * Show the same card in another list.
     *
     * Idempotent: mirroring a card into a list it is already in returns the
     * placement that is already there and writes nothing. Every job in this
     * project has to be safe to run twice, and a picker somebody double-clicks
     * is no different.
     */
    public function mirror(Card $card, BoardList $toList, ?int $createdBy = null): CardPlacement
    {
        $existing = $this->placementIn($card->id, $toList);

        if ($existing !== null) {
            return $existing;
        }

        $placement = $this->place($card, $toList, isOrigin: false, createdBy: $createdBy);

        activity('card')
            ->performedOn($card)
            ->causedBy(auth()->user())
            ->event('card.mirrored')
            ->withProperties(['list' => $toList->name])
            ->log('mirrored onto '.$toList->name);

        return $placement;
    }

    /**
     * Stop showing a card in a list it was mirrored into.
     *
     * Refuses the origin and returns false. A card always has exactly one
     * origin; removing it is what `archive()` and the card's own delete are
     * for, and neither of those is a thing to do by accident from a list of
     * mirrors.
     */
    public function unmirror(CardPlacement $placement): bool
    {
        if ($placement->isOrigin()) {
            return false;
        }

        $card = $placement->card;
        $list = $placement->list;

        $placement->delete();

        activity('card')
            ->performedOn($card)
            ->causedBy(auth()->user())
            ->event('card.unmirrored')
            ->withProperties(['list' => $list?->name])
            ->log($list ? 'no longer mirrored onto '.$list->name : 'no longer mirrored');

        return true;
    }

    /** The card's placement in a list, if it has one. */
    public function placementIn(int $cardId, BoardList $list): ?CardPlacement
    {
        return CardPlacement::query()
            ->where('card_id', $cardId)
            ->where('board_list_id', $list->id)
            ->first();
    }

    public function archive(Card $card): Card
    {
        $card->forceFill(['archived_at' => now()])->save();

        activity('card')
            ->performedOn($card)
            ->causedBy(auth()->user())
            ->event('card.archived')
            ->log('archived');

        return $card;
    }

    public function restore(Card $card): Card
    {
        $card->forceFill(['archived_at' => null])->save();

        activity('card')
            ->performedOn($card)
            ->causedBy(auth()->user())
            ->event('card.restored')
            ->log('restored to the board');

        return $card;
    }

    /** A placement at the bottom of a list. */
    private function place(Card $card, BoardList $list, bool $isOrigin, ?int $createdBy = null): CardPlacement
    {
        $last = CardPlacement::query()
            ->where('board_list_id', $list->id)
            ->orderByDesc('position')
            ->value('position');

        return CardPlacement::query()->create([
            'card_id' => $card->id,
            'board_list_id' => $list->id,
            'position' => Position::after($last === null ? null : Position::format((string) $last)),
            'is_origin' => $isOrigin,
            'created_by' => $createdBy ?? auth()->id(),
        ]);
    }
}
