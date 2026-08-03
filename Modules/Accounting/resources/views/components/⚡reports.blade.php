<?php

use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Services\PaymentRecorder;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * Where the money went and where it came from.
 *
 * A reports page is where `SUM(amount)` is most tempting and where it does the
 * most damage. On SQLite a decimal column has NUMERIC affinity, so a non-integer
 * is stored as an IEEE double and a SQL sum of money is approximate. **Every
 * figure on this page is fetched as rows and added in PHP through `Money`.**
 * Counting invoices in SQL is fine; adding them is not.
 *
 * Two rules the rest of the page follows from.
 *
 * **Every converted figure states the rate that produced it and when.** The
 * reporting totals are added from the figures each invoice and each expense
 * *froze* on its own date — never re-derived at today's rate, which would make
 * last March move every time the lira does. Rows that never got a rate are
 * counted out loud rather than converted with a number nobody could defend.
 *
 * **Unrealised revaluation is a report, not a row.** `PaymentRecorder::
 * unrealised()` restates the still-open part of an invoice at today's rate and
 * writes nothing, because nothing has happened yet. It is shown here with the
 * rate and the date beside it, labelled unrealised, and reading this page
 * leaves the ledger exactly as it found it.
 */
new
#[Title('Reports — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** One of 'month', 'quarter', 'ytd', 'all'. */
    #[Url]
    public string $period = 'ytd';

    private ?Collection $resolvedInvoices = null;

    private ?Collection $resolvedOpen = null;

    private ?Collection $resolvedExpenses = null;

    private ?Collection $resolvedPayments = null;

    /* The period ------------------------------------------------------------- */

    /** @return array{0: ?Carbon, 1: Carbon} */
    private function range(): array
    {
        return match ($this->period) {
            'month' => [now()->startOfMonth(), now()->endOfDay()],
            'quarter' => [now()->startOfQuarter(), now()->endOfDay()],
            'all' => [null, now()->endOfDay()],
            default => [now()->startOfYear(), now()->endOfDay()],
        };
    }

    private function periodLabel(): string
    {
        [$from, $to] = $this->range();

        return $from === null
            ? 'every invoice and expense on file'
            : $from->format('d M Y').' to '.$to->format('d M Y');
    }

    /* Reading ----------------------------------------------------------------- */

    /** Issued, unvoided invoices dated inside the period. */
    private function invoices(): Collection
    {
        if ($this->resolvedInvoices !== null) {
            return $this->resolvedInvoices;
        }

        [$from, $to] = $this->range();

        $query = Invoice::query()->issued()->with(['payments', 'customer']);

        if ($from !== null) {
            $query->whereDate('issued_on', '>=', $from->toDateString())
                ->whereDate('issued_on', '<=', $to->toDateString());
        }

        return $this->resolvedInvoices = $query->get();
    }

    /**
     * Everything still owed, whenever it was raised.
     *
     * Receivables are a position rather than a flow: what is owed today is owed
     * today whichever period the page is showing, so this one deliberately
     * ignores the filter and the page says so.
     */
    private function openInvoices(): Collection
    {
        return $this->resolvedOpen ??= Invoice::query()
            ->issued()
            ->whereNotIn('status', ['paid', 'void'])
            ->with('payments')
            ->orderBy('due_on')
            ->get()
            ->filter(fn (Invoice $invoice): bool => $this->outstandingOf($invoice)->isPositive())
            ->values();
    }

    private function expenses(): Collection
    {
        if ($this->resolvedExpenses !== null) {
            return $this->resolvedExpenses;
        }

        [$from, $to] = $this->range();

        $query = Expense::query();

        if ($from !== null) {
            $query->whereDate('spent_on', '>=', $from->toDateString())
                ->whereDate('spent_on', '<=', $to->toDateString());
        }

        return $this->resolvedExpenses = $query->get();
    }

    /** Money that actually landed inside the period. */
    private function payments(): Collection
    {
        if ($this->resolvedPayments !== null) {
            return $this->resolvedPayments;
        }

        [$from, $to] = $this->range();

        $query = Payment::query()->with('invoice');

        if ($from !== null) {
            $query->whereBetween('paid_at', [$from, $to]);
        }

        return $this->resolvedPayments = $query->get()
            ->filter(fn (Payment $payment): bool => $payment->invoice !== null && ! $payment->invoice->isVoid())
            ->values();
    }

    /* Money -------------------------------------------------------------------- */

    /** What is still owed on one invoice, in its own currency. The rule `PaymentRecorder` states. */
    private function outstandingOf(Invoice $invoice): BrickMoney
    {
        $paid = Money::sum(
            $invoice->payments->map(fn (Payment $payment): string => (string) $payment->applied_amount),
            $invoice->currency,
        );

        $owed = Money::fromStorage((string) $invoice->total, $invoice->currency)->minus($paid, Money::ROUNDING);

        return $owed->isNegative() ? Money::zero($invoice->currency) : $owed;
    }

    /**
     * Add a frozen reporting figure across rows.
     *
     * Only rows that carry one, and only in the reporting currency. A row with
     * no rate is left out and counted, because converting it here would mean
     * inventing a rate for a date that has already passed.
     *
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $rows
     * @return array{0: BrickMoney, 1: int}
     */
    private function reported(Collection $rows): array
    {
        $converted = $rows->filter(
            fn ($row): bool => $row->reporting_amount !== null && $row->reporting_currency === Currencies::USD,
        );

        return [
            Money::sum($converted->map(fn ($row): string => (string) $row->reporting_amount), Currencies::USD),
            $rows->count() - $converted->count(),
        ];
    }

    /**
     * What is outstanding on an invoice, at the rate the invoice itself froze.
     *
     * Not today's rate: this is the receivable as the books recorded it. What
     * it would be worth at today's rate is the unrealised section further down,
     * which is a different question and is labelled as one.
     */
    private function outstandingReported(Invoice $invoice): ?BrickMoney
    {
        if ($invoice->reporting_rate === null || $invoice->reporting_currency !== Currencies::USD) {
            return null;
        }

        return Money::convert($this->outstandingOf($invoice), (string) $invoice->reporting_rate, Currencies::USD);
    }

    private function usd(BrickMoney $money): string
    {
        return Money::format(Money::toStorage($money), Currencies::USD);
    }

    /** A bar width as a whole percentage — a ratio of two amounts, not an amount. */
    private function share(BrickMoney $part, BrickMoney $peak): int
    {
        if ($peak->isZero() || $part->isNegativeOrZero()) {
            return 0;
        }

        return $part->getAmount()
            ->dividedBy($peak->getAmount(), 4, RoundingMode::HalfUp)
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();
    }

    /* Sections ------------------------------------------------------------------ */

    /**
     * Receivables by how late they are, in the reporting currency.
     *
     * @return list<array{label: string, count: int, total: string, tone: string}>
     */
    private function ageing(): array
    {
        $buckets = [
            'current' => ['label' => 'Not due yet', 'tone' => 'text-mono', 'rows' => []],
            'thirty' => ['label' => '1 to 30 days late', 'tone' => 'text-warning', 'rows' => []],
            'sixty' => ['label' => '31 to 60 days late', 'tone' => 'text-warning', 'rows' => []],
            'ninety' => ['label' => 'Over 60 days late', 'tone' => 'text-destructive', 'rows' => []],
        ];

        foreach ($this->openInvoices() as $invoice) {
            $buckets[$this->bucketFor($invoice)]['rows'][] = $invoice;
        }

        return array_values(array_map(function (array $bucket): array {
            $rows = collect($bucket['rows']);

            $total = Money::sum(
                $rows->map(fn (Invoice $invoice): ?BrickMoney => $this->outstandingReported($invoice))->filter(),
                Currencies::USD,
            );

            return [
                'label' => $bucket['label'],
                'count' => $rows->count(),
                'total' => $this->usd($total),
                'tone' => $bucket['tone'],
            ];
        }, $buckets));
    }

    private function bucketFor(Invoice $invoice): string
    {
        if ($invoice->due_on === null || ! $invoice->due_on->isBefore(now()->startOfDay())) {
            return 'current';
        }

        $late = (int) $invoice->due_on->startOfDay()->diffInDays(now()->startOfDay());

        return match (true) {
            $late <= 30 => 'thirty',
            $late <= 60 => 'sixty',
            default => 'ninety',
        };
    }

    /**
     * One row per currency, each figure in that currency and converted nowhere.
     *
     * @return list<array{currency: string, count: int, invoiced: string, received: string, outstanding: string}>
     */
    private function byCurrency(): array
    {
        $currencies = $this->invoices()->pluck('currency')
            ->merge($this->payments()->map(fn (Payment $payment): string => $payment->invoice->currency))
            ->merge($this->openInvoices()->pluck('currency'))
            ->unique()
            ->values();

        return $currencies->map(function (string $currency): array {
            $invoices = $this->invoices()->where('currency', $currency);
            $received = $this->payments()->filter(
                fn (Payment $payment): bool => $payment->invoice->currency === $currency,
            );
            $open = $this->openInvoices()->where('currency', $currency);

            return [
                'currency' => $currency,
                'count' => $invoices->count(),
                'invoiced' => Money::format(
                    Money::toStorage(Money::sum($invoices->map(fn (Invoice $i): string => (string) $i->total), $currency)),
                    $currency,
                ),
                'received' => Money::format(
                    Money::toStorage(Money::sum($received->map(fn (Payment $p): string => (string) $p->applied_amount), $currency)),
                    $currency,
                ),
                'outstanding' => Money::format(
                    Money::toStorage(Money::sum($open->map(fn (Invoice $i): BrickMoney => $this->outstandingOf($i)), $currency)),
                    $currency,
                ),
            ];
        })->all();
    }

    /**
     * Realised foreign-exchange gain and loss, from the payments themselves.
     *
     * Stored in the invoice's own currency at the moment the money landed, so
     * it is totalled per currency and converted nowhere.
     *
     * @return list<array{currency: string, total: string, count: int}>
     */
    private function realisedFx(): array
    {
        return $this->payments()
            ->filter(fn (Payment $payment): bool => (string) $payment->fx_gain_loss !== '0.000000')
            ->groupBy(fn (Payment $payment): string => $payment->invoice->currency)
            ->map(fn (Collection $group, string $currency): array => [
                'currency' => $currency,
                'count' => $group->count(),
                'total' => Money::format(
                    Money::toStorage(Money::sum($group->map(fn (Payment $p): string => (string) $p->fx_gain_loss), $currency)),
                    $currency,
                ),
            ])
            ->values()
            ->all();
    }

    /**
     * The unrealised revaluation of every open invoice raised in another currency.
     *
     * Computed by `PaymentRecorder::unrealised()`, which writes nothing. An
     * invoice already in the reporting currency has nothing to revalue and is
     * counted rather than listed with a row of zeroes.
     *
     * @return array{rows: list<array<string, ?string>>, sameCurrency: int, on: string}
     */
    private function unrealised(): array
    {
        $recorder = app(PaymentRecorder::class);
        $exposed = $this->openInvoices()->filter(
            fn (Invoice $invoice): bool => $invoice->currency !== ($invoice->reporting_currency ?? Currencies::USD),
        );

        return [
            'rows' => $exposed->map(function (Invoice $invoice) use ($recorder): array {
                $revaluation = $recorder->unrealised($invoice);
                $outstanding = $this->outstandingOf($invoice);
                $reporting = $invoice->reporting_currency ?? Currencies::USD;

                return [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'currency' => $invoice->currency,
                    'outstanding' => Money::format(Money::toStorage($outstanding), $invoice->currency),
                    'issueRate' => $invoice->reporting_rate === null ? null : (string) $invoice->reporting_rate,
                    'issuedOn' => $invoice->issued_on?->format('d M Y'),
                    'atIssue' => $invoice->reporting_rate === null
                        ? null
                        : Money::format(
                            Money::toStorage(Money::convert($outstanding, (string) $invoice->reporting_rate, $reporting)),
                            $reporting,
                        ),
                    'todayRate' => $revaluation['rate'],
                    'atToday' => $revaluation['at_today'] === null
                        ? null
                        : Money::format($revaluation['at_today'], $reporting),
                    'difference' => $revaluation['difference'] === null
                        ? null
                        : Money::format($revaluation['difference'], $reporting),
                    'isGain' => $revaluation['difference'] !== null
                        && ! str_starts_with($revaluation['difference'], '-'),
                ];
            })->values()->all(),
            'sameCurrency' => $this->openInvoices()->count() - $exposed->count(),
            'on' => now()->format('d M Y'),
        ];
    }

    /**
     * Twelve months of invoiced revenue against expenses, both in the reporting currency.
     *
     * @return list<array{month: string, invoiced: string, spent: string, invoicedHeight: int, spentHeight: int}>
     */
    private function trend(): array
    {
        $invoices = Invoice::query()->issued()->get();
        $expenses = Expense::query()->get();

        $months = [];

        for ($back = 11; $back >= 0; $back--) {
            $start = now()->startOfMonth()->subMonths($back);

            [$invoiced] = $this->reported($invoices->filter(
                fn (Invoice $invoice): bool => $invoice->issued_on !== null && $invoice->issued_on->isSameMonth($start),
            ));

            [$spent] = $this->reported($expenses->filter(
                fn (Expense $expense): bool => $expense->spent_on->isSameMonth($start),
            ));

            $months[] = ['month' => $start->format('M'), 'invoiced' => $invoiced, 'spent' => $spent];
        }

        $peak = Money::zero(Currencies::USD);

        foreach ($months as $month) {
            foreach ([$month['invoiced'], $month['spent']] as $figure) {
                if ($figure->isGreaterThan($peak)) {
                    $peak = $figure;
                }
            }
        }

        return array_map(fn (array $month): array => [
            'month' => $month['month'],
            'invoiced' => $this->usd($month['invoiced']),
            'spent' => $this->usd($month['spent']),
            'invoicedHeight' => $this->share($month['invoiced'], $peak),
            'spentHeight' => $this->share($month['spent'], $peak),
        ], $months);
    }

    /**
     * Who the money came from, in the reporting currency.
     *
     * @return list<array{name: string, total: string, count: int, width: int}>
     */
    private function topClients(): array
    {
        $groups = $this->invoices()
            ->groupBy(fn (Invoice $invoice): string => $invoice->customer?->name ?? 'No client on the invoice')
            ->map(fn (Collection $group): array => ['total' => $this->reported($group)[0], 'count' => $group->count()])
            ->sort(fn (array $a, array $b): int => $b['total']->getAmount()->compareTo($a['total']->getAmount()))
            ->take(5);

        $peak = $groups->isEmpty() ? Money::zero(Currencies::USD) : $groups->first()['total'];

        return $groups->map(fn (array $entry, string $name): array => [
            'name' => $name,
            'count' => $entry['count'],
            'total' => $this->usd($entry['total']),
            'width' => $this->share($entry['total'], $peak),
        ])->values()->all();
    }

    /* View ----------------------------------------------------------------------- */

    public function with(): array
    {
        [$invoiced, $invoicesUnconverted] = $this->reported($this->invoices());
        [$spent, $expensesUnconverted] = $this->reported($this->expenses());

        $net = $invoiced->minus($spent, Money::ROUNDING);

        $receivable = Money::sum(
            $this->openInvoices()
                ->map(fn (Invoice $invoice): ?BrickMoney => $this->outstandingReported($invoice))
                ->filter(),
            Currencies::USD,
        );

        $receivableUnconverted = $this->openInvoices()->filter(
            fn (Invoice $invoice): bool => $this->outstandingReported($invoice) === null,
        )->count();

        return [
            'periods' => [
                'month' => 'This month',
                'quarter' => 'This quarter',
                'ytd' => 'Year to date',
                'all' => 'All time',
            ],
            'periodLabel' => $this->periodLabel(),

            'kpis' => [
                [
                    'label' => 'Invoiced',
                    'value' => $this->usd($invoiced),
                    'icon' => 'ki-bill',
                    'tone' => 'text-primary',
                    'note' => $this->conversionNote($invoicesUnconverted, 'invoice'),
                ],
                [
                    'label' => 'Expenses',
                    'value' => $this->usd($spent),
                    'icon' => 'ki-wallet',
                    'tone' => 'text-destructive',
                    'note' => $this->conversionNote($expensesUnconverted, 'expense'),
                ],
                [
                    'label' => 'Invoiced less expenses',
                    'value' => $this->usd($net),
                    'icon' => 'ki-chart-line-up',
                    'tone' => $net->isNegative() ? 'text-destructive' : 'text-success',
                    'note' => 'What was billed in the period, less what it cost to run.',
                ],
                [
                    'label' => 'Outstanding today',
                    'value' => $this->usd($receivable),
                    'icon' => 'ki-time',
                    'tone' => 'text-warning',
                    'note' => $receivableUnconverted === 0
                        ? 'Every period, at the rate each invoice froze when it was issued.'
                        : 'Every period, at each invoice\'s frozen rate. '.$receivableUnconverted.' could not be converted.',
                ],
            ],

            'ageing' => $this->ageing(),
            'byCurrency' => $this->byCurrency(),
            'realisedFx' => $this->realisedFx(),
            'unrealised' => $this->unrealised(),
            'trend' => $this->trend(),
            'topClients' => $this->topClients(),

            'invoiceCount' => $this->invoices()->count(),
            'expenseCount' => $this->expenses()->count(),
            'openCount' => $this->openInvoices()->count(),
        ];
    }

    private function conversionNote(int $unconverted, string $noun): string
    {
        $base = 'Added in USD from the rate each '.$noun.' froze on its own date.';

        return $unconverted === 0
            ? $base
            : $base.' '.$unconverted.' '.($unconverted === 1 ? $noun.' has' : $noun.'s have').' no rate and '
                .($unconverted === 1 ? 'is' : 'are').' left out.';
    }

    public function updatedPeriod(): void
    {
        $this->resolvedInvoices = null;
        $this->resolvedExpenses = null;
        $this->resolvedPayments = null;
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Reports</h1>
            <p class="text-sm text-secondary-foreground mt-1">Where the money went and where it came from.</p>
        </div>
        <div class="flex items-center gap-2">
            <select class="kt-select max-w-[180px]" aria-label="Period" wire:model.live="period">
                @foreach ($periods as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <p class="text-xs text-muted-foreground -mt-2">
        Covering {{ $periodLabel }} — {{ $invoiceCount }} issued {{ $invoiceCount === 1 ? 'invoice' : 'invoices' }},
        {{ $expenseCount }} {{ $expenseCount === 1 ? 'expense' : 'expenses' }}. Every total is added in PHP from
        decimal strings, never summed in SQL.
    </p>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        @foreach ($kpis as $k)
            <div class="kt-card">
                <div class="kt-card-content p-5">
                    <div class="flex items-center gap-2 text-sm text-secondary-foreground">
                        <i class="ki-filled {{ $k['icon'] }} {{ $k['tone'] }}"></i>
                        {{ $k['label'] }}
                    </div>
                    <div class="text-2xl font-semibold text-mono mt-2">{{ $k['value'] }}</div>
                    <p class="text-xs text-muted-foreground mt-1">{{ $k['note'] }}</p>
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">

        {{-- Invoiced against spent, twelve months, both from frozen figures. --}}
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Invoiced and spent</h3>
                <span class="text-sm text-muted-foreground">Last twelve months, in USD</span>
            </div>
            <div class="kt-card-content p-5">
                <div class="flex items-end justify-between gap-2 h-[220px]">
                    @foreach ($trend as $m)
                        <div class="flex flex-col items-center gap-2 grow min-w-0">
                            <div class="flex items-end justify-center gap-0.5 w-full grow">
                                <div class="w-1/2 rounded-t bg-primary/70 min-h-[2px]"
                                     style="height: {{ max($m['invoicedHeight'], 1) }}%"
                                     title="Invoiced {{ $m['month'] }} — {{ $m['invoiced'] }}"></div>
                                <div class="w-1/2 rounded-t bg-destructive/60 min-h-[2px]"
                                     style="height: {{ max($m['spentHeight'], 1) }}%"
                                     title="Spent {{ $m['month'] }} — {{ $m['spent'] }}"></div>
                            </div>
                            <span class="text-[10px] text-muted-foreground">{{ $m['month'] }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="flex items-center gap-4 mt-4 text-xs text-muted-foreground">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="size-2.5 rounded-sm bg-primary/70"></span> Invoiced
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="size-2.5 rounded-sm bg-destructive/60"></span> Spent
                    </span>
                </div>
            </div>
        </div>

        {{-- Top clients --}}
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Where the revenue came from</h3>
                <span class="text-sm text-muted-foreground">In USD, as reported</span>
            </div>
            <div class="kt-card-content p-5 flex flex-col gap-4">
                @forelse ($topClients as $client)
                    <div class="flex flex-col gap-1.5" wire:key="client-{{ $loop->index }}">
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="text-secondary-foreground truncate">{{ $client['name'] }}</span>
                            <span class="text-mono font-medium shrink-0">{{ $client['total'] }}</span>
                        </div>
                        <div class="h-1.5 rounded-full bg-muted overflow-hidden">
                            <div class="h-full rounded-full bg-primary/70" style="width: {{ $client['width'] }}%"></div>
                        </div>
                        <span class="text-xs text-muted-foreground">
                            {{ $client['count'] }} {{ $client['count'] === 1 ? 'invoice' : 'invoices' }}
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-muted-foreground text-center py-10">
                        Nothing was invoiced in this period.
                    </p>
                @endforelse
            </div>
        </div>

    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">

        {{-- Ageing --}}
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Receivables by age</h3>
                <span class="text-sm text-muted-foreground">
                    {{ $openCount }} open {{ $openCount === 1 ? 'invoice' : 'invoices' }}
                </span>
            </div>
            <div class="kt-card-table">
                <div class="kt-scrollable-x-auto">
                    <table class="kt-table align-middle text-sm">
                        <thead>
                            <tr>
                                <th>Age</th>
                                <th class="w-[100px] text-end">Invoices</th>
                                <th class="w-[150px] text-end">Outstanding</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($ageing as $bucket)
                                <tr>
                                    <td class="{{ $bucket['tone'] }}">{{ $bucket['label'] }}</td>
                                    <td class="text-end text-secondary-foreground">{{ $bucket['count'] }}</td>
                                    <td class="text-end font-medium text-mono">{{ $bucket['total'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="kt-card-footer">
                <p class="text-xs text-muted-foreground">
                    Every period, not just the one selected — what is owed today is owed today. Converted at the
                    rate each invoice froze when it was issued.
                </p>
            </div>
        </div>

        {{-- Per currency --}}
        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">By currency</h3>
                <span class="text-sm text-muted-foreground">Converted nowhere</span>
            </div>
            <div class="kt-card-table">
                <div class="kt-scrollable-x-auto">
                    <table class="kt-table align-middle text-sm">
                        <thead>
                            <tr>
                                <th class="w-[90px]">Currency</th>
                                <th class="w-[80px] text-end">Invoices</th>
                                <th class="text-end">Invoiced</th>
                                <th class="text-end">Received</th>
                                <th class="text-end">Outstanding</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($byCurrency as $row)
                                <tr wire:key="cur-{{ $row['currency'] }}">
                                    <td class="font-medium text-mono">{{ $row['currency'] }}</td>
                                    <td class="text-end text-secondary-foreground">{{ $row['count'] }}</td>
                                    <td class="text-end text-mono">{{ $row['invoiced'] }}</td>
                                    <td class="text-end text-mono">{{ $row['received'] }}</td>
                                    <td class="text-end text-mono">{{ $row['outstanding'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-10 text-center text-sm text-muted-foreground">
                                        Nothing invoiced or received in this period.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="kt-card-footer">
                <p class="text-xs text-muted-foreground">
                    Each currency is added on its own. Received counts payments dated inside the period; outstanding
                    is every open invoice, whenever it was raised.
                </p>
            </div>
        </div>

    </div>

    {{-- Unrealised revaluation. A report, and only a report. --}}
    <div class="kt-card">
        <div class="kt-card-header">
            <h3 class="kt-card-title">Unrealised revaluation</h3>
            <span class="text-sm text-muted-foreground">As at {{ $unrealised['on'] }}</span>
        </div>
        <div class="kt-card-table">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table align-middle text-sm">
                    <thead>
                        <tr>
                            <th class="w-[120px]">Invoice</th>
                            <th class="w-[140px] text-end">Outstanding</th>
                            <th class="text-end">At the issue rate</th>
                            <th class="text-end">At today's rate</th>
                            <th class="w-[140px] text-end">Difference</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($unrealised['rows'] as $row)
                            <tr wire:key="reval-{{ $row['id'] }}">
                                <td>
                                    <a href="{{ route('accounting.invoice-show', ['invoice' => $row['id']]) }}" wire:navigate
                                       class="font-medium text-mono hover:text-primary">{{ $row['number'] }}</a>
                                    <div class="text-xs text-muted-foreground">{{ $row['currency'] }}</div>
                                </td>
                                <td class="text-end font-medium text-mono">{{ $row['outstanding'] }}</td>
                                <td class="text-end">
                                    <div class="text-mono">{{ $row['atIssue'] ?? '—' }}</div>
                                    @if ($row['issueRate'])
                                        <div class="text-xs text-muted-foreground">
                                            {{ $row['issueRate'] }} on {{ $row['issuedOn'] }}
                                        </div>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <div class="text-mono">{{ $row['atToday'] ?? '—' }}</div>
                                    @if ($row['todayRate'])
                                        <div class="text-xs text-muted-foreground">
                                            {{ $row['todayRate'] }} on {{ $unrealised['on'] }}
                                        </div>
                                    @else
                                        <div class="text-xs text-muted-foreground">No rate stored for today</div>
                                    @endif
                                </td>
                                <td class="text-end font-medium {{ $row['isGain'] ? 'text-success' : 'text-destructive' }}">
                                    {{ $row['difference'] ?? '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-10 text-center">
                                    <i class="ki-filled ki-financial-schedule text-2xl text-muted-foreground"></i>
                                    <p class="text-sm text-muted-foreground mt-2">
                                        Nothing open in a currency other than the reporting one, so there is nothing to revalue.
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="kt-card-footer">
            <p class="text-xs text-muted-foreground">
                Unrealised. Nothing has happened yet, so nothing is written — this restates what is still open at
                today's rate and stores none of it. Only a payment realises a gain or a loss.
                @if ($unrealised['sameCurrency'] > 0)
                    {{ $unrealised['sameCurrency'] }} open
                    {{ $unrealised['sameCurrency'] === 1 ? 'invoice is' : 'invoices are' }} already in the reporting
                    currency and {{ $unrealised['sameCurrency'] === 1 ? 'has' : 'have' }} nothing to revalue.
                @endif
            </p>
        </div>
    </div>

    {{-- Realised FX --}}
    <div class="kt-card">
        <div class="kt-card-header">
            <h3 class="kt-card-title">Realised foreign exchange</h3>
            <span class="text-sm text-muted-foreground">Payments dated inside the period</span>
        </div>
        <div class="kt-card-content p-5">
            @if ($realisedFx === [])
                <p class="text-sm text-muted-foreground text-center py-6">
                    No payment in this period settled at a different rate from the one its invoice was issued at.
                </p>
            @else
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                    @foreach ($realisedFx as $entry)
                        <div class="rounded-lg border border-border p-4" wire:key="fx-{{ $entry['currency'] }}">
                            <div class="text-xs text-muted-foreground">{{ $entry['currency'] }} invoices</div>
                            <div class="text-lg font-semibold text-mono mt-1">{{ $entry['total'] }}</div>
                            <div class="text-xs text-muted-foreground mt-1">
                                across {{ $entry['count'] }} {{ $entry['count'] === 1 ? 'payment' : 'payments' }}
                            </div>
                        </div>
                    @endforeach
                </div>
                <p class="text-xs text-muted-foreground mt-4">
                    Realised the moment the money landed, in the invoice's own currency, and never recomputed
                    afterwards.
                </p>
            @endif
        </div>
    </div>
</div>
