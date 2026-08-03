<?php

namespace Modules\Project\Observers;

use Modules\Project\Models\CardPlacement;
use Modules\Project\Services\Watching;

/**
 * A card arriving somewhere, or moving, → notify the watchers.
 *
 * Two different notifications live here, and they reach two different
 * audiences on purpose:
 *
 * - `created()` is "a card just appeared in this list" — origin or mirror,
 *   the recipients are the same either way (see `Watching`'s own docblock on
 *   why a mirror counts). This is the one notification watching a card can
 *   never itself produce, because nobody can watch a card before it exists —
 *   06's "watch a list … plus new cards created in it".
 * - `updated()` fires only when `board_list_id` actually changes, never on a
 *   same-list reorder — `CardService::rebalance()` rewrites hundreds of rows
 *   through the query builder, which raises no Eloquent events at all, and a
 *   drag that only reorders a list is not "moved" in the sense 06 means.
 *
 * The actor for `created()` is `$placement->created_by`, the column
 * `CardService::place()` already fills in; `updated()` has no such column on
 * `CardPlacement` (see its own docblock — position is deliberately untracked)
 * so it falls back to `auth()->id()`, same reasoning as `CardObserver`.
 */
class CardPlacementObserver
{
    public function __construct(private readonly Watching $watching) {}

    public function created(CardPlacement $placement): void
    {
        $card = $placement->card;
        $list = $placement->list;

        if ($card === null || $list === null) {
            return;
        }

        $verb = $placement->is_origin ? 'added to' : 'mirrored onto';

        $this->watching->notifyNewCardIn(
            $list,
            $card,
            'card.new_in_list',
            '"'.$card->title.'" was '.$verb.' '.$list->name,
            $placement->created_by,
        );
    }

    public function updated(CardPlacement $placement): void
    {
        if (! $placement->wasChanged('board_list_id')) {
            return;
        }

        $card = $placement->card;
        $list = $placement->list;

        if ($card === null || $list === null) {
            return;
        }

        $this->watching->notifyCardWatchers(
            $card,
            'card.moved',
            '"'.$card->title.'" was moved to '.$list->name,
            null,
            auth()->id(),
        );
    }
}
