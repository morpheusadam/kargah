<?php

namespace Modules\Project\Butler;

use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardComment;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Models\Label;
use Modules\Project\Services\CardService;
use Modules\Project\Services\ListOperations;
use Modules\Project\Services\PlacementConflict;

/**
 * What a Butler chain can actually do.
 *
 * Every action goes through the service that already owns the change rather
 * than writing the row itself: a move is `CardService::move()`, a sort is
 * `ListOperations::sort()`, archiving is `CardService::archive()`. That is not
 * politeness about ownership — those methods carry the fractional-position
 * arithmetic, the mirror rules and the activity-log entries, and a Butler rule
 * that moved a card by writing `board_list_id` would produce a card sitting in
 * a list with no feed entry saying how it got there.
 *
 * **Nothing here fires a trigger.** An action *reports* what it changed, as a
 * list of `[trigger, context]` pairs, and `Butler` decides whether to fire
 * them. Keeping the decision in one place is what makes the loop guard a guard
 * rather than a suggestion: an action cannot route round something it cannot
 * call.
 *
 * What gets reported is **only what a model event cannot see**: adding and
 * removing labels and members, which happen on pivot tables Eloquent raises no
 * events for. A move, a date, a completion, an archive and a comment all end in
 * a `save()` or an `insert()` on a real model, and `Hooks` is already listening
 * to those — reporting them here as well would fire every such rule twice.
 *
 * A card whose target list, label or member has been deleted is left alone and
 * the action reports nothing. Same reasoning as `Conditions`: silently doing
 * something *else* is worse than doing nothing.
 */
final class Actions
{
    public function __construct(
        private readonly CardService $cards,
        private readonly ListOperations $lists,
    ) {}

    /**
     * `arg`: 'list' | 'label' | 'member' | 'text' | 'number' | 'date' | 'sort' | null.
     *
     * @var array<string, array{label: string, arg: ?string, arg_label: ?string}>
     */
    public const CATALOGUE = [
        'move_to_list' => ['label' => 'move the card to', 'arg' => 'list', 'arg_label' => 'the list'],
        'move_to_top' => ['label' => 'move the card to the top of its list', 'arg' => null, 'arg_label' => null],
        'move_to_bottom' => ['label' => 'move the card to the bottom of its list', 'arg' => null, 'arg_label' => null],

        'add_label' => ['label' => 'add the label', 'arg' => 'label', 'arg_label' => 'the label'],
        'remove_label' => ['label' => 'remove the label', 'arg' => 'label', 'arg_label' => 'the label'],
        'remove_all_labels' => ['label' => 'remove every label', 'arg' => null, 'arg_label' => null],

        'add_member' => ['label' => 'add the member', 'arg' => 'member', 'arg_label' => 'the member'],
        'remove_member' => ['label' => 'remove the member', 'arg' => 'member', 'arg_label' => 'the member'],
        'remove_all_members' => ['label' => 'take everybody off the card', 'arg' => null, 'arg_label' => null],

        'set_due_in_days' => ['label' => 'set the due date', 'arg' => 'number', 'arg_label' => 'days from today'],
        'set_due_date' => ['label' => 'set the due date to', 'arg' => 'date', 'arg_label' => 'the date'],
        'clear_due' => ['label' => 'remove the due date', 'arg' => null, 'arg_label' => null],
        'set_start_in_days' => ['label' => 'set the start date', 'arg' => 'number', 'arg_label' => 'days from today'],
        'clear_start' => ['label' => 'remove the start date', 'arg' => null, 'arg_label' => null],

        'mark_complete' => ['label' => 'mark the card complete', 'arg' => null, 'arg_label' => null],
        'mark_incomplete' => ['label' => 'mark the card incomplete', 'arg' => null, 'arg_label' => null],

        'archive' => ['label' => 'archive the card', 'arg' => null, 'arg_label' => null],
        'unarchive' => ['label' => 'restore the card from the archive', 'arg' => null, 'arg_label' => null],

        'comment' => ['label' => 'post the comment', 'arg' => 'text', 'arg_label' => 'the comment'],

        'sort_list' => ['label' => "sort the card's list by", 'arg' => 'sort', 'arg_label' => 'the order'],
    ];

    public static function isValid(string $key): bool
    {
        return array_key_exists($key, self::CATALOGUE);
    }

