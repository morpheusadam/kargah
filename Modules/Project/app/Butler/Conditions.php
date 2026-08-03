<?php

namespace Modules\Project\Butler;

use Modules\Project\Models\Card;

/**
 * The filters that qualify a trigger before its actions run — and, for a board
 * button, the filter that decides which cards it runs over at all. One
 * vocabulary doing both jobs, because they are the same question asked twice:
 * "does this card match?"
 *
 * **Every condition must pass.** There is no OR and no if/else branch. Spec 06
 * asks for if/else inside a rule; this is the AND half of it, and the honest
 * position is that a rule with an else-branch is a second rule with the
 * inverted condition — which every condition here has, deliberately, as its
 * own key (`in_list` / `not_in_list`, `has_label` / `lacks_label`). What that
 * does not give you is a *shared* action prefix across both branches. Noted in
 * the report rather than half-built.
 *
 * A condition naming a list or a label that has since been deleted evaluates
 * to **false**, never to "skip me". A rule quietly widening its own scope when
 * somebody deletes a label is worse than a rule that stops running.
 */
final class Conditions
{
    /**
     * `arg`: 'list' | 'label' | 'member' | 'text' | 'number' | null.
     *
     * @var array<string, array{label: string, arg: ?string}>
     */
    public const CATALOGUE = [
        'in_list' => ['label' => 'the card is in the list', 'arg' => 'list'],
        'not_in_list' => ['label' => 'the card is not in the list', 'arg' => 'list'],
        'has_label' => ['label' => 'the card has the label', 'arg' => 'label'],
        'lacks_label' => ['label' => 'the card does not have the label', 'arg' => 'label'],
        'has_any_label' => ['label' => 'the card has at least one label', 'arg' => null],
        'has_no_labels' => ['label' => 'the card has no labels', 'arg' => null],
        'has_member' => ['label' => 'the card has the member', 'arg' => 'member'],
        'lacks_member' => ['label' => 'the card does not have the member', 'arg' => 'member'],
        'has_no_members' => ['label' => 'the card has nobody on it', 'arg' => null],
        'is_complete' => ['label' => 'the card is complete', 'arg' => null],
        'is_not_complete' => ['label' => 'the card is not complete', 'arg' => null],
        'has_due' => ['label' => 'the card has a due date', 'arg' => null],
        'no_due' => ['label' => 'the card has no due date', 'arg' => null],
        'is_overdue' => ['label' => 'the card is overdue', 'arg' => null],
        'due_within_days' => ['label' => 'the card is due within N days', 'arg' => 'number'],
        'is_archived' => ['label' => 'the card is archived', 'arg' => null],
        'is_not_archived' => ['label' => 'the card is not archived', 'arg' => null],
        'title_contains' => ['label' => "the card's title contains", 'arg' => 'text'],
        'title_starts_with' => ['label' => "the card's title starts with", 'arg' => 'text'],
        'has_unchecked_items' => ['label' => 'the card has unticked checklist items', 'arg' => null],
        'checklists_complete' => ['label' => 'every checklist on the card is complete', 'arg' => null],
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

    /**
     * Does this card satisfy every condition in the set?
     *
     * An empty set passes — a rule with no conditions is "always", which is
     * what somebody who wrote no conditions meant.
     *
     * @param  list<array{condition?: string, value?: mixed}>  $conditions
     */
    public static function allPass(array $conditions, Card $card): bool
    {
        foreach ($conditions as $condition) {
            $key = (string) ($condition['condition'] ?? '');

            if ($key === '' || ! self::isValid($key)) {
                continue;
            }

            if (! self::passes($key, $condition['value'] ?? null, $card)) {
                return false;
            }
        }

        return true;
    }

    private static function passes(string $key, mixed $value, Card $card): bool
    {
        return match ($key) {
            'in_list' => self::listId($card) === self::intOrNull($value),
            'not_in_list' => self::listId($card) !== self::intOrNull($value),

            'has_label' => self::labelIds($card)->contains(self::intOrNull($value)),
            'lacks_label' => ! self::labelIds($card)->contains(self::intOrNull($value)),
            'has_any_label' => self::labelIds($card)->isNotEmpty(),
            'has_no_labels' => self::labelIds($card)->isEmpty(),

            'has_member' => self::memberIds($card)->contains(self::intOrNull($value)),
            'lacks_member' => ! self::memberIds($card)->contains(self::intOrNull($value)),
            'has_no_members' => self::memberIds($card)->isEmpty(),

            'is_complete' => $card->isComplete(),
            'is_not_complete' => ! $card->isComplete(),

            'has_due' => $card->due_on !== null,
            'no_due' => $card->due_on === null,
            'is_overdue' => $card->dueState() === 'overdue',
            'due_within_days' => self::dueWithin($card, (int) $value),

            'is_archived' => $card->isArchived(),
            'is_not_archived' => ! $card->isArchived(),

            'title_contains' => self::contains($card->title, $value),
            'title_starts_with' => self::startsWith($card->title, $value),

            'has_unchecked_items' => $card->checklistItems()->where('is_done', false)->exists(),
            'checklists_complete' => ! $card->checklistItems()->where('is_done', false)->exists()
                && $card->checklistItems()->exists(),

            default => true,
        };
    }

    private static function listId(Card $card): ?int
    {
        $id = $card->originPlacement?->board_list_id ?? $card->list?->id;

        return $id === null ? null : (int) $id;
    }

    private static function labelIds(Card $card): \Illuminate\Support\Collection
    {
        return ($card->relationLoaded('labels') ? $card->labels : $card->labels()->get())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
    }

    private static function memberIds(Card $card): \Illuminate\Support\Collection
    {
        return ($card->relationLoaded('members') ? $card->members : $card->members()->get())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id);
    }

    private static function dueWithin(Card $card, int $days): bool
    {
        if ($card->due_on === null) {
            return false;
        }

        return $card->due_on->lte(now()->startOfDay()->addDays(max(0, $days)));
    }

    private static function contains(?string $haystack, mixed $needle): bool
    {
        $needle = trim((string) $needle);

        return $needle !== '' && str_contains(mb_strtolower((string) $haystack), mb_strtolower($needle));
    }

    private static function startsWith(?string $haystack, mixed $needle): bool
    {
        $needle = trim((string) $needle);

        return $needle !== '' && str_starts_with(mb_strtolower((string) $haystack), mb_strtolower($needle));
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
