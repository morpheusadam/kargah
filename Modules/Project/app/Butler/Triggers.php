<?php

namespace Modules\Project\Butler;

/**
 * What a rule can listen for.
 *
 * Every key here is fired by `Modules\Project\Butler\Hooks`, which listens on
 * Eloquent model events rather than on the Livewire components that cause
 * them — so a rule fires however the change was made: the drawer, the board
 * canvas, the table view, a seeder, or another Butler action. That last one is
 * the whole reason `Butler` carries a loop guard.
 *
 * **Two of these have no model event behind them.** `card_label` and
 * `card_members` are plain pivot tables and Eloquent raises nothing at all
 * when a row is attached or detached — `BelongsToMany::attach()` writes
 * through the query builder. So `card.label_added`, `card.label_removed`,
 * `card.member_added` and `card.member_removed` fire when a *Butler action*
 * changes them (which is exactly the "when a label is added, add a label"
 * loop the guard exists for), and need one line at each UI site that toggles
 * a label or a member. Those lines are reported rather than made, because
 * every one of them is in a file this task does not own.
 *
 * `arg` says what the trigger can be narrowed by, and is what the builder
 * draws a second control for. `null` means the trigger takes no qualifier.
 */
final class Triggers
{
    public const CARD_CREATED = 'card.created';

    public const CARD_MOVED_INTO_LIST = 'card.moved_into_list';

    public const CARD_MOVED_OUT_OF_LIST = 'card.moved_out_of_list';

    public const CARD_LABEL_ADDED = 'card.label_added';

    public const CARD_LABEL_REMOVED = 'card.label_removed';

    public const CARD_MEMBER_ADDED = 'card.member_added';

    public const CARD_MEMBER_REMOVED = 'card.member_removed';

    public const CARD_DUE_SET = 'card.due_set';

    public const CARD_DUE_CLEARED = 'card.due_cleared';

    public const CARD_COMPLETED = 'card.completed';

    public const CARD_REOPENED = 'card.reopened';

    public const CARD_ARCHIVED = 'card.archived';

    public const CARD_UNARCHIVED = 'card.unarchived';

    public const CHECKLIST_ITEM_CHECKED = 'checklist.item_checked';

    public const CHECKLIST_COMPLETED = 'checklist.completed';

    public const COMMENT_ADDED = 'comment.added';

    /**
     * Every trigger, in the order the builder lists them.
     *
     * `arg` values: 'list', 'label', 'member', 'text', or null.
     *
     * @var array<string, array{label: string, arg: ?string, arg_label: ?string, auto: bool}>
     */
    public const CATALOGUE = [
        self::CARD_CREATED => [
            'label' => 'a card is created',
            'arg' => 'list',
            'arg_label' => 'in the list',
            'auto' => true,
        ],
        self::CARD_MOVED_INTO_LIST => [
            'label' => 'a card is moved into a list',
            'arg' => 'list',
            'arg_label' => 'the list',
            'auto' => true,
        ],
        self::CARD_MOVED_OUT_OF_LIST => [
            'label' => 'a card is moved out of a list',
            'arg' => 'list',
            'arg_label' => 'the list',
            'auto' => true,
        ],
        self::CARD_LABEL_ADDED => [
            'label' => 'a label is added to a card',
            'arg' => 'label',
            'arg_label' => 'the label',
            'auto' => false,
        ],
        self::CARD_LABEL_REMOVED => [
            'label' => 'a label is removed from a card',
            'arg' => 'label',
            'arg_label' => 'the label',
            'auto' => false,
        ],
        self::CARD_MEMBER_ADDED => [
            'label' => 'a member is added to a card',
            'arg' => 'member',
            'arg_label' => 'the member',
            'auto' => false,
        ],
        self::CARD_MEMBER_REMOVED => [
            'label' => 'a member is removed from a card',
            'arg' => 'member',
            'arg_label' => 'the member',
            'auto' => false,
        ],
        self::CARD_DUE_SET => [
            'label' => 'a due date is set or changed',
            'arg' => null,
            'arg_label' => null,
            'auto' => true,
        ],
        self::CARD_DUE_CLEARED => [
            'label' => 'a due date is removed',
            'arg' => null,
            'arg_label' => null,
            'auto' => true,
        ],
        self::CARD_COMPLETED => [
            'label' => 'a card is marked complete',
            'arg' => null,
            'arg_label' => null,
            'auto' => true,
        ],
        self::CARD_REOPENED => [
            'label' => 'a card is marked incomplete again',
            'arg' => null,
            'arg_label' => null,
            'auto' => true,
        ],
        self::CARD_ARCHIVED => [
            'label' => 'a card is archived',
            'arg' => null,
            'arg_label' => null,
            'auto' => true,
        ],
        self::CARD_UNARCHIVED => [
            'label' => 'a card is restored from the archive',
            'arg' => null,
            'arg_label' => null,
            'auto' => true,
        ],
        self::CHECKLIST_ITEM_CHECKED => [
            'label' => 'a checklist item is ticked',
            'arg' => 'text',
            'arg_label' => 'and its text contains',
            'auto' => true,
        ],
        self::CHECKLIST_COMPLETED => [
            'label' => 'a whole checklist is completed',
            'arg' => null,
            'arg_label' => null,
            'auto' => true,
        ],
        self::COMMENT_ADDED => [
            'label' => 'a comment is posted',
            'arg' => 'text',
            'arg_label' => 'and it contains',
            'auto' => true,
        ],
    ];

    public static function isValid(string $trigger): bool
    {
        return array_key_exists($trigger, self::CATALOGUE);
    }

    public static function label(string $trigger): string
    {
        return self::CATALOGUE[$trigger]['label'] ?? $trigger;
    }

    /** What the trigger can be narrowed by: 'list', 'label', 'member', 'text' or null. */
    public static function argument(string $trigger): ?string
    {
        return self::CATALOGUE[$trigger]['arg'] ?? null;
    }

    public static function argumentLabel(string $trigger): ?string
    {
        return self::CATALOGUE[$trigger]['arg_label'] ?? null;
    }

    /**
     * Whether a model event fires this trigger on its own. The four pivot
     * triggers return false and need a call at the UI site — see the class
     * docblock.
     */
    public static function isAutomatic(string $trigger): bool
    {
        return (bool) (self::CATALOGUE[$trigger]['auto'] ?? false);
    }
}
