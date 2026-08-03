<?php

namespace Modules\Project\Services;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Project\Models\Board;
use Modules\Project\Models\Card;
use Modules\Project\Models\ChecklistItem;
use Modules\Project\Support\IcsCalendar;
use Modules\Project\Support\IcsEvent;

/**
 * A board's due dates, turned into an `.ics` document.
 *
 * `IcsCalendar` and `IcsEvent` know nothing about Eloquent, cards or boards on
 * purpose — this is the one place that bridges the two, so the mapping lives
 * once rather than once per caller.
 *
 * **One event per card, never per placement.** `Board::cards()` is already
 * deduplicated for exactly this reason: a card mirrored onto two lists of the
 * same board has one due date, not two, and a subscriber's calendar showing
 * the same appointment twice is a worse bug than showing it once from the
 * "wrong" list. Which list a card is mirrored into has no bearing on when it
 * is due.
 *
 * **Archived cards are left out.** A due date on a card nobody is working on
 * any more is not something to keep surfacing on a calendar somebody
 * subscribed to for what is still owed.
 *
 * **The `UID` is `card-{id}@{host}`, never the placement id.** A card must
 * keep the same UID across every regeneration — see `IcsEvent`'s own docblock
 * — and since this maps one event per *card*, the id that has to stay stable
 * is the card's.
 *
 * **Advanced checklist items are on the feed too.** An item can carry a due
 * date of its own, and 06 says those appear on the calendar; there is no
 * separate ICS path, so putting them in `events()` is what puts them in the
 * feed as well. They get their own UID space — `checklist-item-{id}@{host}` —
 * because an item id and a card id collide numerically and would otherwise
 * overwrite each other in a subscriber's calendar. A ticked item is left off
 * for the same reason an archived card is: it is no longer owed.
 */
class BoardCalendar
{
    /**
     * The whole feed for one board, ready to be served as `text/calendar`.
     */
    public function build(Board $board): string
    {
        return IcsCalendar::build($this->events($board), $board->name, $this->stamp($board));
    }

    /** @return list<IcsEvent> */
    public function events(Board $board): array
    {
        return [
            ...$this->dueCards($board)->map(fn (Card $card): IcsEvent => $this->toEvent($card, $board))->all(),
            ...$this->dueItems($board)->map(fn (ChecklistItem $item): IcsEvent => $this->toItemEvent($item, $board))->all(),
        ];
    }

    /**
     * `DTSTAMP` for every event in the feed.
     *
     * Deliberately not `now()`: the same data must produce the same stamp on
     * every regeneration, or the feed's bytes — and its `ETag` — change on
     * every poll even when nothing did. The latest `updated_at` among the
     * cards actually on the feed is the true "last modified" of what a
     * subscriber can see; the board's own `updated_at` covers the case where
     * only its name changed, which moves `X-WR-CALNAME` with nothing due.
     */
    public function stamp(Board $board): DateTimeImmutable
    {
        /** @var ?Carbon $latestCard */
        $latestCard = $this->dueCards($board)->max('updated_at');
        /** @var ?Carbon $latestItem */
        $latestItem = $this->dueItems($board)->max('updated_at');
        $boardUpdated = $board->updated_at;

        $moment = collect([$latestCard, $latestItem, $boardUpdated])
            ->filter()
            ->sort()
            ->last() ?? now();

        return DateTimeImmutable::createFromInterface($moment);
    }

    /**
     * Every active card on the board with a due date, distinct by card.
     *
     * @return Collection<int, Card>
     */
    private function dueCards(Board $board): Collection
    {
        return $board->cards()
            ->active()
            ->whereNotNull('due_on')
            ->orderBy('due_on')
            ->orderBy('id')
            ->get(['id', 'title', 'due_on', 'updated_at']);
    }

    /**
     * Every dated, unticked checklist item on an active card on the board.
     *
     * Scoped through the card ids `Board::cards()` already resolves, so a
     * mirrored card's items appear once — the same deduplication the cards
     * themselves get, for the same reason.
     *
     * @return Collection<int, ChecklistItem>
     */
    private function dueItems(Board $board): Collection
    {
        return ChecklistItem::query()
            ->whereNotNull('due_on')
            ->where('is_done', false)
            ->whereIn('checklist_id', fn ($q) => $q
                ->select('id')
                ->from('checklists')
                ->whereIn('card_id', $board->cards()->active()->select('cards.id')))
            ->with('checklist.card:id,title')
            ->orderBy('due_on')
            ->orderBy('id')
            ->get();
    }

    private function toEvent(Card $card, Board $board): IcsEvent
    {
        return new IcsEvent(
            uid: 'card-'.$card->id.'@'.$this->host(),
            summary: $card->title,
            start: DateTimeImmutable::createFromInterface($card->due_on),
            allDay: true,
            url: route('projects.boards', ['board' => $board->slug]),
            lastModified: $card->updated_at !== null
                ? DateTimeImmutable::createFromInterface($card->updated_at)
                : null,
        );
    }

    /**
     * A checklist item as an event.
     *
     * The summary is the item's own text, and the card it belongs to goes in
     * the description rather than the title — a subscriber's month view shows
     * summaries and nothing else, and prefixing every item with its card would
     * make eight items on one card read as eight copies of the same line.
     */
    private function toItemEvent(ChecklistItem $item, Board $board): IcsEvent
    {
        $cardTitle = $item->checklist?->card?->title;

        return new IcsEvent(
            uid: 'checklist-item-'.$item->id.'@'.$this->host(),
            summary: $item->text,
            start: DateTimeImmutable::createFromInterface($item->due_on),
            allDay: true,
            description: $cardTitle === null ? null : 'Checklist item on '.$cardTitle,
            url: route('projects.boards', ['board' => $board->slug]),
            lastModified: $item->updated_at !== null
                ? DateTimeImmutable::createFromInterface($item->updated_at)
                : null,
        );
    }

    /**
     * The domain half of the UID. `config('app.url')` rather than the request,
     * because a scheduled or console regeneration of the same feed has no
     * request to read a host from, and the UID has to be the same either way.
     */
    private function host(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'kargah.local';
    }

    /**
     * A per-board secret that makes the feed's signed URL revocable.
     *
     * Generated lazily, on the first request for a link — most boards will
     * never be subscribed to, and there is no reason to mint a secret for one
     * nobody has asked for yet. `token_hash`-style hashing does not apply
     * here: this token is not a credential compared in a login path, it is a
     * capability that travels inside the URL itself, so it is stored and
     * compared as plain bytes, the same way a share link's own token is.
     */
    public function tokenFor(Board $board): string
    {
        if ($board->feed_token !== null) {
            return $board->feed_token;
        }

        $token = Str::random(48);

        $board->forceFill(['feed_token' => $token])->save();

        return $token;
    }

    /** Invalidate every URL issued before now. */
    public function regenerateToken(Board $board): string
    {
        $token = Str::random(48);

        $board->forceFill(['feed_token' => $token])->save();

        return $token;
    }

    public function feedUrl(Board $board): string
    {
        return URL::signedRoute('projects.calendar-feed', [
            'board' => $board->slug,
            'token' => $this->tokenFor($board),
        ]);
    }
}
