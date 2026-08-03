<?php

namespace Modules\Project\Services;

use Modules\Project\Models\BoardList;
use RuntimeException;

/**
 * A card was asked to sit in a list it is already in.
 *
 * The unique index on `card_placements` would refuse this too, but a caught
 * constraint violation is not an error message: it says nothing about which
 * card, which list, or what the user should do instead. `CardService` refuses
 * by name so the caller can say so.
 */
final class PlacementConflict extends RuntimeException
{
    public static function alreadyPlaced(BoardList $list): self
    {
        return new self('That card is already in '.$list->name.'.');
    }
}
