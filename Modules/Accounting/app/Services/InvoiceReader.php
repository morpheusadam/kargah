<?php

namespace Modules\Accounting\Services;

use Brick\Money\Money as BrickMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Modules\Accounting\Contracts\InvoiceReader as InvoiceReaderContract;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\InvoiceLine;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Support\Currencies;
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

    public function issue(int $id, ?string $reportingCurrency = null): ?array
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

    /**
     * See the contract's docblock for why this is per currency rather than
     * one cross-currency figure.
     *
     * Two queries, whatever the size of the book: the outstanding rows
     * themselves, and their payments in one bulk `whereIn`. Everything after
     * that is `brick/money` arithmetic in PHP — no `SUM(amount)`, matching
     * the same rule `PaymentRecorder::outstanding()` follows for one invoice,
     * just accumulated across many. That still means reading every
     * outstanding invoice's row into PHP and running the money maths on each
     * one on every call; at a few hundred invoices that is comfortably sub-
     * millisecond, but a book of ten thousand outstanding invoices would read
     * ten thousand rows and run ten thousand `brick/money` operations per
     * call. The dashboard's own `Cache::flexible()` window absorbs repeat
     * calls; the book itself would need a materialised running total (a
     * ledger-style row updated by `PaymentRecorder`, not recomputed here) if
     * it ever grew that large — no such row exists today.
     */
    public function totals(): array
    {
        $book = Invoice::query()
            ->issued()
            ->whereNotIn('status', ['paid', 'void'])
            ->with(['payments:id,invoice_id,applied_amount'])
            ->get(['id', 'currency', 'total', 'due_on']);

        $outstanding = [];
        $overdue = [];

        foreach (Currencies::supported() as $code) {
            $outstanding[$code] = Money::zero($code);
            $overdue[$code] = Money::zero($code);
        }

        $today = now()->startOfDay();

        foreach ($book as $invoice) {
            $outstanding[$invoice->currency] ??= Money::zero($invoice->currency);
            $overdue[$invoice->currency] ??= Money::zero($invoice->currency);

            $paid = Money::sum(
                $invoice->payments->map(fn (Payment $payment): string => (string) $payment->applied_amount),
                $invoice->currency,
            );

            $owed = Money::fromStorage((string) $invoice->total, $invoice->currency)->minus($paid, Money::ROUNDING);
            $owed = $owed->isNegative() ? Money::zero($invoice->currency) : $owed;

            $outstanding[$invoice->currency] = $outstanding[$invoice->currency]->plus($owed, Money::ROUNDING);

            if ($invoice->due_on !== null && $invoice->due_on->lt($today)) {
                $overdue[$invoice->currency] = $overdue[$invoice->currency]->plus($owed, Money::ROUNDING);
            }
        }

        return [
            'outstanding' => array_map(
                fn (string $code): array => $this->money(Money::toStorage($outstanding[$code]), $code),
                array_keys($outstanding),
            ),
            'overdue' => array_map(
                fn (string $code): array => $this->money(Money::toStorage($overdue[$code]), $code),
                array_keys($overdue),
            ),
        ];
    }

    /** See the contract. One query, and no conversion performed anywhere in it. */
    public function revenueByMonth(int $months = 12): array
    {
        $window = self::monthWindow($months);

        $invoices = $this->issuedBetween(array_key_first($window), array_key_last($window))
            ->get(['id', 'currency', 'total', 'try_equivalent', 'issued_on']);

        $totals = array_map(fn (): BrickMoney => Money::zero(Currencies::TRY), $window);
        $counted = 0;
        $excluded = 0;

        foreach ($invoices as $invoice) {
            $key = $invoice->issued_on->format('Y-m');

            if (! array_key_exists($key, $totals)) {
                continue;
            }

            $lira = $this->lira($invoice);

            if ($lira === null) {
                $excluded++;

                continue;
            }

            $totals[$key] = $totals[$key]->plus($lira, Money::ROUNDING);
            $counted++;
        }

        return [
            'currency' => Currencies::TRY,
            'symbol' => Currencies::symbol(Currencies::TRY),
            'months' => array_values(array_map(fn (string $key): array => [
                'month' => $key,
                'label' => $window[$key],
                'amount' => Money::toStorage($totals[$key]),
                'formatted' => Money::format(Money::toStorage($totals[$key]), Currencies::TRY),
            ], array_keys($window))),
            'counted' => $counted,
            'excluded' => $excluded,
        ];
    }

    /** See the contract. */
    public function revenueByClient(int $months = 12, int $limit = 6): array
    {
        $limit = max(1, min(20, $limit));
        $window = self::monthWindow($months);

        $invoices = $this->issuedBetween(array_key_first($window), array_key_last($window))
            ->with('customer:id,name')
            ->get(['id', 'currency', 'total', 'try_equivalent', 'customer_id']);

        /** @var array<string, array{name: string, total: BrickMoney}> $byClient */
        $byClient = [];
        $counted = 0;
        $excluded = 0;

        foreach ($invoices as $invoice) {
            $lira = $this->lira($invoice);

            if ($lira === null) {
                $excluded++;

                continue;
            }

            // Keyed by id rather than by name so two clients who happen to
            // share a name stay two clients.
            $key = (string) ($invoice->customer_id ?? 0);

            $byClient[$key] ??= [
                'name' => $invoice->customer?->name ?? 'No client on the invoice',
                'total' => Money::zero(Currencies::TRY),
            ];

            $byClient[$key]['total'] = $byClient[$key]['total']->plus($lira, Money::ROUNDING);
            $counted++;
        }

        $rows = array_values($byClient);
        usort($rows, fn (array $a, array $b): int => $b['total']->compareTo($a['total']));

        $top = array_slice($rows, 0, $limit);
        $rest = array_slice($rows, $limit);

        $clients = array_map(fn (array $row): array => [
            'name' => $row['name'],
            'amount' => Money::toStorage($row['total']),
            'formatted' => Money::format(Money::toStorage($row['total']), Currencies::TRY),
            'is_other' => false,
        ], $top);

        if ($rest !== []) {
            $other = Money::sum(array_column($rest, 'total'), Currencies::TRY);

            $clients[] = [
                'name' => count($rest).' other '.(count($rest) === 1 ? 'client' : 'clients'),
                'amount' => Money::toStorage($other),
                'formatted' => Money::format(Money::toStorage($other), Currencies::TRY),
                'is_other' => true,
            ];
        }

        return [
            'currency' => Currencies::TRY,
            'symbol' => Currencies::symbol(Currencies::TRY),
            'clients' => $clients,
            'counted' => $counted,
            'excluded' => $excluded,
        ];
    }

    /** See the contract. Same two-query shape as `totals()`, bucketed rather than pooled. */
    public function agedReceivables(): array
    {
        $book = Invoice::query()
            ->issued()
            ->whereNotIn('status', ['paid', 'void'])
            ->with(['payments:id,invoice_id,applied_amount'])
            ->get(['id', 'currency', 'total', 'due_on']);

        $labels = [
            'not_due' => 'Not yet due',
            '1_30' => '1–30 days overdue',
            '31_60' => '31–60 days overdue',
            'over_60' => 'More than 60 days overdue',
        ];

        $totals = [];
        $counts = [];

        foreach (array_keys($labels) as $key) {
            $counts[$key] = 0;

            foreach (Currencies::supported() as $code) {
                $totals[$key][$code] = Money::zero($code);
            }
        }

        $today = now()->startOfDay();
        $count = 0;

        foreach ($book as $invoice) {
            $owed = $this->owed($invoice);

            // Nothing outstanding is nobody's problem — see the contract.
            if ($owed->isZero()) {
                continue;
            }

            $key = $this->bucketFor($invoice->due_on, $today);

            $totals[$key][$invoice->currency] ??= Money::zero($invoice->currency);
            $totals[$key][$invoice->currency] = $totals[$key][$invoice->currency]->plus($owed, Money::ROUNDING);
            $counts[$key]++;
            $count++;
        }

        return [
            'buckets' => array_values(array_map(fn (string $key): array => [
                'key' => $key,
                'label' => $labels[$key],
                'count' => $counts[$key],
                'totals' => array_map(
                    fn (string $code): array => $this->money(Money::toStorage($totals[$key][$code]), $code),
                    array_keys($totals[$key]),
                ),
            ], array_keys($labels))),
            'count' => $count,
        ];
    }

    /**
     * The trailing window, `['2025-09' => 'Sep 2025', …]`, oldest first.
     *
     * `ExpenseReader::monthWindow()` is the same three lines on purpose:
     * the two series are joined on this key by whoever draws them, so the two
     * readers have to agree about which months exist. Sharing it would mean a
     * new file in `Support`, which is a bigger change than the duplication is
     * worth — but change one and you must change the other, or a month falls
     * out of one series and the join silently shows an em dash.
     *
     * @return array<string, string>
     */
    public static function monthWindow(int $months): array
    {
        $months = max(1, min(60, $months));
        $start = now()->startOfMonth()->subMonths($months - 1);

        $window = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $window[$month->format('Y-m')] = $month->format('M Y');
        }

        return $window;
    }

    /** Issued, unvoided, dated inside `YYYY-MM` … `YYYY-MM` inclusive. */
    private function issuedBetween(string $firstMonth, string $lastMonth): Builder
    {
        return Invoice::query()
            ->issued()
            ->whereNotNull('issued_on')
            ->whereBetween('issued_on', [
                $firstMonth.'-01',
                Carbon::parse($lastMonth.'-01')->endOfMonth()->toDateString(),
            ]);
    }

    /**
     * What one invoice is worth in lira, or null when nothing on the document
     * says. Never derives a rate — see the contract's docblock.
     */
    private function lira(Invoice $invoice): ?BrickMoney
    {
        if ($invoice->currency === Currencies::TRY) {
            return Money::fromStorage((string) $invoice->total, Currencies::TRY);
        }

        return $invoice->try_equivalent === null
            ? null
            : Money::fromStorage((string) $invoice->try_equivalent, Currencies::TRY);
    }

    /** Total minus applied payments, never negative — the same figure `totals()` accumulates. */
    private function owed(Invoice $invoice): BrickMoney
    {
        $paid = Money::sum(
            $invoice->payments->map(fn (Payment $payment): string => (string) $payment->applied_amount),
            $invoice->currency,
        );

        $owed = Money::fromStorage((string) $invoice->total, $invoice->currency)->minus($paid, Money::ROUNDING);

        return $owed->isNegative() ? Money::zero($invoice->currency) : $owed;
    }

    private function bucketFor(?\DateTimeInterface $dueOn, \DateTimeInterface $today): string
    {
        if ($dueOn === null || $dueOn >= $today) {
            return 'not_due';
        }

        $days = (int) $dueOn->diff($today)->days;

        return match (true) {
            $days <= 30 => '1_30',
            $days <= 60 => '31_60',
            default => 'over_60',
        };
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