    public static function label(string $key): string
    {
        return self::CATALOGUE[$key]['label'] ?? $key;
    }

    public static function argument(string $key): ?string
    {
        return self::CATALOGUE[$key]['arg'] ?? null;
    }

    public static function argumentLabel(string $key): ?string
    {
        return self::CATALOGUE[$key]['arg_label'] ?? null;
    }

    /**
     * Run one action on one card.
     *
     * @param  array{action?: string, value?: mixed}  $action
     * @return list<array{0: string, 1: array<string, mixed>}> the triggers this
     *                                                         action caused, for `Butler` to fire — or an empty list when
     *                                                         it changed nothing
     */
    public function run(array $action, Card $card): array
    {
        $key = (string) ($action['action'] ?? '');

        if ($key === '' || ! self::isValid($key)) {
            return [];
        }

        $value = $action['value'] ?? null;

        return match ($key) {
            'move_to_list' => $this->moveToList($card, $this->intOrNull($value)),
            'move_to_top' => $this->reorder($card, top: true),
            'move_to_bottom' => $this->reorder($card, top: false),

            'add_label' => $this->addLabel($card, $this->intOrNull($value)),
            'remove_label' => $this->removeLabel($card, $this->intOrNull($value)),
            'remove_all_labels' => $this->removeAllLabels($card),

            'add_member' => $this->addMember($card, $this->intOrNull($value)),
            'remove_member' => $this->removeMember($card, $this->intOrNull($value)),
            'remove_all_members' => $this->removeAllMembers($card),

            'set_due_in_days' => $this->setDate($card, 'due_on', now()->startOfDay()->addDays((int) $value)),
            'set_due_date' => $this->setDate($card, 'due_on', $this->dateOrNull($value)),
            'clear_due' => $this->setDate($card, 'due_on', null),
            'set_start_in_days' => $this->setDate($card, 'start_on', now()->startOfDay()->addDays((int) $value)),
            'clear_start' => $this->setDate($card, 'start_on', null),

            'mark_complete' => $this->setComplete($card, true),
            'mark_incomplete' => $this->setComplete($card, false),

            'archive' => $this->archive($card),
            'unarchive' => $this->unarchive($card),

            'comment' => $this->comment($card, (string) $value),

            'sort_list' => $this->sortList($card, (string) $value),

            default => [],
        };
    }

    /* Placement ------------------------------------------------------------- */

    private function moveToList(Card $card, ?int $listId): array
    {
        $placement = $this->origin($card);
        $target = $listId === null ? null : BoardList::query()->find($listId);

        if ($placement === null || $target === null || (int) $placement->board_list_id === (int) $target->id) {
            return [];
        }

        try {
            // Appended at the bottom: `visibleIndex` is the count of what is
            // already there, and an empty `$visibleIds` tells `CardService` to
            // use the real ordering rather than a browser's filtered view.
            $this->cards->move($placement, $target, $this->countIn($target));
        } catch (PlacementConflict) {
            // The card is mirrored into the target list already. Nothing to do,
            // and nothing went wrong — see ListOperations::moveAllCards().
            return [];
        }

        // `CardPlacement::updated` in `Hooks` has already fired
        // `card.moved_out_of_list` and `card.moved_into_list` from inside that
        // save, with both list ids. Nothing to report.
        return [];
    }

    private function reorder(Card $card, bool $top): array
    {
        $placement = $this->origin($card);
        $list = $placement?->list;

        if ($placement === null || $list === null) {
            return [];
        }

        $this->cards->move($placement, $list, $top ? 0 : $this->countIn($list));

        // A reorder inside one list is not a move in the sense a rule means —
        // `CardPlacementObserver` takes the same position, and firing
        // `moved_into_list` here would make "sort the list" retrigger every
        // rule watching that list, once per card.
        return [];
    }

    private function countIn(BoardList $list): int
    {
        return CardPlacement::query()
            ->where('board_list_id', $list->id)
            ->onCanvas()
            ->count();
    }

    private function origin(Card $card): ?CardPlacement
    {
        return CardPlacement::query()
            ->where('card_id', $card->id)
            ->origin()
            ->first();
    }

    /* Labels and members ----------------------------------------------------- */

