<?php

namespace Modules\Accounting\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Modules\Accounting\Contracts\InvoiceReader as InvoiceReaderContract;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\InvoiceLine;
use Modules\Accounting\Support\Money;

/**
 * `InvoiceReader` over the real tables.
 *
 * Reads only. Issuing goes through the existing `InvoiceIssuer` — this class
 * shapes what comes back into arrays and decides, once, what "call issue()
 * twice" means for a caller that cannot see the model.
 */
class InvoiceReader implements InvoiceReaderContract
{
    public function __construct(
        private readonly InvoiceIssuer $issuer,
        private readonly PaymentRecorder $payments,
    ) {}

    public function find(int $id): ?array
    {
        $invoice = $this->query()->find($id);

        return $invoice === null ? null : $this->shape($invoice);
    }

    public function paginate(?string $status = null, string $search = '', ?string $cursor = null, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));
        $query = $this->scoped($status, $search);

        $decoded = $cursor === null || $cursor === ''
            ? null
            : rescue(fn (): ?Cursor => Cursor::fromEncoded($cursor), null, false);

        $paginator = $query->orderByDesc('id')->cursorPaginate($perPage, ['*'], 'cursor', $decoded);

        return [
            'items' => $paginator->getCollection()->map(fn (Invoice $invoice): array => $this->shape($invoice))->all(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'prev_cursor' => $paginator->previousCursor()?->encode(),
            'per_page' => $perPage,
        ];
    }

    public function issue(int $id, string $reportingCurrency = 'USD'): ?array
    {
        $invoice = $this->query()->find($id);

        if ($invoice === null) {
            return null;
        }

        // Idempotent by design, not by catching InvoiceIssuer's refusal: an
        // invoice that is already issued has already had its one moment, and a
        // retried request gets the figures that moment produced rather than a
        // second attempt at freezing them.
        if (! $invoice->isIssued()) {
            $this->issuer->issue($invoice, $reportingCurrency);
            $invoice = $this->query()->find($id);
        }

        return $this->shape($invoice);
    }

    private function query(): Builder
    {
        return Invoice::query()->with(['customer', 'company', 'lines']);
    }

    private function scoped(?string $status, string $search): Builder
    {
        $query = $this->query();

        $term = trim($search);

        if ($term !== '') {
            $like = '%'.$term.'%';

            $query->where(fn (Builder $q) => $q
                ->where('number', 'like', $like)
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', $like))
                ->orWhereHas('company', fn (Builder $c) => $c->where('name', 'like', $like)));
        }

        return match ($status) {
            'draft' => $query->draft(),
            'sent' => $query->issued()->whereIn('status', ['sent', 'part_paid']),
            'paid' => $query->where('status', 'paid'),
            'overdue' => $query->overdue(),
            default => $query,
        };
    }

    private function shape(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'status' => $invoice->status,
            'currency' => $invoice->currency,
            'customer' => $invoice->customer === null ? null : [
                'id' => $invoice->customer->id,
                'name' => $invoice->customer->name,
            ],
            'company' => $invoice->company === null ? null : [
                'id' => $invoice->company->id,
                'name' => $invoice->company->name,
            ],
            'subtotal' => $this->money((string) $invoice->subtotal, $invoice->currency),
            'tax_percent' => (string) $invoice->tax_percent,
            'tax_amount' => $this->money((string) $invoice->tax_amount, $invoice->currency),
            'total' => $this->money((string) $invoice->total, $invoice->currency),
            'reporting' => $invoice->reporting_currency === null ? null : [
                'currency' => $invoice->reporting_currency,
                'rate' => $invoice->reporting_rate === null ? null : (string) $invoice->reporting_rate,
                'amount' => $invoice->reporting_amount === null
                    ? null
                    : $this->money((string) $invoice->reporting_amount, $invoice->reporting_currency),
            ],
            'outstanding' => $this->money($this->payments->outstanding($invoice), $invoice->currency),
            'is_issued' => $invoice->isIssued(),
            'is_overdue' => $invoice->isOverdue(),
            'issued_on' => $invoice->issued_on?->toDateString(),
            'due_on' => $invoice->due_on?->toDateString(),
            'sent_at' => $invoice->sent_at?->toIso8601String(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'voided_at' => $invoice->voided_at?->toIso8601String(),
            'lines' => $invoice->lines->map(fn (InvoiceLine $line): array => [
                'id' => $line->id,
                'description' => $line->description,
                'quantity' => (string) $line->quantity,
                'unit_price' => $this->money((string) $line->unit_price, $invoice->currency),
                'amount' => $this->money((string) $line->amount, $invoice->currency),
            ])->all(),
        ];
    }

    /** @return array{amount: string, currency: string, formatted: string} */
    private function money(string $amount, string $currency): array
    {
        return [
            'amount' => $amount,
            'currency' => $currency,
            'formatted' => Money::format($amount, $currency),
        ];
    }
}
