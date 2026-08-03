<?php

namespace Modules\Platform\Services\Assistant\Tools;

use Modules\Accounting\Contracts\InvoiceReader;
use Modules\Platform\Support\Scopes;

/**
 * What the whole book is owed, and how much of it is late.
 *
 * **Per currency, never added across currencies** — `InvoiceReader::totals()`
 * is emphatic about why, and a language model is the consumer most likely to
 * helpfully add three currencies together into one confident wrong number. The
 * description below says so in the words the model actually reads, which is
 * the only place a rule like this can be enforced for a tool result: nothing
 * downstream can stop a model doing arithmetic on figures it has been given.
 */
class AccountingTotals implements Tool
{
    use ReadsArguments;

    public const NAME = 'accounting_totals';

    public function __construct(private readonly InvoiceReader $invoices) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Total outstanding and overdue invoice money, given separately for each currency. '
            .'Never add the currencies together and never convert between them: report each one as its own figure.';
    }

    public function scope(): string
    {
        return Scopes::ACCOUNTING_READ;
    }

    public function parameters(): array
    {
        return $this->noParameters();
    }

    public function execute(array $arguments): array
    {
        return $this->invoices->totals();
    }
}
