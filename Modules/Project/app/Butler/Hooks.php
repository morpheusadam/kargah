<?php

namespace Modules\Project\Butler;

use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardComment;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Models\ChecklistItem;

/**
 * Where the triggers actually come from.
 *
 * Model events, registered from `ButlerServiceProvider` — not observers, and
 * not calls in the Livewire components. `Modules/Project/app/Observers/` is
 * another task's in-flight work this session, and a `Model::updated()` closure
 * reaches the same moment as an observer method without sharing a file with
 * anybody. Multiple listeners on one event coexist fine; `Card::booted()`
 * already registers a `CardPlacement::created` listener of its own for card
 * numbering, and this adds a second.
 *
 * Everything below is a `saved`/`created`/`updated` listener, so a rule fires
 * however the change was made — the drawer, the board canvas, the table view,
 * a seeder, or another Butler action. The last of those is the loop, and
 * `Butler` is what stops it.
 *
 * **`card_label` and `card_members` are not here.** They are pivot tables and
 * Eloquent raises no event for `attach()`/`detach()`/`toggle()` — the write
 * goes through the query builder. Butler's own label and member actions report
 * their changes back to the engine directly (see `Actions`), so a rule can
 * still cascade off one; what is missing is the *UI* half, which needs one
 * line at each site that toggles a label or a member. Those lines are in the
 * final report rather than in this file, because every one of them is in a
 * component this task does not own.
 */
final class Hooks
{
    public static function register(): void
    {
        self::placements();
        self::cards();
        self::checklists();
        self::comments();
    }

    /**
     * A card appearing in a list, and a card moving between lists.
     *
     * `created` fires `card.created` for the origin placement only — a mirror
     * is the same card shown twice, not a new one, and a rule that added a
     * label to "every card created in Inbox" should not fire again when
     * somebody mirrors that card onto a second board.
     *
     * `updated` is the one event that produces two triggers: a card leaving one
     * list is the same write as it arriving in another, and rules exist for
     * both. `getOriginal()` is what makes the "out of" half possible at all.
     *
     * 🔴 The destination list is looked up **by id**, never read off
     * `$placement->list`. `CardService::move()` reads `$placement->list` before
     * it changes `board_list_id`, to name both lists in the activity entry —
     * which loads and caches the relation pointing at the *old* list. By the
     * time this listener runs, `$placement->list` is still that cached old
     * list, and a rule listening for "moved into Done" would silently never
     * match. Cost: one query, and the difference between a working feature and
     * one that fires for the wrong list.
     */
    private static function placements(): void
    {
        CardPlacement::created(function (CardPlacement $placement): void {
            if (! $placement->is_origin) {
                return;
            }

            $card = $placement->card;
            $list = self::listById($placement->board_list_id);

            if ($card === null || $list === null) {
                return;
            }

            self::butler()->fire(Triggers::CARD_CREATED, $card, [
                'list_id' => (int) $list->id,
                'board_id' => (int) $list->board_id,
            ]);
        });

        CardPlacement::updated(function (CardPlacement $placement): void {
            if (! $placement->wasChanged('board_list_id')) {
                return;
            }

            $card = $placement->card;
            $list = self::listById($placement->board_list_id);

            if ($card === null || $list === null) {
                return;
            }

            $fromId = $placement->getOriginal('board_list_id');
            $boardId = (int) $list->board_id;

            if ($fromId !== null) {
                self::butler()->fire(Triggers::CARD_MOVED_OUT_OF_LIST, $card, [
                    'list_id' => (int) $fromId,
                    'board_id' => $boardId,
                ]);
            }

            self::butler()->fire(Triggers::CARD_MOVED_INTO_LIST, $card, [
                'list_id' => (int) $list->id,
                'board_id' => $boardId,
            ]);
        });
    }

    /**
     * The card's own columns: dates, completion, archiving.
     *
     * All read from `wasChanged()` plus `getOriginal()` rather than from the
     * new value alone, because "a due date is set" and "a due date is changed"
     * are the same trigger but "a due date is removed" is a different one, and
     * only the before-value tells them apart.
     */
    private static function cards(): void
    {
        Card::updated(function (Card $card): void {
            $butler = self::butler();

            if ($card->wasChanged('due_on')) {
                $butler->fire(
                    $card->due_on === null ? Triggers::CARD_DUE_CLEARED : Triggers::CARD_DUE_SET,
                    $card,
                );
            }

            if ($card->wasChanged('completed_at')) {
                $butler->fire(
                    $card->completed_at === null ? Triggers::CARD_REOPENED : Triggers::CARD_COMPLETED,
                    $card,
                );
            }

            if ($card->wasChanged('archived_at')) {
                $butler->fire(
                    $card->archived_at === null ? Triggers::CARD_UNARCHIVED : Triggers::CARD_ARCHIVED,
                    $card,
                );
            }
        });
    }

    /**
     * A checklist item being ticked, and the checklist it completes.
     *
     * The second is derived from the first rather than stored: the moment an
     * item goes to done, ask whether anything on that checklist is still
     * open. One `exists()` per tick, and no "is this checklist finished"
     * column that could disagree with its own items.
     */
    private static function checklists(): void
    {
        ChecklistItem::updated(function (ChecklistItem $item): void {
            if (! $item->wasChanged('is_done') || ! $item->is_done) {
                return;
            }

            $checklist = $item->checklist;
            $card = $checklist?->card;

            if ($card === null) {
                return;
            }

            $butler = self::butler();

            $butler->fire(Triggers::CHECKLIST_ITEM_CHECKED, $card, ['text' => (string) $item->text]);

            if ($checklist->items()->where('is_done', false)->doesntExist()) {
                $butler->fire(Triggers::CHECKLIST_COMPLETED, $card, ['text' => (string) $checklist->name]);
            }
        });
    }

    private static function comments(): void
    {
        CardComment::created(function (CardComment $comment): void {
            $card = $comment->card;

            if ($card === null) {
                return;
            }

            self::butler()->fire(Triggers::COMMENT_ADDED, $card, ['text' => (string) $comment->body]);
        });
    }

    /**
     * Resolved per event, not captured once. The container holds one instance
     * — that is what makes the loop guard shared — but resolving late keeps
     * the provider's `boot()` from constructing the engine, and everything it
     * depends on, on every request that never fires a trigger.
     */
    private static function butler(): Butler
    {
        return app(Butler::class);
    }

    /** The list as it is *now*, not as some caller's cached relation remembers it. */
    private static function listById(mixed $id): ?BoardList
    {
        return $id === null ? null : BoardList::query()->find((int) $id);
    }
}
