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

            'period' => $this->period($invoice),
            'signature' => $this->signature($invoice),
            'footer' => (string) config('accounting.document.footer', ''),
        ];
    }

    /**
     * The engagement's working period, already worded, or null.
     *
     * Composed here rather than in the template because three of the four
     * combinations need different wording and only one of them is a range. A
     * half-open period is a real thing a person types — work that started on a
     * date and has no agreed end — and printing "1 July 2026 — —" for it would
     * put a dash where the client looks for a date.
     *
     * 🔴 Both null returns null, and the template prints nothing at all. That
     * is the normal case: every invoice raised before the columns existed has
     * no period, and none of them may grow a dangling label.
     */
    private function period(Invoice $invoice): ?string
    {
        $starts = $invoice->starts_on?->format('j F Y');
        $ends = $invoice->ends_on?->format('j F Y');

        return match (true) {
            $starts !== null && $ends !== null => $starts.' – '.$ends,
            $starts !== null => 'From '.$starts,
            $ends !== null => 'Until '.$ends,
            default => null,
        };
    }

    /**
     * The signature block: the image already inlined, the name, and the date.
     *
     * `image` is null whenever there is no usable file, and that is not an
     * error — the block falls back to the rule, the typed name and the date. An
     * invoice that refused to render because a decoration is absent would be a
     * worse failure than an unsigned one, and the signature file is absent on a
     * fresh install by definition.
     *
     * `date` is the invoice's own issue date and is null on a draft, which has
     * not been issued and therefore has no date to sign against.
     */
    private function signature(Invoice $invoice): array
    {
        $path = config('accounting.document.signature_image');

        return [
            'image' => is_string($path) && $path !== '' ? $this->inlineImage(public_path($path)) : null,
            'name' => (string) config('accounting.document.signature_name', ''),
            'date' => $invoice->issued_on?->format('j F Y'),
        ];
    }

    /**
     * A file on disk as a `data:` URI, or null if it cannot be read.
     *
     * 🔴 Read here and not in the template. dompdf runs with
     * `isRemoteEnabled => false` and resolves every `src` through its own chroot
     * and protocol whitelist, so a path that `is_file()` agrees with in PHP does
     * not necessarily resolve inside the renderer — and when it does not, dompdf
     * draws nothing and reports nothing. `data://` is on dompdf's default
     * allowed-protocol list with no rules attached (`Options::$allowedProtocols`)
     * and is decoded before any of that machinery runs, so a data URI cannot
     * miss.
     *
     * The mime type is detected from the bytes rather than assumed from the
     * extension: the setting is a path a person typed, and a JPEG named `.png`
     * would otherwise be announced as something it is not.
     */
    private function inlineImage(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $bytes = @file_get_contents($path);

        if ($bytes === false || $bytes === '') {
            return null;
        }

        $mime = $this->mimeOf($path);

        return $mime === null ? null : 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /**
     * The file's own mime type, or null if nothing can identify it.
     *
     * `getimagesize()` first because it both names the type and proves the file
     * really is a raster image; `finfo` second because SVG is a signature format
     * dompdf supports and `getimagesize()` cannot read. Null rather than a
     * guessed default — an unidentifiable file is treated exactly like a missing
     * one, which is the path that is already safe.
     */
    private function mimeOf(string $path): ?string
    {
        $size = @getimagesize($path);

        if (is_array($size) && isset($size['mime']) && is_string($size['mime'])) {
            return $size['mime'];
        }

        if (function_exists('finfo_open') && ($finfo = @finfo_open(FILEINFO_MIME_TYPE)) !== false) {
            $mime = @finfo_file($finfo, $path);
            finfo_close($finfo);

            if (is_string($mime) && str_starts_with($mime, 'image/')) {
                return $mime;
            }
        }

        return null;
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
