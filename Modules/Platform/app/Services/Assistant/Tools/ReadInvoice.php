<?php

namespace Modules\Platform\Services\Assistant\Tools;

use Modules\Accounting\Contracts\InvoiceReader;
use Modules\Platform\Support\Scopes;

/**
 * One invoice in full, lines included.
 *
 * Read only. `InvoiceReader::issue()` exists on the same contract and is
 * deliberately **not** a tool: `07-platform.md` draws the line at "anything
 * that spends money or sends mail asks first — drafting an invoice is a tool,
 * issuing one is not", and issuing freezes an exchange rate onto a legal
 * document. There is no drafting tool either, because no contract exposes
 * drafting; see the report.
 */
class ReadInvoice implements Tool
{
    use ReadsArguments;

    public const NAME = 'read_invoice';

    public function __construct(private readonly InvoiceReader $invoices) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Read one invoice by its numeric id, including its lines, tax, total and what is still outstanding. '
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
                'invoice_id' => ['type' => 'integer', 'description' => 'The invoice id, as returned by list_invoices.'],
            ],
            'required' => ['invoice_id'],
        ];
    }

    public function execute(array $arguments): array
    {
        $id = $this->intArgument($arguments, 'invoice_id', null, 1);

        if ($id === null) {
            return ['error' => 'invoice_id is required and must be a number.'];
        }

        $invoice = $this->invoices->find($id);

        if ($invoice === null) {
            return ['error' => 'There is no invoice with id '.$id.'.'];
        }

        return ['invoice' => $invoice];
    }
}