    private function addLabel(Card $card, ?int $labelId): array
    {
        $label = $labelId === null ? null : Label::query()->find($labelId);

        if ($label === null) {
            return [];
        }

        $changed = $card->labels()->syncWithoutDetaching([$label->id]);

        $card->unsetRelation('labels');

        // `attached` is empty when the label was already on the card, which is
        // what stops "when a label is added, add that label" from firing itself
        // even before the loop guard sees it.
        return $changed['attached'] === []
            ? []
            : [[Triggers::CARD_LABEL_ADDED, ['label_id' => (int) $label->id, 'board_id' => (int) $label->board_id]]];
    }

    private function removeLabel(Card $card, ?int $labelId): array
    {
        $label = $labelId === null ? null : Label::query()->find($labelId);

        if ($label === null || $card->labels()->whereKey($label->id)->doesntExist()) {
            return [];
        }

        $card->labels()->detach($label->id);
        $card->unsetRelation('labels');

        return [[Triggers::CARD_LABEL_REMOVED, ['label_id' => (int) $label->id, 'board_id' => (int) $label->board_id]]];
    }

    private function removeAllLabels(Card $card): array
    {
        $ids = $card->labels()->pluck('labels.id');

        if ($ids->isEmpty()) {
            return [];
        }

        $card->labels()->detach();
        $card->unsetRelation('labels');

        return $ids->map(fn ($id): array => [Triggers::CARD_LABEL_REMOVED, ['label_id' => (int) $id]])->all();
    }

    private function addMember(Card $card, ?int $userId): array
    {
        if ($userId === null) {
            return [];
        }

        $changed = $card->members()->syncWithoutDetaching([$userId]);

        $card->unsetRelation('members');

        return $changed['attached'] === []
            ? []
            : [[Triggers::CARD_MEMBER_ADDED, ['user_id' => $userId]]];
    }

    private function removeMember(Card $card, ?int $userId): array
    {
        if ($userId === null || $card->members()->whereKey($userId)->doesntExist()) {
            return [];
        }

        $card->members()->detach($userId);
        $card->unsetRelation('members');

        return [[Triggers::CARD_MEMBER_REMOVED, ['user_id' => $userId]]];
    }

    private function removeAllMembers(Card $card): array
    {
        $ids = $card->members()->pluck('users.id');

        if ($ids->isEmpty()) {
            return [];
        }

        $card->members()->detach();
        $card->unsetRelation('members');

        return $ids->map(fn ($id): array => [Triggers::CARD_MEMBER_REMOVED, ['user_id' => (int) $id]])->all();
    }

    /* Dates and state --------------------------------------------------------- */

    private function setDate(Card $card, string $column, mixed $value): array
    {
        $card->{$column} = $value;

        if (! $card->isDirty($column)) {
            return [];
        }

        // `Card::updated` in `Hooks` sees this save and fires `card.due_set` or
        // `card.due_cleared` itself, from inside it.
        $card->save();

        return [];
    }

    private function setComplete(Card $card, bool $complete): array
    {
        if ($card->isComplete() === $complete) {
            return [];
        }

        $card->completed_at = $complete ? now() : null;
        $card->save();

        return [];
    }

    private function archive(Card $card): array
    {
        if ($card->isArchived()) {
            return [];
        }

        $this->cards->archive($card);

        return [];
    }

    private function unarchive(Card $card): array
    {
        if (! $card->isArchived()) {
            return [];
        }

        $this->cards->restore($card);

        return [];
    }

    /* Comment and sort --------------------------------------------------------- */

    private function comment(Card $card, string $template): array
    {
        $body = trim(Interpolator::render($template, $card));

        if ($body === '') {
            return [];
        }

        // `CardComment::created` in `Hooks` fires `comment.added` with this
        // body, so a rule watching for a comment containing a word sees
        // Butler's own comments as well as a person's — deliberately.
        CardComment::query()->create([
            'card_id' => $card->id,
            'created_by' => auth()->id(),
            'body' => $body,
        ]);

        return [];
    }

    private function sortList(Card $card, string $sort): array
    {
        $list = $this->origin($card)?->list;

        if ($list === null || ! ListOperations::isSort($sort)) {
            return [];
        }

        $this->lists->sort($list, $sort);

        // `ListOperations::sort()` rewrites positions through the query
        // builder, which raises no Eloquent events, so nothing else would have
        // fired here anyway. Reported as nothing on purpose: a re-ordered list
        // has not moved any card *between* lists.
        return [];
    }

    /* ------------------------------------------------------------------------- */

    private function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function dateOrNull(mixed $value): mixed
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
