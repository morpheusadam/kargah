<?php

namespace Modules\Project\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Support\Position;

/**
 * Everything that changes where a card sits.
 *
 * The one rule worth stating: a drag writes one row. The list keeps its order
 * in a fractional column, so a card dropped between two others takes the
 * midpoint of its neighbours and nothing else moves. Renumbering a 500-card
 * list on every drag is the behaviour this exists to avoid.
 */
class CardService
{
    /**
     * Place a card at a position in a list.
     *
     * `$visibleIndex` is what the browser reported: the index the card landed
     * on among the cards *it can see*. A filter may be hiding rows between
     * them, so the index is resolved against the visible ordering and then
     * bracketed by real positions — never treated as an offset into the table.
     *
     * @param  list<int>  $visibleIds  Ordered ids the browser had on screen for
     *                                 the target list, before the drop.
     */
    public function move(Card $card, BoardList $toList, int $visibleIndex, array $visibleIds = []): Card
    {
        return DB::transaction(function () use ($card, $toList, $visibleIndex, $visibleIds) {
            $fromList = $card->list;

            $neighbours = $this->neighbours($card, $toList, $visibleIndex, $visibleIds);

            if (Position::needsRebalance($neighbours['before'], $neighbours['after'])) {
                // The gap between two neighbours cannot be halved for ever.
                // This is the only path that writes more than one row, and it
                // is reached roughly once every forty insertions in the same
                // place — not once per drag.
                $this->rebalance($toList);

                $neighbours = $this->neighbours($card->fresh(), $toList, $visibleIndex, $visibleIds);
            }

            $card->board_list_id = $toList->id;
            $card->position = Position::between($neighbours['before'], $neighbours['after']);
            $card->save();

            activity('card')
                ->performedOn($card)
                ->causedBy(auth()->user())
                ->event('card.moved')
                ->withProperties([
                    'from_list' => $fromList?->name,
                    'to_list' => $toList->name,
                    'position' => (string) $card->position,
                ])
                ->log($fromList && $fromList->id !== $toList->id
                    ? 'moved from '.$fromList->name.' to '.$toList->name
                    : 'reordered in '.$toList->name);

            return $card;
        });
    }

    /**
     * The two real positions the card must land between.
     *
     * @param  list<int>  $visibleIds
     * @return array{before: ?string, after: ?string}
     */
    private function neighbours(Card $card, BoardList $toList, int $visibleIndex, array $visibleIds): array
    {
        $order = $this->orderedPositions($toList, exceptCardId: $card->id);

        // Restrict to what the browser actually had on screen, in its order. An
        // ordinal into this array is not an ordinal into the table: a filter
        // may be hiding rows between any two of them.
        $visible = $visibleIds === []
            ? $order->values()->all()
            : collect($visibleIds)
                ->reject(fn ($id): bool => (int) $id === $card->id)
                ->map(fn ($id): ?string => $order->get((int) $id))
                ->filter()
                ->values()
                ->all();

        $before = $visibleIndex > 0 ? ($visible[$visibleIndex - 1] ?? null) : null;
        $after = $visible[$visibleIndex] ?? null;

        // Dropped above everything visible: go above everything real, so a card
        // hidden by the filter does not end up on top of it.
        if ($before === null && $after === null) {
            $extreme = $this->extremes($toList, $card->id);

            return $visibleIndex <= 0
                ? ['before' => null, 'after' => $extreme['min']]
                : ['before' => $extreme['max'], 'after' => null];
        }

        if ($before === null) {
            $min = $this->extremes($toList, $card->id)['min'];

            // Nothing real sits above the first visible card, so the top of the
            // list is genuinely free.
            return ['before' => null, 'after' => $min ?? $after];
        }

        if ($after === null) {
            $max = $this->extremes($toList, $card->id)['max'];

            return ['before' => $max ?? $before, 'after' => null];
        }

        return ['before' => $before, 'after' => $after];
    }

    /** @return Collection<int, string> id => position */
    private function orderedPositions(BoardList $list, ?int $exceptCardId = null): Collection
    {
        return Card::query()
            ->where('board_list_id', $list->id)
            ->when($exceptCardId, fn ($q) => $q->where('id', '!=', $exceptCardId))
            ->active()
            ->orderBy('position')
            ->pluck('position', 'id')
            ->map(fn ($position): string => Position::format((string) $position));
    }

    /** @return array{min: ?string, max: ?string} */
    private function extremes(BoardList $list, ?int $exceptCardId = null): array
    {
        $positions = $this->orderedPositions($list, $exceptCardId)->values();

        return [
            'min' => $positions->first(),
            'max' => $positions->last(),
        ];
    }

    /**
     * Spread a list's cards evenly again.
     *
     * Writes every row, which is exactly why it is not on the drag path.
     */
    public function rebalance(BoardList $list): int
    {
        $cards = Card::query()
            ->where('board_list_id', $list->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get(['id']);

        $positions = Position::spread($cards->count());

        DB::transaction(function () use ($cards, $positions) {
            foreach ($cards as $index => $card) {
                Card::query()->whereKey($card->id)->update(['position' => $positions[$index]]);
            }
        });

        return $cards->count();
    }

    /** Create a card at the bottom of a list. */
    public function append(BoardList $list, string $title, array $attributes = []): Card
    {
        $last = Card::query()
            ->where('board_list_id', $list->id)
            ->orderByDesc('position')
            ->value('position');

        $card = Card::query()->create([
            'board_list_id' => $list->id,
            'title' => trim($title),
            'position' => Position::after($last === null ? null : Position::format((string) $last)),
            'created_by' => auth()->id(),
            ...$attributes,
        ]);

        activity('card')
            ->performedOn($card)
            ->causedBy(auth()->user())
            ->event('card.created')
            ->log('added to '.$list->name);

        return $card;
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
}
