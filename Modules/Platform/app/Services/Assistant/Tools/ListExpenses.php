<?php

namespace Modules\Platform\Services\Assistant\Tools;

use Modules\Accounting\Contracts\ExpenseReader;
use Modules\Platform\Support\Scopes;

/**
 * A page of expenses.
 *
 * `billable` is tri-state on the contract — true, false, or "do not narrow at
 * all" — and this keeps that shape rather than flattening it to a default,
 * because "every expense" and "only the non-billable ones" are different
 * questions and a model asking the first should not get the second.
 */
class ListExpenses implements Tool
{
    use ReadsArguments;

    public const NAME = 'list_expenses';

    private const MAX_PER_PAGE = 50;

    public function __construct(private readonly ExpenseReader $expenses) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'List expenses, newest first, optionally narrowed by a search term or by whether they are billable to a client. '
            .'Amounts are strings, never numbers — quote them exactly as given.';
    }

    public function scope(): string
    {
        return Scopes::ACCOUNTING_READ;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'q' => ['type' => 'string', 'description' => 'Search the vendor, category and description.'],
                'billable' => ['type' => 'boolean', 'description' => 'Only billable expenses, or only non-billable ones. Omit for all of them.'],
                'cursor' => ['type' => 'string', 'description' => 'The next_cursor from a previous call, to read the following page.'],
                'per_page' => ['type' => 'integer', 'description' => 'How many per page, at most '.self::MAX_PER_PAGE.'.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): array
    {
        $cursor = $this->stringArgument($arguments, 'cursor');

        $page = $this->expenses->paginate(
            $this->stringArgument($arguments, 'q'),
            $this->nullableBoolArgument($arguments, 'billable'),
            $cursor === '' ? null : $cursor,
            $this->intArgument($arguments, 'per_page', 20, 1, self::MAX_PER_PAGE) ?? 20,
        );

        return [
            'expenses' => $page['items'],
            'next_cursor' => $page['next_cursor'],
        ];
    }
}
