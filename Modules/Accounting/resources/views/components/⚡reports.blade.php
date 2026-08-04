<?php

use Brick\Math\BigDecimal;
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
 *
 * ---
 *
 * Three things the later sections add, and the judgement behind each.
 *
 * 🔴 **The profit and loss is on a cash basis, and the page says so on the
 * card.** A Turkish self-employed professional issues the serbest meslek
 * makbuzu at the moment payment is *collected*, not when the work is delivered
 * or the invoice sent, and geçici vergi is assessed on earnings — so money that
 * actually landed is the basis that matches what eventually gets filed. The
 * accrual answer is not hidden: "Invoiced" in the cards above is exactly that,
 * and the P&L card names it as the other question rather than letting anybody
 * read one total as the other. Choose wrong and every figure on the report
 * moves, which is why the choice is written on the page and not only here.
 *
 * 🔴 **Lira figures come from what each document froze, by whichever route it
 * froze one.** An invoice has a lira figure if it was raised in lira, or if it
 * was reported in lira, or if the buyer was a domestic Turkish company — in
 * which case `InvoiceIssuer` also froze the TCMB buying rate and the lira
 * equivalent tax procedure requires. An invoice that took none of those routes
 * has no lira figure and is **counted out loud rather than converted**, because
 * converting last March at today's rate makes last March move every time the
 * lira does. Kargah's main case is a foreign client, so expect that count to be
 * large: the honest gap is the point, not a defect.
 *
 * 🔴 **Nothing in the tax section is advice, and every rate in it is
 * configuration.** Turkish income-tax brackets are revalued annually, so the
 * geçici vergi rate and threshold live in `config/accounting.php` with an
 * `env()` default, and the page prints the rate, the year it belongs to, and
 * the instruction to confirm it. Where the research could not establish a rule
 * — whether Art. 94 stopaj reaches a *foreign* payer, which is Kargah's usual
 * case — the page prints the open question instead of a number. A confidently
 * wrong tax figure is a liability; an honest gap is useful.
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

        // `company` joins the eager load for the stopaj section: whether the
        // buyer is a domestic Turkish company is the one thing that decides
        // whether withholding is a sourced fact or an open question.
        $query = Invoice::query()->issued()->with(['payments', 'customer', 'company']);

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
            // `customer` because an ageing report nobody can put a name to is a
            // report nobody can act on.
            ->with(['payments', 'customer'])
            ->orderBy('due_on')
            ->get()
            ->filter(fn (Invoice $invoice): bool => $this->outstandingOf($invoice)->isPositive())
            ->values();
    }

    private function expenses(): Collection
    {
        return $this->resolvedExpenses ??= $this->expensesBetween(...$this->range());
    }

    /** Money that actually landed inside the period. */
    private function payments(): Collection
    {
        return $this->resolvedPayments ??= $this->paymentsBetween(...$this->range());
    }

    /**
     * The same two reads, over any window.
     *
     * The profit and loss compares a period against the one before it, so the
     * window has to be an argument rather than the page's own filter. They are
     * split out instead of duplicated so the void-invoice rule below cannot
     * drift between the two callers — a payment against a voided invoice is not
     * income, and a comparison that forgot that would flatter one period.
     */
    private function expensesBetween(?Carbon $from, Carbon $to): Collection
    {
        $query = Expense::query();

        if ($from !== null) {
            $query->whereDate('spent_on', '>=', $from->toDateString())
                ->whereDate('spent_on', '<=', $to->toDateString());
        }

        return $query->get();
    }

    private function paymentsBetween(?Carbon $from, Carbon $to): Collection
    {
        $query = Payment::query()->with('invoice');

        if ($from !== null) {
            $query->whereBetween('paid_at', [$from, $to]);
        }

        return $query->get()
            ->filter(fn (Payment $payment): bool => $payment->invoice !== null && ! $payment->invoice->isVoid())
            ->values();
    }

    /**
     * The period immediately before the one being shown, of the same shape.
     *
     * Built by stepping back from the start of the current window rather than
     * subtracting a fixed number of days: "last month" is 28, 30 or 31 days and
     * a comparison that used 30 for all of them would report a February that
     * never happened. "All time" has no previous period and says so instead of
     * inventing one.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function previousRange(): ?array
    {
        return match ($this->period) {
            'month' => [
                now()->startOfMonth()->subMonthNoOverflow(),
                now()->startOfMonth()->subDay()->endOfDay(),
            ],
            'quarter' => [
                now()->startOfQuarter()->subMonthsNoOverflow(3),
                now()->startOfQuarter()->subDay()->endOfDay(),
            ],
            'all' => null,
            // Year to date compares against the same elapsed days last year,
            // not against the whole of last year — otherwise every January
            // reports a collapse.
            default => [now()->startOfYear()->subYearNoOverflow(), now()->subYearNoOverflow()->endOfDay()],
        };
    }

    private function previousLabel(): ?string
    {
        return match ($this->period) {
            'month' => 'last month',
            'quarter' => 'the previous quarter',
            'all' => null,
            default => 'the same days last year',
        };
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

    /* Lira ----------------------------------------------------------------------- */

    private function lira(BrickMoney $money): string
    {
        return Money::format(Money::toStorage($money), Currencies::TRY);
    }

    /**
     * The lira figure an invoice froze at issue, and where it came from.
     *
     * TRY is what the owner actually files in, and an invoice can have frozen a
     * lira figure by any of three routes:
     *
     *  1. it was raised in lira, so the total already is the figure;
     *  2. the reporting currency was lira at issue, so `reporting_amount` is;
     *  3. the buyer was a domestic Turkish company, so `InvoiceIssuer` froze
     *     the TCMB buying rate and `try_equivalent` alongside — the figure
     *     Turkish tax procedure requires the document to carry.
     *
     * An invoice that took none of them has **no** lira figure, and this
     * returns null rather than reaching for a rate. Kargah's usual invoice is
     * USD to a foreign client, which is exactly that case, so the sections
     * below all carry a count of what they had to leave out.
     *
     * @return array{amount: BrickMoney, rate: string, source: string, on: ?string}|null
     */
    private function liraOf(Invoice $invoice): ?array
    {
        if ($invoice->currency === Currencies::TRY) {
            return [
                'amount' => Money::fromStorage((string) $invoice->total, Currencies::TRY),
                'rate' => '1.000000',
                'source' => 'raised in lira',
                'on' => $invoice->issued_on?->format('d M Y'),
            ];
        }

        if ($invoice->reporting_currency === Currencies::TRY && $invoice->reporting_amount !== null) {
            return [
                'amount' => Money::fromStorage((string) $invoice->reporting_amount, Currencies::TRY),
                'rate' => (string) $invoice->reporting_rate,
                'source' => 'the reporting rate frozen at issue',
                'on' => $invoice->issued_on?->format('d M Y'),
            ];
        }

        if ($invoice->try_equivalent !== null) {
            return [
                'amount' => Money::fromStorage((string) $invoice->try_equivalent, Currencies::TRY),
                'rate' => (string) $invoice->issue_rate_to_try,
                'source' => $invoice->issue_rate_source ?? 'the rate frozen at issue',
                'on' => $invoice->issue_rate_date?->format('d M Y'),
            ];
        }

        return null;
    }

    /**
     * Part of an invoice — what is still open, what was collected, the KDV on
     * it — expressed in lira as a *share* of the figure the invoice froze.
     *
     * 🔴 A share, and deliberately not `part × issue_rate_to_try`. For a USDT
     * invoice the frozen lira equivalent was computed through a USD bridge —
     * TCMB USD/TRY multiplied by USDT/USD — so `issue_rate_to_try` on its own
     * is the lira price of a *dollar*, not of a tether, and multiplying by it
     * would overstate the figure by the whole bridge. Taking the same
     * proportion of the lira total that the part is of the invoice total is
     * exact whichever of the three routes froze the figure, and it can never
     * disagree with the number printed on the document itself.
     */
    private function shareInLira(Invoice $invoice, BrickMoney $part): ?BrickMoney
    {
        $frozen = $this->liraOf($invoice);
        $total = BigDecimal::of((string) $invoice->total);

        if ($frozen === null || $total->isZero()) {
            return null;
        }

        return Money::of(
            (string) $frozen['amount']->getAmount()
                ->multipliedBy(BigDecimal::of($part->getAmount())->dividedBy($total, 12, RoundingMode::HalfUp))
                ->toScale(Currencies::STORAGE_SCALE, RoundingMode::HalfUp),
            Currencies::TRY,
        );
    }

    /**
     * What an expense cost in lira, from what it froze, or nothing.
     *
     * Same rule as an invoice and for the same reason. Most of the owner's
     * costs are incurred in Turkey and are already in lira, so this one usually
     * finds a figure where the invoice side usually does not.
     *
     * @return array{amount: BrickMoney, source: string}|null
     */
    private function liraOfExpense(Expense $expense): ?array
    {
        if ($expense->currency === Currencies::TRY) {
            return [
                'amount' => Money::fromStorage((string) $expense->amount, Currencies::TRY),
                'source' => 'spent in lira',
            ];
        }

        if ($expense->reporting_currency === Currencies::TRY && $expense->reporting_amount !== null) {
            return [
                'amount' => Money::fromStorage((string) $expense->reporting_amount, Currencies::TRY),
                'source' => 'the rate frozen when it was recorded',
            ];
        }

        return null;
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

    /* Aged receivables ------------------------------------------------------------ */

    /**
     * The five buckets, in order.
     *
     * Five rather than the four the summary card above uses, because "over 60"
     * is where a receivable stops being late and starts being a problem, and a
     * bucket that lumps a 61-day-old invoice in with a 400-day-old one hides
     * the only one worth a phone call.
     */
    private const AGE_BUCKETS = [
        ['key' => 'current', 'label' => 'Not due yet', 'tone' => 'text-secondary-foreground'],
        ['key' => 'd30', 'label' => '1 to 30 days', 'tone' => 'text-warning'],
        ['key' => 'd60', 'label' => '31 to 60 days', 'tone' => 'text-warning'],
        ['key' => 'd90', 'label' => '61 to 90 days', 'tone' => 'text-destructive'],
        ['key' => 'over', 'label' => 'Over 90 days', 'tone' => 'text-destructive'],
    ];

    /** Whole days past `due_on`, or null when the invoice is not late at all. */
    private function daysLate(Invoice $invoice): ?int
    {
        if ($invoice->due_on === null) {
            return null;
        }

        $due = $invoice->due_on->copy()->startOfDay();
        $today = now()->startOfDay();

        return $due->isBefore($today) ? (int) $due->diffInDays($today) : null;
    }

    /**
     * 🔴 The boundaries, spelled out, because off by one here is the classic defect.
     *
     * Days are counted midnight to midnight, so an invoice due yesterday is one
     * day late and an invoice due **today is not late at all** — money is not
     * overdue until the day after the day it was promised. The bands are
     * therefore inclusive on both ends: 1–30, 31–60, 61–90, and 91 upwards in
     * the last one. An invoice with no due date has no age and sits in
     * "not due yet" rather than being called overdue on a date nobody set.
     */
    private function ageBucket(?int $daysLate): string
    {
        return match (true) {
            $daysLate === null => 'current',
            $daysLate <= 30 => 'd30',
            $daysLate <= 60 => 'd60',
            $daysLate <= 90 => 'd90',
            default => 'over',
        };
    }

    /** @return array{label: string, tone: string} */
    private function bucketMeta(string $key): array
    {
        foreach (self::AGE_BUCKETS as $bucket) {
            if ($bucket['key'] === $key) {
                return ['label' => $bucket['label'], 'tone' => $bucket['tone']];
            }
        }

        return ['label' => $key, 'tone' => 'text-mono'];
    }

    /**
     * Who owes what, and for how long — the report the "outstanding" figure
     * above cannot answer.
     *
     * 🔴 One row per currency and **never a mixed total**. A client billed in
     * dollars and in lira is two figures; adding them needs a rate, and a rate
     * needs a date and a source before anybody can argue with the result. The
     * lira row underneath is a separate line built only from invoices that
     * froze a lira figure, and what it had to leave out is printed beside it.
     *
     * Receivables are a position rather than a flow, so like the summary card
     * this ignores the period filter: what is owed today is owed today.
     *
     * @return array{
     *     buckets: list<array{key: string, label: string, tone: string}>,
     *     currencies: list<array{currency: string, cells: list<array{count: int, total: string}>, total: string}>,
     *     lira: list<array{count: int, total: string}>,
     *     liraTotal: string,
     *     unconverted: int,
     * }
     */
    private function agedReceivables(): array
    {
        $keys = array_column(self::AGE_BUCKETS, 'key');

        $perCurrency = [];
        $lira = array_fill_keys($keys, Money::zero(Currencies::TRY));
        $liraCounts = array_fill_keys($keys, 0);
        $unconverted = 0;

        foreach ($this->openInvoices() as $invoice) {
            $key = $this->ageBucket($this->daysLate($invoice));
            $currency = $invoice->currency;
            $outstanding = $this->outstandingOf($invoice);

            if (! isset($perCurrency[$currency])) {
                foreach ($keys as $empty) {
                    $perCurrency[$currency][$empty] = ['count' => 0, 'total' => Money::zero($currency)];
                }
            }

            $perCurrency[$currency][$key]['count']++;
            $perCurrency[$currency][$key]['total'] = $perCurrency[$currency][$key]['total']
                ->plus($outstanding, Money::ROUNDING);

            $inLira = $this->shareInLira($invoice, $outstanding);

            if ($inLira === null) {
                $unconverted++;

                continue;
            }

            $liraCounts[$key]++;
            $lira[$key] = $lira[$key]->plus($inLira, Money::ROUNDING);
        }

        $currencies = [];

        foreach ($perCurrency as $currency => $cells) {
            $currencies[] = [
                'currency' => $currency,
                'cells' => array_values(array_map(fn (array $cell): array => [
                    'count' => $cell['count'],
                    'total' => Money::format(Money::toStorage($cell['total']), $currency),
                ], $cells)),
                'total' => Money::format(
                    Money::toStorage(Money::sum(array_column($cells, 'total'), $currency)),
                    $currency,
                ),
            ];
        }

        return [
            'buckets' => self::AGE_BUCKETS,
            'currencies' => $currencies,
            'lira' => array_values(array_map(fn (string $key): array => [
                'count' => $liraCounts[$key],
                'total' => $this->lira($lira[$key]),
            ], $keys)),
            'liraTotal' => $this->lira(Money::sum($lira, Currencies::TRY)),
            'unconverted' => $unconverted,
        ];
    }

    /**
     * The overdue invoices themselves, worst first — the list to work from.
     *
     * Only the late ones. An invoice that is not yet due is not somebody to
     * chase, and putting it in this table would bury the ones that are.
     *
     * @return list<array<string, mixed>>
     */
    private function chaseList(): array
    {
        return $this->openInvoices()
            ->map(fn (Invoice $invoice): array => ['invoice' => $invoice, 'late' => $this->daysLate($invoice)])
            ->filter(fn (array $row): bool => $row['late'] !== null)
            ->sortByDesc('late')
            ->map(function (array $row): array {
                /** @var Invoice $invoice */
                $invoice = $row['invoice'];

                $outstanding = $this->outstandingOf($invoice);
                $inLira = $this->shareInLira($invoice, $outstanding);
                $frozen = $this->liraOf($invoice);
                $meta = $this->bucketMeta($this->ageBucket($row['late']));

                return [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'client' => $invoice->customer?->name ?? 'No client on the invoice',
                    'due' => $invoice->due_on?->format('d M Y'),
                    'late' => $row['late'],
                    'bucket' => $meta['label'],
                    'tone' => $meta['tone'],
                    'currency' => $invoice->currency,
                    'outstanding' => Money::format(Money::toStorage($outstanding), $invoice->currency),
                    'lira' => $inLira === null ? null : $this->lira($inLira),
                    'rate' => $frozen === null
                        ? null
                        : $frozen['rate'].' — '.$frozen['source'].($frozen['on'] === null ? '' : ', '.$frozen['on']),
                ];
            })
            ->values()
            ->all();
    }

    /* Profit and loss -------------------------------------------------------------- */

    /**
     * Collections and costs inside a window, both in lira.
     *
     * Income is what actually landed, valued at the rate its *invoice* froze.
     * 🔴 That is an approximation with a name: no lira rate is frozen on the
     * payment row itself, so a collection that arrived months after issue is
     * valued here at the issue-date rate rather than the collection-date one.
     * The movement between the two is real and is already recorded per payment
     * as `fx_gain_loss`, in the invoice's own currency — it is shown in the
     * realised foreign exchange section further down and is deliberately not
     * folded in here, where it would be a second conversion nobody could trace.
     * The page states this rather than quietly absorbing it.
     *
     * @return array{
     *     income: BrickMoney, expenses: BrickMoney, net: BrickMoney,
     *     incomeUnpriced: int, expensesUnpriced: int, collections: int, costs: int,
     * }
     */
    private function cashFigures(?Carbon $from, Carbon $to): array
    {
        $income = Money::zero(Currencies::TRY);
        $incomeUnpriced = 0;
        $collections = $this->paymentsBetween($from, $to);

        foreach ($collections as $payment) {
            $inLira = $this->shareInLira(
                $payment->invoice,
                Money::fromStorage((string) $payment->applied_amount, $payment->invoice->currency),
            );

            if ($inLira === null) {
                $incomeUnpriced++;

                continue;
            }

            $income = $income->plus($inLira, Money::ROUNDING);
        }

        $spent = Money::zero(Currencies::TRY);
        $expensesUnpriced = 0;
        $costs = $this->expensesBetween($from, $to);

        foreach ($costs as $expense) {
            $inLira = $this->liraOfExpense($expense);

            if ($inLira === null) {
                $expensesUnpriced++;

                continue;
            }

            $spent = $spent->plus($inLira['amount'], Money::ROUNDING);
        }

        return [
            'income' => $income,
            'expenses' => $spent,
            'net' => $income->minus($spent, Money::ROUNDING),
            'incomeUnpriced' => $incomeUnpriced,
            'expensesUnpriced' => $expensesUnpriced,
            'collections' => $collections->count(),
            'costs' => $costs->count(),
        ];
    }

    /**
     * Profit and loss for the period, in lira, on a **cash basis**.
     *
     * The basis is the whole report. See the class docblock for the argument:
     * the serbest meslek makbuzu is issued on collection and geçici vergi is
     * assessed on earnings, so money that landed is what eventually gets filed.
     * The accrual answer — what was invoiced in the same period — is carried
     * alongside as `invoiced`, labelled as the other question, so nobody can
     * read one total as the other.
     *
     * @return array<string, mixed>
     */
    private function profitAndLoss(): array
    {
        [$from, $to] = $this->range();

        $current = $this->cashFigures($from, $to);
        $previousRange = $this->previousRange();
        $previous = $previousRange === null ? null : $this->cashFigures(...$previousRange);

        // What was invoiced in the same period, for contrast only. Added from
        // the lira figure each invoice froze, exactly like everything else.
        $invoiced = Money::zero(Currencies::TRY);
        $invoicedUnpriced = 0;

        foreach ($this->invoices() as $invoice) {
            $frozen = $this->liraOf($invoice);

            if ($frozen === null) {
                $invoicedUnpriced++;

                continue;
            }

            $invoiced = $invoiced->plus($frozen['amount'], Money::ROUNDING);
        }

        return [
            'income' => $this->lira($current['income']),
            'expenses' => $this->lira($current['expenses']),
            'net' => $this->lira($current['net']),
            'isLoss' => $current['net']->isNegative(),
            'collections' => $current['collections'],
            'costs' => $current['costs'],
            'incomeUnpriced' => $current['incomeUnpriced'],
            'expensesUnpriced' => $current['expensesUnpriced'],

            'invoiced' => $this->lira($invoiced),
            'invoicedUnpriced' => $invoicedUnpriced,

            'previousLabel' => $this->previousLabel(),
            'previousNet' => $previous === null ? null : $this->lira($previous['net']),
            'previousIncome' => $previous === null ? null : $this->lira($previous['income']),
            'previousExpenses' => $previous === null ? null : $this->lira($previous['expenses']),
            'change' => $previous === null
                ? null
                : $this->lira($current['net']->minus($previous['net'], Money::ROUNDING)),
            'improved' => $previous !== null
                && ! $current['net']->minus($previous['net'], Money::ROUNDING)->isNegative(),
        ];
    }

    /* Tax --------------------------------------------------------------------------- */

    /**
     * One configured tax setting, as a decimal string.
     *
     * Read through here rather than straight from `config()` so a blank env
     * line cannot put an empty string into the money layer, which would throw
     * three frames later with nothing pointing back at the config file. A value
     * that had to fall back is still printed on the page, so a broken setting
     * is visible rather than silent.
     */
    private function taxSetting(string $key, string $fallback): string
    {
        $value = config('accounting.tax.'.$key);

        return match (true) {
            is_string($value) && trim($value) !== '' => trim($value),
            is_int($value) => (string) $value,
            default => $fallback,
        };
    }

    /**
     * KDV charged, geçici vergi accrued, and the stopaj question left open.
     *
     * 🔴 Every figure here is an estimate for the operator to confirm with a
     * mali müşavir, and the page says so beside each one. Three rules this
     * section will not break:
     *
     *  - **KDV is totalled from what each invoice froze**, never by re-applying
     *    a rate to a subtotal. An invoice zero-rated under the export-of-
     *    services exemption froze zero, and re-applying 20% would invent a
     *    liability that does not exist.
     *  - **The geçici vergi rate and threshold come from configuration**, with
     *    the year they belong to printed next to the answer, because Turkish
     *    brackets are revalued annually and a hardcoded 15% silently becomes
     *    wrong every January.
     *  - **Stopaj is computed only where it is sourced.** Art. 94 withholding
     *    is documented for Turkish tax-liable payers; whether it reaches a
     *    foreign client paying a Turkish freelancer directly could not be
     *    verified either way, so foreign invoices are counted and the question
     *    is printed instead of a number.
     *
     * @return array<string, mixed>
     */
    private function taxSummary(): array
    {
        $year = $this->taxSetting('year', '2026');
        $kdvPercent = $this->taxSetting('kdv_percent', '20');
        $geciciPercent = $this->taxSetting('gecici_vergi_percent', '15');
        $threshold = $this->taxSetting('gecici_vergi_threshold', '190000');
        $stopajPercent = $this->taxSetting('stopaj_percent', '20');

        /* KDV, from what the invoices actually froze ------------------------- */

        $kdvLira = Money::zero(Currencies::TRY);
        $kdvUnconverted = 0;

        foreach ($this->invoices() as $invoice) {
            $tax = Money::fromStorage((string) $invoice->tax_amount, $invoice->currency);

            if ($tax->isZero()) {
                continue;
            }

            $share = $this->shareInLira($invoice, $tax);

            if ($share === null) {
                $kdvUnconverted++;

                continue;
            }

            $kdvLira = $kdvLira->plus($share, Money::ROUNDING);
        }

        $kdv = $this->invoices()
            ->groupBy('currency')
            ->map(fn (Collection $group, string $currency): array => [
                'currency' => $currency,
                'count' => $group->count(),
                'net' => Money::format(
                    Money::toStorage(Money::sum($group->map(fn (Invoice $i): string => (string) $i->subtotal), $currency)),
                    $currency,
                ),
                'charged' => Money::format(
                    Money::toStorage(Money::sum($group->map(fn (Invoice $i): string => (string) $i->tax_amount), $currency)),
                    $currency,
                ),
                'zeroRated' => $group->filter(
                    fn (Invoice $i): bool => BigDecimal::of((string) $i->tax_amount)->isZero(),
                )->count(),
            ])
            ->values()
            ->all();

        /* Geçici vergi ------------------------------------------------------- */

        $quarterStart = now()->startOfQuarter();
        $quarterEnd = now()->endOfQuarter();
        $quarter = $this->cashFigures($quarterStart, now()->endOfDay());
        $yearToDate = $this->cashFigures(now()->startOfYear(), now()->endOfDay());

        $base = $quarter['net']->isNegative() ? Money::zero(Currencies::TRY) : $quarter['net'];

        /* Stopaj ------------------------------------------------------------- */

        $domestic = $this->invoices()->filter(
            fn (Invoice $invoice): bool => $invoice->company?->is_domestic === true,
        );

        return [
            'year' => $year,
            'kdvPercent' => $kdvPercent,
            'geciciPercent' => $geciciPercent,
            'threshold' => $this->lira(Money::of($threshold, Currencies::TRY)),
            'stopajPercent' => $stopajPercent,

            'kdv' => $kdv,
            'kdvLira' => $this->lira($kdvLira),
            'kdvUnconverted' => $kdvUnconverted,

            'quarter' => 'Quarter '.$quarterStart->quarter.' of '.$quarterStart->year,
            'quarterRange' => $quarterStart->format('d M').' to '.$quarterEnd->format('d M Y'),
            // The 17th of the second month after the period closes.
            'quarterDue' => $quarterEnd->copy()->startOfMonth()->addMonthsNoOverflow(2)->day(17)->format('d F Y'),
            'quarterIncome' => $this->lira($quarter['income']),
            'quarterExpenses' => $this->lira($quarter['expenses']),
            'quarterNet' => $this->lira($quarter['net']),
            'quarterIsLoss' => $quarter['net']->isNegative(),
            'gecici' => $this->lira(Money::percentageOf($base, $geciciPercent)),
            'geciciUnpriced' => $quarter['incomeUnpriced'] + $quarter['expensesUnpriced'],

            'yearToDateNet' => $this->lira($yearToDate['net']),
            'overThreshold' => $yearToDate['net']->getAmount()->compareTo(BigDecimal::of($threshold)) > 0,

            'stopajRows' => $domestic
                ->groupBy('currency')
                ->map(fn (Collection $group, string $currency): array => [
                    'currency' => $currency,
                    'count' => $group->count(),
                    'withheld' => Money::format(
                        Money::toStorage(Money::percentageOf(
                            Money::sum($group->map(fn (Invoice $i): string => (string) $i->subtotal), $currency),
                            $stopajPercent,
                        )),
                        $currency,
                    ),
                ])
                ->values()
                ->all(),
            'stopajForeign' => $this->invoices()->count() - $domestic->count(),
        ];
    }

    /* Expenses by category ----------------------------------------------------------- */

    /**
     * What the money went on, in the period.
     *
     * Each category shows one figure per currency it was spent in — never a
     * mixed total — with the lira total beside it, added only from the costs
     * that froze a lira figure. The bar is a ratio of two lira amounts, so a
     * category with nothing convertible has no bar rather than a misleading one.
     *
     * @return list<array<string, mixed>>
     */
    private function expensesByCategory(): array
    {
        $groups = $this->expenses()
            ->groupBy(fn (Expense $expense): string => $expense->category ?: 'Uncategorised')
            ->map(function (Collection $group, string $category): array {
                $lira = Money::zero(Currencies::TRY);
                $unpriced = 0;

                foreach ($group as $expense) {
                    $inLira = $this->liraOfExpense($expense);

                    if ($inLira === null) {
                        $unpriced++;

                        continue;
                    }

                    $lira = $lira->plus($inLira['amount'], Money::ROUNDING);
                }

                return [
                    'category' => $category,
                    'count' => $group->count(),
                    'amounts' => $group->groupBy('currency')
                        ->map(fn (Collection $rows, string $currency): string => Money::format(
                            Money::toStorage(Money::sum($rows->map(fn (Expense $e): string => (string) $e->amount), $currency)),
                            $currency,
                        ))
                        ->values()
                        ->all(),
                    'money' => $lira,
                    'unpriced' => $unpriced,
                ];
            })
            ->sort(fn (array $a, array $b): int => $b['money']->getAmount()->compareTo($a['money']->getAmount()));

        $peak = $groups->isEmpty() ? Money::zero(Currencies::TRY) : $groups->first()['money'];

        return $groups->map(fn (array $row): array => [
            'category' => $row['category'],
            'count' => $row['count'],
            'amounts' => $row['amounts'],
            'lira' => $this->lira($row['money']),
            'unpriced' => $row['unpriced'],
            'width' => $this->share($row['money'], $peak),
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

            'pnl' => $this->profitAndLoss(),
            'aged' => $this->agedReceivables(),
            'chase' => $this->chaseList(),
            'tax' => $this->taxSummary(),
            'byCategory' => $this->expensesByCategory(),

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

    {{-- Profit and loss. The basis is on the card, not only in the docblock. --}}
    <div class="kt-card">
        <div class="kt-card-header">
            <h3 class="kt-card-title">Profit and loss</h3>
            <span class="text-sm text-muted-foreground">In lira, cash basis</span>
        </div>
        <div class="kt-card-content p-5 flex flex-col gap-5">

            <div class="rounded-lg border border-warning/30 bg-warning/10 p-4">
                <p class="text-sm text-mono">
                    <i class="ki-filled ki-information-2 text-warning"></i>
                    <span class="font-medium">Cash basis.</span>
                    Income below is money that actually landed inside the period, not what was invoiced.
                </p>
                <p class="text-xs text-secondary-foreground mt-2">
                    A serbest meslek makbuzu is issued when payment is collected rather than when the work is
                    delivered, and geçici vergi is assessed on earnings — so collection is the basis that matches
                    what eventually gets filed. What was <em>invoiced</em> in the same period is
                    {{ $pnl['invoiced'] }}, which is a different question with a different answer; the "Invoiced"
                    card at the top of this page is that same question in USD.
                    @if ($pnl['invoicedUnpriced'] > 0)
                        {{ $pnl['invoicedUnpriced'] }}
                        {{ $pnl['invoicedUnpriced'] === 1 ? 'invoice froze' : 'invoices froze' }}
                        no lira figure and {{ $pnl['invoicedUnpriced'] === 1 ? 'is' : 'are' }} left out of it.
                    @endif
                </p>
            </div>

            <div class="kt-scrollable-x-auto">
                <table class="kt-table align-middle text-sm">
                    <thead>
                        <tr>
                            <th></th>
                            <th class="text-end">This period</th>
                            @if ($pnl['previousNet'] !== null)
                                <th class="text-end">Against {{ $pnl['previousLabel'] }}</th>
                                <th class="w-[150px] text-end">Change</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="text-secondary-foreground">
                                Income collected
                                <div class="text-xs text-muted-foreground">
                                    {{ $pnl['collections'] }}
                                    {{ $pnl['collections'] === 1 ? 'payment' : 'payments' }} landed
                                </div>
                            </td>
                            <td class="text-end font-medium text-mono">{{ $pnl['income'] }}</td>
                            @if ($pnl['previousNet'] !== null)
                                <td class="text-end text-muted-foreground">{{ $pnl['previousIncome'] }}</td>
                                <td></td>
                            @endif
                        </tr>
                        <tr>
                            <td class="text-secondary-foreground">
                                Expenses
                                <div class="text-xs text-muted-foreground">
                                    {{ $pnl['costs'] }} {{ $pnl['costs'] === 1 ? 'cost' : 'costs' }} recorded
                                </div>
                            </td>
                            <td class="text-end font-medium text-mono">{{ $pnl['expenses'] }}</td>
                            @if ($pnl['previousNet'] !== null)
                                <td class="text-end text-muted-foreground">{{ $pnl['previousExpenses'] }}</td>
                                <td></td>
                            @endif
                        </tr>
                        <tr>
                            <td class="font-medium text-mono">Net, collected less spent</td>
                            <td class="text-end text-lg font-semibold {{ $pnl['isLoss'] ? 'text-destructive' : 'text-success' }}">
                                {{ $pnl['net'] }}
                            </td>
                            @if ($pnl['previousNet'] !== null)
                                <td class="text-end text-muted-foreground">{{ $pnl['previousNet'] }}</td>
                                <td class="text-end font-medium {{ $pnl['improved'] ? 'text-success' : 'text-destructive' }}">
                                    {{ $pnl['change'] }}
                                </td>
                            @endif
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="kt-card-footer">
            <p class="text-xs text-muted-foreground">
                Every figure is added in lira from what each document froze on its own date.
                @if ($pnl['incomeUnpriced'] > 0 || $pnl['expensesUnpriced'] > 0)
                    @if ($pnl['incomeUnpriced'] > 0)
                        {{ $pnl['incomeUnpriced'] }}
                        {{ $pnl['incomeUnpriced'] === 1 ? 'payment settled an invoice that' : 'payments settled invoices that' }}
                        never froze a lira figure, so {{ $pnl['incomeUnpriced'] === 1 ? 'it is' : 'they are' }} left out of income.
                    @endif
                    @if ($pnl['expensesUnpriced'] > 0)
                        {{ $pnl['expensesUnpriced'] }}
                        {{ $pnl['expensesUnpriced'] === 1 ? 'cost has' : 'costs have' }}
                        no lira figure and {{ $pnl['expensesUnpriced'] === 1 ? 'is' : 'are' }} left out of expenses.
                    @endif
                    Nothing is converted at today's rate to fill the gap — that would make a past month move
                    every time the lira does.
                @else
                    Nothing had to be left out.
                @endif
                A collection is valued at the rate its <em>invoice</em> froze, because no lira rate is frozen on a
                payment: the movement between issue and settlement is recorded separately as realised foreign
                exchange, further down this page, and is not folded in here.
            </p>
        </div>
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

    {{-- Aged receivables, per currency, never one mixed total. --}}
    <div class="kt-card">
        <div class="kt-card-header">
            <h3 class="kt-card-title">Aged receivables</h3>
            <span class="text-sm text-muted-foreground">Every open invoice, by how overdue it is</span>
        </div>
        <div class="kt-card-table">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table align-middle text-sm">
                    <thead>
                        <tr>
                            <th class="w-[90px]">Currency</th>
                            @foreach ($aged['buckets'] as $bucket)
                                <th class="text-end {{ $bucket['tone'] }}">{{ $bucket['label'] }}</th>
                            @endforeach
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($aged['currencies'] as $row)
                            <tr wire:key="aged-{{ $row['currency'] }}">
                                <td class="font-medium text-mono">{{ $row['currency'] }}</td>
                                @foreach ($row['cells'] as $cell)
                                    <td class="text-end">
                                        <div class="text-mono">{{ $cell['total'] }}</div>
                                        <div class="text-xs text-muted-foreground">
                                            {{ $cell['count'] }} {{ $cell['count'] === 1 ? 'invoice' : 'invoices' }}
                                        </div>
                                    </td>
                                @endforeach
                                <td class="text-end font-medium text-mono">{{ $row['total'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-10 text-center">
                                    <i class="ki-filled ki-shield-tick text-2xl text-success"></i>
                                    <p class="text-sm text-muted-foreground mt-2">
                                        Nothing is outstanding. Every issued invoice has been settled.
                                    </p>
                                </td>
                            </tr>
                        @endforelse

                        @if ($aged['currencies'] !== [])
                            <tr>
                                <td class="text-secondary-foreground">
                                    In lira
                                    <div class="text-xs text-muted-foreground">at each invoice's frozen rate</div>
                                </td>
                                @foreach ($aged['lira'] as $cell)
                                    <td class="text-end">
                                        <div class="text-mono">{{ $cell['total'] }}</div>
                                        <div class="text-xs text-muted-foreground">
                                            from {{ $cell['count'] }}
                                        </div>
                                    </td>
                                @endforeach
                                <td class="text-end font-medium text-mono">{{ $aged['liraTotal'] }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
        <div class="kt-card-footer">
            <p class="text-xs text-muted-foreground">
                One row per currency and never a mixed total: adding lira to dollars needs a rate, and a rate needs
                a date and a source before anybody can argue with the result. The lira row is added only from
                invoices that froze a lira figure when they were issued.
                @if ($aged['unconverted'] > 0)
                    {{ $aged['unconverted'] }} open
                    {{ $aged['unconverted'] === 1 ? 'invoice' : 'invoices' }} froze none — usually because the buyer
                    is abroad, so no lira equivalent was required — and
                    {{ $aged['unconverted'] === 1 ? 'is' : 'are' }} counted here rather than converted at today's
                    rate.
                @endif
                Bands are inclusive: an invoice due today is not late, one due yesterday is one day late, and
                "31 to 60 days" holds days 31 to 60. Receivables are a position, so this ignores the period filter.
            </p>
        </div>
    </div>

    {{-- The list to actually work from. --}}
    <div class="kt-card">
        <div class="kt-card-header">
            <h3 class="kt-card-title">Overdue, worst first</h3>
            <span class="text-sm text-muted-foreground">
                {{ count($chase) }} {{ count($chase) === 1 ? 'invoice' : 'invoices' }} past their due date
            </span>
        </div>
        <div class="kt-card-table">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table align-middle text-sm">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th class="w-[120px]">Invoice</th>
                            <th class="w-[120px]">Due</th>
                            <th class="w-[140px] text-end">Overdue by</th>
                            <th class="w-[150px] text-end">Outstanding</th>
                            <th class="w-[150px] text-end">In lira</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($chase as $row)
                            <tr wire:key="chase-{{ $row['id'] }}">
                                <td class="text-mono">{{ $row['client'] }}</td>
                                <td>
                                    <a href="{{ route('accounting.invoice-show', ['invoice' => $row['id']]) }}"
                                       wire:navigate class="font-medium text-mono hover:text-primary">{{ $row['number'] }}</a>
                                    <div class="text-xs text-muted-foreground">{{ $row['currency'] }}</div>
                                </td>
                                <td class="text-secondary-foreground">{{ $row['due'] }}</td>
                                <td class="text-end {{ $row['tone'] }}">
                                    {{ $row['late'] }} {{ $row['late'] === 1 ? 'day' : 'days' }}
                                    <div class="text-xs text-muted-foreground">{{ $row['bucket'] }}</div>
                                </td>
                                <td class="text-end font-medium text-mono">{{ $row['outstanding'] }}</td>
                                <td class="text-end">
                                    <div class="text-mono">{{ $row['lira'] ?? '—' }}</div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ $row['rate'] ?? 'No lira rate was frozen for this invoice' }}
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-10 text-center">
                                    <i class="ki-filled ki-shield-tick text-2xl text-success"></i>
                                    <p class="text-sm text-muted-foreground mt-2">
                                        Nothing is overdue. Every open invoice is still inside its terms.
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
                Only invoices past their due date, oldest first — an invoice still inside its terms is not somebody
                to chase and would only bury the ones that are. The lira column is the same share of the figure the
                invoice froze; where it says nothing, no lira rate was frozen and none is invented here.
            </p>
        </div>
    </div>

    {{-- Tax. Estimates, sourced where they could be sourced, silent where they could not. --}}
    <div class="kt-card">
        <div class="kt-card-header">
            <h3 class="kt-card-title">Tax summary</h3>
            <span class="text-sm text-muted-foreground">Estimates for {{ $tax['year'] }} — not advice</span>
        </div>
        <div class="kt-card-content p-5 flex flex-col gap-5">

            <div class="rounded-lg border border-destructive/30 bg-destructive/10 p-4">
                <p class="text-sm text-mono">
                    <i class="ki-filled ki-information-2 text-destructive"></i>
                    <span class="font-medium">Every figure below is an estimate for you to confirm with your mali
                    müşavir.</span>
                </p>
                <p class="text-xs text-secondary-foreground mt-2">
                    The rates come from <span class="text-mono">config/accounting.php</span> and apply to the
                    {{ $tax['year'] }} tax year. Turkish income-tax brackets are revalued every year, so check them
                    against the Gelir İdaresi Başkanlığı's published figures before relying on anything here.
                    Kargah does not know your other income, your deductions, the 80% service-export exemption, or
                    what you have already paid.
                </p>
            </div>

            {{-- KDV --}}
            <div class="flex flex-col gap-3">
                <h4 class="text-sm font-medium text-mono">
                    KDV charged in the period
                    <span class="text-xs text-muted-foreground font-normal">
                        — totalled from what each invoice froze, never by applying a rate again
                    </span>
                </h4>
                <div class="kt-scrollable-x-auto">
                    <table class="kt-table align-middle text-sm">
                        <thead>
                            <tr>
                                <th class="w-[90px]">Currency</th>
                                <th class="w-[90px] text-end">Invoices</th>
                                <th class="text-end">Net of KDV</th>
                                <th class="text-end">KDV charged</th>
                                <th class="w-[130px] text-end">At zero</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($tax['kdv'] as $row)
                                <tr wire:key="kdv-{{ $row['currency'] }}">
                                    <td class="font-medium text-mono">{{ $row['currency'] }}</td>
                                    <td class="text-end text-secondary-foreground">{{ $row['count'] }}</td>
                                    <td class="text-end text-mono">{{ $row['net'] }}</td>
                                    <td class="text-end font-medium text-mono">{{ $row['charged'] }}</td>
                                    <td class="text-end text-secondary-foreground">{{ $row['zeroRated'] }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="py-6 text-center text-sm text-muted-foreground">
                                        Nothing was invoiced in this period, so no KDV was charged.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-muted-foreground">
                    In lira: {{ $tax['kdvLira'] }}, added from the figure each invoice froze.
                    @if ($tax['kdvUnconverted'] > 0)
                        {{ $tax['kdvUnconverted'] }} {{ $tax['kdvUnconverted'] === 1 ? 'invoice' : 'invoices' }}
                        charged KDV but froze no lira figure and {{ $tax['kdvUnconverted'] === 1 ? 'is' : 'are' }}
                        left out.
                    @endif
                    The standard rate on professional services is {{ $tax['kdvPercent'] }}%. An invoice showing zero
                    may be zero-rated as an export of services under exemption code 302, but that needs four
                    conditions to hold at once and is a judgement per invoice — Kargah counts them and assumes
                    nothing.
                </p>
            </div>

            {{-- Geçici vergi --}}
            <div class="flex flex-col gap-3">
                <h4 class="text-sm font-medium text-mono">
                    Geçici vergi — {{ $tax['quarter'] }}
                    <span class="text-xs text-muted-foreground font-normal">
                        — {{ $tax['quarterRange'] }}, due {{ $tax['quarterDue'] }}
                    </span>
                </h4>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
                    <div class="rounded-lg border border-border p-4">
                        <div class="text-xs text-muted-foreground">Collected this quarter</div>
                        <div class="text-lg font-semibold text-mono mt-1">{{ $tax['quarterIncome'] }}</div>
                    </div>
                    <div class="rounded-lg border border-border p-4">
                        <div class="text-xs text-muted-foreground">Spent this quarter</div>
                        <div class="text-lg font-semibold text-mono mt-1">{{ $tax['quarterExpenses'] }}</div>
                    </div>
                    <div class="rounded-lg border border-border p-4">
                        <div class="text-xs text-muted-foreground">Earnings the estimate is based on</div>
                        <div class="text-lg font-semibold mt-1 {{ $tax['quarterIsLoss'] ? 'text-destructive' : 'text-mono' }}">
                            {{ $tax['quarterNet'] }}
                        </div>
                    </div>
                    <div class="rounded-lg border border-warning/30 bg-warning/10 p-4">
                        <div class="text-xs text-muted-foreground">
                            Estimated at {{ $tax['geciciPercent'] }}%, the {{ $tax['year'] }} first bracket
                        </div>
                        <div class="text-lg font-semibold text-mono mt-1">{{ $tax['gecici'] }}</div>
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">
                    Provisional income tax is filed quarterly, due the 17th of the second month after the quarter
                    closes — {{ $tax['quarterDue'] }} for this one. The estimate applies
                    {{ $tax['geciciPercent'] }}%, read from configuration as the first income-tax bracket for
                    {{ $tax['year'] }}, to the quarter's earnings on the same cash basis as the profit and loss
                    above.
                    @if ($tax['quarterIsLoss'])
                        The quarter is at a loss, so the estimate is nil rather than a negative tax.
                    @endif
                    @if ($tax['geciciUnpriced'] > 0)
                        {{ $tax['geciciUnpriced'] }}
                        {{ $tax['geciciUnpriced'] === 1 ? 'row' : 'rows' }} in the quarter froze no lira figure and
                        {{ $tax['geciciUnpriced'] === 1 ? 'is' : 'are' }} not in the base, so the estimate is low by
                        whatever {{ $tax['geciciUnpriced'] === 1 ? 'it is' : 'they are' }} worth.
                    @endif
                </p>
                <p class="text-xs {{ $tax['overThreshold'] ? 'text-destructive' : 'text-muted-foreground' }}">
                    Kargah only knows the first bracket, which runs to {{ $tax['threshold'] }}. Year to date the
                    earnings on this basis are {{ $tax['yearToDateNet'] }}.
                    @if ($tax['overThreshold'])
                        That is over the threshold, so a higher band applies to part of it and the estimate above is
                        too low. Work the real figure out with your mali müşavir.
                    @else
                        Cross that and a higher band applies to the excess, which this estimate does not model.
                    @endif
                </p>
            </div>

            {{-- Stopaj --}}
            <div class="flex flex-col gap-3">
                <h4 class="text-sm font-medium text-mono">
                    Stopaj — withholding at source
                    <span class="text-xs text-muted-foreground font-normal">
                        — {{ $tax['stopajPercent'] }}%, withheld and remitted by the payer, not by you
                    </span>
                </h4>

                @if ($tax['stopajRows'] !== [])
                    <div class="kt-scrollable-x-auto">
                        <table class="kt-table align-middle text-sm">
                            <thead>
                                <tr>
                                    <th class="w-[90px]">Currency</th>
                                    <th class="w-[110px] text-end">Invoices</th>
                                    <th class="text-end">Withheld by the payer</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tax['stopajRows'] as $row)
                                    <tr wire:key="stopaj-{{ $row['currency'] }}">
                                        <td class="font-medium text-mono">{{ $row['currency'] }}</td>
                                        <td class="text-end text-secondary-foreground">{{ $row['count'] }}</td>
                                        <td class="text-end font-medium text-mono">{{ $row['withheld'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Shown only for invoices whose buyer is a domestic Turkish company, applied to the fee before
                        KDV. That is the case the sources actually describe: a Turkish tax-liable organisation
                        withholds under Article 94 and remits it itself.
                    </p>
                @endif

                <div class="rounded-lg border border-border p-4">
                    <p class="text-sm text-mono">
                        <i class="ki-filled ki-information-2 text-muted-foreground"></i>
                        No withholding is computed for foreign clients, and that is deliberate.
                    </p>
                    <p class="text-xs text-secondary-foreground mt-2">
                        The research behind this page could not establish whether the Article 94 obligation reaches a
                        <em>foreign</em> client paying a Turkish freelancer directly — which is most of Kargah's work.
                        The sources describe the duty only for Turkish tax-liable payers, and none of them said it
                        does not apply abroad either. So the question is printed here instead of a number:
                        {{ $tax['stopajForeign'] }}
                        {{ $tax['stopajForeign'] === 1 ? 'invoice in this period was' : 'invoices in this period were' }}
                        raised to a buyer that is not a domestic Turkish company. Ask your mali müşavir what, if
                        anything, is withheld on those.
                    </p>
                </div>
            </div>
        </div>
    </div>

    {{-- Expenses by category --}}
    <div class="kt-card">
        <div class="kt-card-header">
            <h3 class="kt-card-title">What the money went on</h3>
            <span class="text-sm text-muted-foreground">By category, in the period</span>
        </div>
        <div class="kt-card-content p-5 flex flex-col gap-4">
            @forelse ($byCategory as $row)
                <div class="flex flex-col gap-1.5" wire:key="cat-{{ $loop->index }}">
                    <div class="flex items-baseline justify-between gap-3 text-sm">
                        <span class="text-secondary-foreground truncate">{{ $row['category'] }}</span>
                        <span class="text-mono font-medium shrink-0">{{ $row['lira'] }}</span>
                    </div>
                    <div class="h-1.5 rounded-full bg-muted overflow-hidden">
                        <div class="h-full rounded-full bg-destructive/60" style="width: {{ $row['width'] }}%"></div>
                    </div>
                    <span class="text-xs text-muted-foreground">
                        {{ $row['count'] }} {{ $row['count'] === 1 ? 'cost' : 'costs' }} —
                        {{ implode(', ', $row['amounts']) }}
                        @if ($row['unpriced'] > 0)
                            ({{ $row['unpriced'] }} with no lira figure, left out of the lira total)
                        @endif
                    </span>
                </div>
            @empty
                <p class="text-sm text-muted-foreground text-center py-10">
                    Nothing was spent in this period.
                </p>
            @endforelse
        </div>
        <div class="kt-card-footer">
            <p class="text-xs text-muted-foreground">
                The bar and the headline figure are in lira; the line underneath is each currency the category was
                actually spent in, added separately. This is the breakdown a tax return asks for, so what could not
                be valued in lira is named rather than absorbed.
            </p>
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
