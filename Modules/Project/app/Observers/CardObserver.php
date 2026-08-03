<?php

namespace Modules\Project\Observers;

use Modules\Project\Models\Card;
use Modules\Project\Services\Watching;

/**
 * Card fields changed → notify the watchers, for the two changes 06's
 * Notifications section names: a date, and archiving.
 *
 * Registered as a model observer rather than called from `CardService`, so it
 * fires however the change was made — `CardService::archive()`, the drawer's
 * due-date popover, a future bulk action — every one of them ends in
 * `Card::save()`, which is the one place this needs to listen.
 *
 * `auth()->id()` is the actor here, not a column, because unlike a comment a
 * `Card` row carries no "changed by" of its own. A change made outside a web
 * request — a seeder, the due-date sweep touching `completed_at` once that
 * exists, the assistant later — has no actor to exclude, which is correct:
 * there is nobody to exclude *themselves* from hearing about it.
 */
class CardObserver
{
    public function __construct(private readonly Watching $watching) {}

    public function updated(Card $card): void
    {
        $actorId = auth()->id();

        if ($card->wasChanged('due_on') || $card->wasChanged('start_on')) {
            $this->notifyDateChange($card, $actorId);
        }

        // Restoring also changes `archived_at` — back to null — and is
        // deliberately silent here. 06 names "archiving", not "restoring",
        // and a card coming back onto the board is visible on the board
        // itself the moment it happens.
        if ($card->wasChanged('archived_at') && $card->archived_at !== null) {
            $this->watching->notifyCardWatchers(
                $card,
                'card.archived',
                '"'.$card->title.'" was archived',
                null,
                $actorId,
            );
        }
    }

    private function notifyDateChange(Card $card, ?int $actorId): void
    {
        $dueChanged = $card->wasChanged('due_on');
        $field = $dueChanged ? 'due date' : 'start date';
        $value = $dueChanged ? $card->due_on : $card->start_on;

        $title = $value === null
            ? 'The '.$field.' was removed from "'.$card->title.'"'
            : 'The '.$field.' on "'.$card->title.'" is now '.$value->format('j M Y');

        $this->watching->notifyCardWatchers($card, 'card.due_changed', $title, null, $actorId);
    }
}
