<?php

namespace Modules\Project\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Core\Contracts\Notifier as NotifierContract;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\Watcher;

/**
 * Who is watching what, and who that means telling about a card.
 *
 * Trello's model, and the reason it is worth copying: watching a card gets you
 * its comments, date changes, moves and archiving; watching a list gets you
 * all of that for every card in it, plus new cards created in it; watching a
 * board gets you all of that for every card on it, plus new cards anywhere.
 *
 * **A card's "board" is plural.** Since mirror cards, one card can sit on
 * several lists across several boards at once — see `CardPlacement`'s own
 * docblock. `recipientsForCard()` therefore does not ask "the" board; it
 * resolves every list and every board the card currently has a placement in,
 * origin or mirror alike, and unions their watchers with the card's own.
 *
 * **Watching a board you merely mirrored a card onto notifies you about that
 * card.** The alternative — counting only the origin board — would make
 * "watch this board" quietly lie: the card genuinely appears there, the
 * mirror is not a copy, and a person who mirrored a card onto their board did
 * so because they wanted to track it from there. So a comment, a due-date
 * change, a move or an archive on the card reaches every board and list it is
 * currently placed on, not only the one it lives on. The one thing this does
 * not extend to is "new card" notifications for the origin creation reaching
 * a board the card is *not yet* mirrored onto — that would mean guessing
 * about a mirror that has not happened.
 *
 * **Two queries resolve a card's recipients, regardless of how many
 * placements or watchers exist.** One reads the card's current list and board
 * ids; one reads every watcher of the card, those lists, or those boards, in
 * a single `WHERE ... OR ... OR ...`. `CardWatchingTest` asserts the count
 * directly with fifty watchers on the table.
 */
class Watching
{
    public function __construct(private readonly NotifierContract $notifier) {}

    /* Watching itself --------------------------------------------------------- */

    /**
     * Start watching. Idempotent: watching something already watched writes
     * nothing the second time and returns the row that is already there.
     */
    public function watch(Model $watchable, int $userId): Watcher
    {
        $existing = $this->find($watchable, $userId);

        if ($existing !== null) {
            return $existing;
        }

        $watcher = new Watcher(['user_id' => $userId]);
        $watcher->watchable()->associate($watchable);
        $watcher->save();

        return $watcher;
    }

    /** @return bool whether a watch was actually removed */
    public function unwatch(Model $watchable, int $userId): bool
    {
        $existing = $this->find($watchable, $userId);

        if ($existing === null) {
            return false;
        }

        $existing->delete();

        return true;
    }

    public function isWatching(Model $watchable, int $userId): bool
    {
        return $this->find($watchable, $userId) !== null;
    }

    /** @return bool the new state — true once watching, false once not */
    public function toggle(Model $watchable, int $userId): bool
    {
        if ($this->isWatching($watchable, $userId)) {
            $this->unwatch($watchable, $userId);

            return false;
        }

        $this->watch($watchable, $userId);

        return true;
    }

    private function find(Model $watchable, int $userId): ?Watcher
    {
        return Watcher::query()
            ->where('watchable_type', $watchable->getMorphClass())
            ->where('watchable_id', $watchable->getKey())
            ->where('user_id', $userId)
            ->first();
    }

    /* Resolving recipients ------------------------------------------------------ */

    /**
     * Who should hear about ongoing activity on `$card` — a comment, a date
     * change, a move, an archive: watchers of the card itself, of every list
     * it is currently placed in, and of every board those lists belong to.
     *
     * @return list<int> deduplicated, with `$excludeUserId` removed — the
     *                   actor never notifies themselves
     */
    public function recipientsForCard(Card $card, ?int $excludeUserId = null): array
    {
        $scope = DB::table('card_placements')
            ->join('board_lists', 'board_lists.id', '=', 'card_placements.board_list_id')
            ->where('card_placements.card_id', $card->getKey())
            ->select('board_lists.id as list_id', 'board_lists.board_id as board_id')
            ->get();

        $listIds = $scope->pluck('list_id')->unique()->values()->all();
        $boardIds = $scope->pluck('board_id')->unique()->values()->all();

        return $this->watcherIds($card->getMorphClass(), $card->getKey(), $listIds, $boardIds, $excludeUserId);
    }

    /**
     * Who should hear that a new card just appeared in `$list` — its own
     * watchers, and the board's. Never the card's own watchers: nobody can
     * watch a card before it exists, which is exactly what makes this the one
     * notification watching a card can never produce on its own.
     *
     * @return list<int>
     */
    public function recipientsForNewCardIn(BoardList $list, ?int $excludeUserId = null): array
    {
        return $this->watcherIds(null, null, [$list->getKey()], [$list->board_id], $excludeUserId);
    }

