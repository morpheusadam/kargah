<?php

namespace Modules\Platform\Services\Assistant\Tools;

use Modules\Accounting\Contracts\InvoiceReader;
use Modules\Platform\Support\Scopes;

/**
 * A page of the invoice book.
 *
 * The cursor is passed through rather than hidden, so a model that genuinely
 * needs the next page can ask for it; it is not looped over here, because
 * walking a whole book into a provider's context window is exactly the shape
 * of accident the iteration cap in `AssistantConversation` exists to bound.
 *
 * Every money figure arrives from the contract as
 * `{amount, currency, formatted}` with `amount` a **string**, and it is passed
 * on untouched. `03-accounting.md` explains at length why a JSON number is not
 * acceptable for money; the fact that the consumer here is a language model
 * rather than a client library does not change it — `json_encode` of a float
 * is where the digits get lost, whoever reads them next.
 */
class ListInvoices implements Tool
{
    use ReadsArguments;

    public const NAME = 'list_invoices';

    private const STATUSES = ['draft', 'sent', 'paid', 'overdue'];

    private const MAX_PER_PAGE = 50;

    public function __construct(private readonly InvoiceReader $invoices) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'List invoices, newest first, optionally narrowed to a status or a search term. '
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
                'status' => [
                    'type' => 'string',
                    'enum' => self::STATUSES,
                    'description' => 'Narrow to one status. Omit for all invoices.',
                ],
                'q' => ['type' => 'string', 'description' => 'Search invoice numbers and customer names.'],
                'cursor' => ['type' => 'string', 'description' => 'The next_cursor from a previous call, to read the following page.'],
                'per_page' => ['type' => 'integer', 'description' => 'How many per page, at most '.self::MAX_PER_PAGE.'.'],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments): array
    {
        $status = $this->stringArgument($arguments, 'status');

        if ($status !== '' && ! in_array($status, self::STATUSES, true)) {
            return ['error' => 'status must be one of: '.implode(', ', self::STATUSES).'.'];
        }

        $cursor = $this->stringArgument($arguments, 'cursor');

        $page = $this->invoices->paginate(
            $status === '' ? null : $status,
            $this->stringArgument($arguments, 'q'),
            $cursor === '' ? null : $cursor,
            $this->intArgument($arguments, 'per_page', 20, 1, self::MAX_PER_PAGE) ?? 20,
        );

        return [
            'invoices' => $page['items'],
            'next_cursor' => $page['next_cursor'],
        ];
    }
}
