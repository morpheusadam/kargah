<?php

namespace Modules\Platform\Services\Assistant\Tools;

use Modules\Platform\Support\Scopes;
use Modules\Project\Contracts\BoardReader;

/**
 * Cards whose due date has passed and which are still open.
 *
 * "What is overdue?" is the question `07-platform.md` uses as the CLI's own
 * worked example, and this is half of the answer — the other half is
 * `AccountingTotals`, whose `overdue` figure is about invoices rather than
 * work. Both exist so the model can answer the whole question rather than the
 * half it happened to have a tool for.
 */
class CardsOverdue implements Tool
{
    use ReadsArguments;

    public const NAME = 'cards_overdue';

    private const MAX_LIMIT = 50;

    public function __construct(private readonly BoardReader $boards) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'List cards whose due date has passed and which are still open, across every board, most overdue first.';
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
                'limit' => ['type' => 'integer', 'description' => 'How many cards to return, at most '.self::MAX_LIMIT.'.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): array
    {
        $cards = $this->boards->cardsOverdue($this->intArgument($arguments, 'limit', 20, 1, self::MAX_LIMIT) ?? 20);

        return [
            'count' => $this->boards->countOverdue(),
            'showing' => count($cards),
            'cards' => $cards,
        ];
    }
}