    /**
     * @param  list<int>  $listIds
     * @param  list<int>  $boardIds
     * @return list<int>
     */
    private function watcherIds(?string $cardType, ?int $cardId, array $listIds, array $boardIds, ?int $excludeUserId): array
    {
        $query = Watcher::query()->where(function ($outer) use ($cardType, $cardId, $listIds, $boardIds) {
            $any = false;

            if ($cardType !== null && $cardId !== null) {
                $outer->orWhere(fn ($w) => $w->where('watchable_type', $cardType)->where('watchable_id', $cardId));
                $any = true;
            }

            if ($listIds !== []) {
                $outer->orWhere(fn ($w) => $w->where('watchable_type', 'board_list')->whereIn('watchable_id', $listIds));
                $any = true;
            }

            if ($boardIds !== []) {
                $outer->orWhere(fn ($w) => $w->where('watchable_type', 'board')->whereIn('watchable_id', $boardIds));
                $any = true;
            }

            if (! $any) {
                // Nothing to match against — force an empty result rather
                // than an unconstrained `where()` that would match every row.
                $outer->whereRaw('1 = 0');
            }
        });

        $ids = $query->pluck('user_id')->map(fn ($id): int => (int) $id)->unique();

        if ($excludeUserId !== null) {
            $ids = $ids->reject(fn (int $id): bool => $id === $excludeUserId);
        }

        return $ids->values()->all();
    }

    /* Notifying --------------------------------------------------------------- */

    /**
     * Tell everyone who should hear about ongoing activity on `$card`.
     *
     * @return int how many notifications were actually written
     */
    public function notifyCardWatchers(Card $card, string $event, string $title, ?string $body, ?int $actorId): int
    {
        $recipients = $this->recipientsForCard($card, $actorId);

        if ($recipients === []) {
            return 0;
        }

        return $this->notifier->notifyMany($recipients, $event, $title, [
            'subject' => $card,
            'body' => $body,
            'url' => $this->cardUrl($card),
            'actor_id' => $actorId,
        ]);
    }

    /**
     * Tell everyone who watches `$list` (or its board) that `$card` just
     * appeared there — created or mirrored, the recipients are the same.
     *
     * @return int how many notifications were actually written
     */
    public function notifyNewCardIn(BoardList $list, Card $card, string $event, string $title, ?int $actorId): int
    {
        $recipients = $this->recipientsForNewCardIn($list, $actorId);

        if ($recipients === []) {
            return 0;
        }

        return $this->notifier->notifyMany($recipients, $event, $title, [
            'subject' => $card,
            'url' => $this->cardUrl($card),
            'actor_id' => $actorId,
        ]);
    }

    /**
     * "Being added to a card" — always notified, regardless of watching. Not
     * routed through `recipientsForCard()` at all: this is the one event 06
     * says bypasses the watch mechanism entirely, same as an @mention.
     *
     * **Not wired to anything yet.** The only call site is
     * `⚡card-detail.blade.php::toggleMember()`, a file this task does not
     * own — see the report for the exact line to add there. This method
     * exists so the producer is complete and directly testable in the
     * meantime; nothing currently calls it.
     *
     * @return array the notification written, or the skipped shape if the
     *               member has turned `card.assigned` off — see
     *               `Notifier::notify()`
     */
    public function notifyMemberAdded(Card $card, int $memberId, ?int $actorId): array
    {
        if ($memberId === $actorId) {
            // Adding yourself to a card is not news to yourself.
            return ['id' => null];
        }

        return $this->notifier->notify($memberId, 'card.assigned', 'You were added to "'.$card->title.'"', [
            'subject' => $card,
            'url' => $this->cardUrl($card),
            'actor_id' => $actorId,
        ]);
    }

    /**
     * Where clicking a card notification goes: the card, open.
     *
     * This used to name the board alone, because the board had no per-card
     * URL — a notification saying "you were mentioned on a card" then opened a
     * board with nothing highlighted, which is the defect
     * `project-guaid/HANDOVER-2026-08-05.md` files under "no per-card deep
     * link". `⚡boards.blade.php` now carries a `card` URL property and opens
     * that card from `mount()`, so the id belongs in the link.
     *
     * **Still the card's origin board, not the board that produced the
     * notification.** A watcher of a board a card is only *mirrored* onto is
     * sent to where the card lives rather than to their own board. That is
     * unchanged from before and stays deliberate: the origin is the one board
     * every recipient of this notification can be told about without a query
     * per recipient, and the card is drawn there for all of them. The cost is
     * that a mirror-board watcher arrives somewhere they did not expect; the
     * alternative is a per-recipient URL, which this method has no recipient
     * to build one for.
     *
     * 🔴 The board component refuses a `card` id that is not on the named
     * board, archived, or deleted — so a link built here for a card that is
     * later archived degrades to the board with a sentence saying why, not to
     * a drawer full of somebody else's card. Do not "fix" that refusal by
     * loosening it; it is what stops an incrementing integer in this URL from
     * walking the `cards` table.
     */
    public function cardUrl(Card $card): ?string
    {
        $board = $card->list?->board;

        return $board === null
            ? null
            : route('projects.boards', ['board' => $board->slug, 'card' => $card->getKey()]);
    }
}
