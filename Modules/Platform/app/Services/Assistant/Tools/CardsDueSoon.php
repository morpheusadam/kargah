<?php

namespace Modules\Platform\Services\Assistant\Tools;

use Modules\Platform\Support\Scopes;
use Modules\Project\Contracts\BoardReader;

/**
 * Cards due within a window, across every board.
 *
 * Not overdue — that is `CardsOverdue`, and the contract keeps them apart for
 * the reason its docblock gives: "due within `$days`" includes today and
 * excludes anything already past, so the two answers never double-count a
 * card. `count` comes from `countDueSoon()` rather than from `count($cards)`,
 * because the list is bounded by `limit` and a model told "3 cards are due"
 * when 40 are is worse than one told nothing.
 */
class CardsDueSoon implements Tool
{
    use ReadsArguments;

    public const NAME = 'cards_due_soon';

    private const MAX_LIMIT = 50;

    public function __construct(private readonly BoardReader $boards) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'List cards due within the next few days across every board, soonest first. '
            .'Use days=0 for "due today". Completed and archived cards are never included.';
    }

    public function scope(): string
    {
        return Scopes::PROJECT_READ;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'description' => 'How far ahead to look, in days. 0 means today. Defaults to 30.'],
                'limit' => ['type' => 'integer', 'description' => 'How many cards to return, at most '.self::MAX_LIMIT.'.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): array
    {
        $days = $this->intArgument($arguments, 'days', 30, 0, 365) ?? 30;
        $limit = $this->intArgument($arguments, 'limit', 20, 1, self::MAX_LIMIT) ?? 20;

        $cards = $this->boards->cardsDueSoon($days, $limit);

        return [
            'days' => $days,
            'count' => $this->boards->countDueSoon($days),
            'showing' => count($cards),
            'cards' => $cards,
        ];
    }
}
