<?php

namespace Modules\Accounting\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;

/**
 * The invoice as a document someone else will read.
 *
 * dompdf is pure PHP with no binary and no daemon, which is the only kind of
 * PDF generation shared hosting will run. wkhtmltopdf and headless Chrome both
 * need a process nobody can start there.
 *
 * What the document must always show, per 03-accounting.md:
 *
 *   - the invoice's own currency, with its symbol
 *   - the reporting-currency figure alongside, marked as converted
 *   - the rate used and its date — never only the converted number
 *   - for a domestic Turkish buyer: the TCMB rate, its date and the TL figure
 *
 * A number whose provenance is invisible is a number nobody can defend to an
 * accountant, so every converted figure on this page carries its rate.
 */
class InvoiceDocument
{
    /** Everything the template needs, with every figure already formatted. */
    public function data(Invoice $invoice): array
    {
        $invoice->loadMissing(['lines', 'company', 'customer', 'payments']);

        return [
            'invoice' => $invoice,
            'lines' => $invoice->lines,
            'company' => $invoice->company,
            'customer' => $invoice->customer,

            'subtotal' => Money::format((string) $invoice->subtotal, $invoice->currency),
            'taxAmount' => Money::format((string) $invoice->tax_amount, $invoice->currency),
            'total' => Money::format((string) $invoice->total, $invoice->currency),

            'lineAmounts' => $invoice->lines->mapWithKeys(fn ($line) => [
                $line->id => Money::format((string) $line->amount, $invoice->currency),
            ]),
            'lineUnitPrices' => $invoice->lines->mapWithKeys(fn ($line) => [
                $line->id => Money::format((string) $line->unit_price, $invoice->currency),
            ]),

            // Shown only alongside the real figure, never instead of it.
            'reporting' => $invoice->reporting_amount === null ? null : [
                'amount' => Money::format((string) $invoice->reporting_amount, $invoice->reporting_currency),
                'currency' => $invoice->reporting_currency,
                'rate' => (string) $invoice->reporting_rate,
                'on' => $invoice->issued_on?->format('j F Y'),
            ],

            // Filled only for a domestic Turkish buyer. The rate type is stated
            // because which one applies is a legal question, not a preference.
            'lira' => $invoice->try_equivalent === null ? null : [
                'amount' => Money::format((string) $invoice->try_equivalent, Currencies::TRY),
                'rate' => (string) $invoice->issue_rate_to_try,
                'source' => $invoice->issue_rate_source,
                'on' => $invoice->issue_rate_date?->format('j F Y'),
                'note' => $invoice->rate_note,
            ],

            'chainPayments' => $invoice->payments
                ->filter(fn ($payment) => $payment->isCrypto())
                ->map(fn ($payment) => $payment->chainDetail)
                ->filter(),
        ];
    }

    /** The rendered PDF, ready to stream or store. */
    public function render(Invoice $invoice): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('accounting::documents.invoice', $this->data($invoice))
            ->setPaper('a4')
            ->setOption(['isRemoteEnabled' => false]);
    }

    public function download(Invoice $invoice): Response
    {
        return $this->render($invoice)->download($this->filename($invoice));
    }

    public function stream(Invoice $invoice): Response
    {
        return $this->render($invoice)->stream($this->filename($invoice));
    }

    private function filename(Invoice $invoice): string
    {
        return $invoice->number.'.pdf';
    }
}
