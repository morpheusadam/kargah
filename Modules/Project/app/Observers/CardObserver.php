<?php

namespace Modules\Project\Observers;

use Modules\Core\Contracts\Notifier;
use Modules\Project\Models\Card;
use Modules\Project\Services\Watching;
use Modules\Project\Support\Mentions;

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
    public function __construct(
        private readonly Watching $watching,
        private readonly Notifier $notifier,
    ) {}

    public function created(Card $card): void
    {
        // A card written with a description that already names somebody. There
        // is no "before" to compare against, so everyone named is new.
        $this->notifyMentions($card, Mentions::recipients($card->description, auth()->id()));
    }

    public function updated(Card $card): void
    {
        $actorId = auth()->id();

        if ($card->wasChanged('due_on') || $card->wasChanged('start_on')) {
            $this->notifyDateChange($card, $actorId);
        }

        if ($card->wasChanged('description')) {
            $this->notifyDescriptionMentions($card, $actorId);
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

    /**
     * Only the people the edit *added*.
     *
     * A description is edited over and over, and re-notifying everyone already
     * named in it every time a typo is fixed would make the feature something
     * people ask to turn off. `getOriginal()` gives the text as it was before
     * this save, so the difference is exactly who is newly named.
     */
    private function notifyDescriptionMentions(Card $card, ?int $actorId): void
    {
        $before = Mentions::recipients($card->getOriginal('description'), $actorId);
        $after = Mentions::recipients($card->description, $actorId);

        $this->notifyMentions($card, array_values(array_diff($after, $before)));
    }

    /**
     * A mention always notifies, watching or not — 06 puts it in the same
     * sentence as being added to a card.
     *
     * No `dedupe_key`, on purpose: the caller has already diffed against the
     * previous text, so a description saved twice unchanged produces an empty
     * list here rather than a duplicate the key would have to catch. A key
     * would also be wrong in the one case that matters — somebody removed from
     * the description and named again later is being mentioned a second time,
     * and should hear about it a second time.
     *
     * @param  list<int>  $userIds
     */
    private function notifyMentions(Card $card, array $userIds): void
    {
        $actor = auth()->user()?->name ?? 'Someone';

        foreach ($userIds as $userId) {
            $this->notifier->notify(
                $userId,
                'card.mentioned',
                $actor.' mentioned you in the description of "'.$card->title.'"',
                [
                    'subject' => $card,
                    'url' => $this->watching->cardUrl($card),
                    'actor_id' => auth()->id(),
                ],
            );
        }
    }
}
