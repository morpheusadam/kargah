<?php

use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * What the business costs to run, read from the database.
 *
 * Two things here are worth knowing before changing anything.
 *
 * **Nothing is totalled in SQL.** `SUM(amount)` on SQLite is a sum of doubles,
 * because the column has NUMERIC affinity and a decimal is stored as an IEEE
 * double. The rows are fetched and added through `Money::sum()` instead, which
 * works on decimal strings. Counting rows in SQL is fine; adding money is not.
 *
 * **Currencies are never mixed.** A total is per currency, and the single
 * reporting figure alongside it comes from each row's *frozen*
 * `reporting_amount` — the figure the expense recorded on the day it was
 * incurred. Rows that never got a rate are excluded and counted out loud rather
 * than converted at today's rate, which would make last March's cost move every
 * time the lira does.
 */
new
#[Title('Expenses — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url]
    public string $search = '';

    /** A category name, or '' for every category. */
    #[Url]
    public string $category = '';

    /** One of 'all', 'month', 'quarter', 'year'. */
    #[Url]
    public string $period = 'all';

    /** One of '', 'billable', 'unbilled', 'rebilled'. */
    #[Url(as: 'billing')]
    public string $billing = '';

    /**
     * Per-request memos. Private, so Livewire neither ships nor rehydrates
     * them. Without these the page runs the same query four times: the table,
     * the totals, the category panel and the filter toast each go looking
     * independently.
     */
    private ?Collection $resolvedExpenses = null;

    private ?int $resolvedTotalCount = null;

    /* Reading ---------------------------------------------------------------- */

    /** The rows that survive the current filter, newest first. */
    private function expenses(): Collection
    {
        if ($this->resolvedExpenses !== null) {
            return $this->resolvedExpenses;
        }

        $query = Expense::query()->with(['company', 'rebilledOn'])->orderByDesc('spent_on')->orderByDesc('id');

        if ($this->category !== '') {
            $query->inCategory($this->category);
        }

        [$from, $to] = $this->range();

        if ($from !== null) {
            $query->whereDate('spent_on', '>=', $from->toDateString())
                ->whereDate('spent_on', '<=', $to->toDateString());
        }

        $term = trim($this->search);

        if ($term !== '') {
            $query->where(function ($q) use ($term): void {
                $q->where('vendor', 'like', '%'.$term.'%')
                    ->orWhere('description', 'like', '%'.$term.'%')
                    ->orWhere('receipt_reference', 'like', '%'.$term.'%')
                    ->orWhere('category', 'like', '%'.$term.'%');
            });
        }

        match ($this->billing) {
            'billable' => $query->billable(),
            'unbilled' => $query->unbilled(),
            'rebilled' => $query->whereNotNull('rebilled_on_invoice_id'),
            default => null,
        };

        return $this->resolvedExpenses = $query->get();
    }

    /** How many there are before any filter — the denominator in every summary. */
    private function totalCount(): int
    {
        // A row count, not a money figure. SQL is the right place for it.
        return $this->resolvedTotalCount ??= Expense::query()->count();
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    private function range(): array
    {
        return match ($this->period) {
            'month' => [now()->startOfMonth(), now()->endOfDay()],
            'quarter' => [now()->startOfQuarter(), now()->endOfDay()],
            'year' => [now()->startOfYear(), now()->endOfDay()],
            default => [null, null],
        };
    }

    /* Totals ----------------------------------------------------------------- */

    /**
     * One total per currency, added in PHP.
     *
     * @return list<array{currency: string, count: int, formatted: string}>
     */
    private function totalsByCurrency(Collection $rows): array
    {
        return $rows
            ->groupBy('currency')
            ->map(fn (Collection $group, string $currency): array => [
                'currency' => $currency,
                'count' => $group->count(),
                'formatted' => Money::format(
                    Money::toStorage($this->sumOf($group, 'amount', $currency)),
                    $currency,
                ),
            ])
            ->values()
            ->all();
    }

    /** Add one decimal column of a set of rows, in one currency. */
    private function sumOf(Collection $rows, string $column, string $currency): BrickMoney
    {
        return Money::sum(
            $rows->map(fn (Expense $expense): string => (string) $expense->{$column}),
            $currency,
        );
    }

    /** The rows carrying a frozen reporting figure, which is the only kind that can be added across currencies. */
    private function converted(Collection $rows): Collection
    {
        return $rows->filter(
            fn (Expense $expense): bool => $expense->reporting_amount !== null
                && $expense->reporting_currency === Currencies::USD,
        );
    }

    private function reportingTotal(Collection $rows): BrickMoney
    {
        return $this->sumOf($this->converted($rows), 'reporting_amount', Currencies::USD);
    }

    /**
     * A bar width, as a whole percentage.
     *
     * The width is not money — it is a ratio of two amounts that *are*, so the
     * division happens on BigDecimal at a stated scale and comes out an integer.
     */
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

    /* View ------------------------------------------------------------------- */

    public function with(): array
    {
        $rows = $this->expenses();
        $converted = $this->converted($rows);

        $byCategory = $rows
            ->groupBy(fn (Expense $expense): string => $expense->category ?: 'Uncategorised')
            ->map(fn (Collection $group): array => [
                'count' => $group->count(),
                'total' => $this->sumOf($this->converted($group), 'reporting_amount', Currencies::USD),
            ])
            // Compared as decimals, not as strings: sorted by text, "9" beats
            // "10" and the biggest category ends up halfway down the list.
            ->sort(fn (array $a, array $b): int => $b['total']->getAmount()->compareTo($a['total']->getAmount()));

        $peak = $byCategory->isEmpty()
            ? Money::zero(Currencies::USD)
            : $byCategory->first()['total'];

        $unbilled = $rows->filter(
            fn (Expense $expense): bool => $expense->is_billable && ! $expense->isRebilled(),
        );

        return [
            'rows' => $rows->map(fn (Expense $expense): array => [
                'id' => $expense->id,
                'date' => $expense->spent_on?->format('d M Y') ?? '—',
                'vendor' => $expense->vendor,
                'description' => $expense->description,
                'category' => $expense->category ?: 'Uncategorised',
                'company' => $expense->company?->name,
                'receipt' => $expense->receipt_reference,
                'amount' => $expense->formattedAmount(),
                'currency' => $expense->currency,
                'reporting' => $expense->currency === Currencies::USD ? null : $expense->formattedReporting(),
                'rate' => $expense->reporting_rate === null ? null : (string) $expense->reporting_rate,
                'billing' => $this->billingState($expense),
                'rebilledOn' => $expense->rebilledOn?->number,
            ])->all(),

            'totals' => $this->totalsByCurrency($rows),
            'reportingTotal' => Money::format(Money::toStorage($this->reportingTotal($rows)), Currencies::USD),
            'unconverted' => $rows->count() - $converted->count(),

            'unbilledCount' => $unbilled->count(),
            'unbilledTotal' => Money::format(
                Money::toStorage($this->sumOf($this->converted($unbilled), 'reporting_amount', Currencies::USD)),
                Currencies::USD,
            ),

            'categories' => $byCategory->map(fn (array $entry, string $name): array => [
                'name' => $name,
                'count' => $entry['count'],
                'formatted' => Money::format(Money::toStorage($entry['total']), Currencies::USD),
                'width' => $this->share($entry['total'], $peak),
            ])->values()->all(),

            'categoryOptions' => Expense::query()
                ->whereNotNull('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->all(),

            'periods' => [
                'all' => 'All time',
                'month' => 'This month',
                'quarter' => 'This quarter',
                'year' => 'Year to date',
            ],
            'billingOptions' => [
                '' => 'Everything',
                'billable' => 'Billable',
                'unbilled' => 'Billable, not yet invoiced',
                'rebilled' => 'Already rebilled',
            ],

            'shownCount' => $rows->count(),
            'totalCount' => $this->totalCount(),
            'activeFilters' => $this->activeFilters(),

            'billingBadges' => [
                'rebilled' => ['label' => 'Rebilled', 'class' => 'kt-badge kt-badge-sm kt-badge-success'],
                'unbilled' => ['label' => 'Recoverable', 'class' => 'kt-badge kt-badge-sm kt-badge-warning'],
                'absorbed' => ['label' => 'Absorbed', 'class' => 'kt-badge kt-badge-sm kt-badge-outline'],
            ],
        ];
    }

    /**
     * Billable and rebilled are two different questions.
     *
     * A cost the client agreed to cover is billable the day it is incurred and
     * stays recoverable until an invoice actually carries it. The gap between
     * the two is the money most easily forgotten.
     */
    private function billingState(Expense $expense): string
    {
        if ($expense->isRebilled()) {
            return 'rebilled';
        }

        return $expense->is_billable ? 'unbilled' : 'absorbed';
    }

    private function activeFilters(): int
    {
        return (trim($this->search) === '' ? 0 : 1)
            + ($this->category === '' ? 0 : 1)
            + ($this->period === 'all' ? 0 : 1)
            + ($this->billing === '' ? 0 : 1);
    }

    /** What the filter left on screen, in one sentence. */
    private function summary(): string
    {
        $rows = $this->expenses();

        if ($rows->isEmpty()) {
            return 'Nothing matches, out of '.$this->totalCount().' recorded.';
        }

        $totals = array_map(fn (array $total): string => $total['formatted'], $this->totalsByCurrency($rows));

        return 'Showing '.$rows->count().' of '.$this->totalCount().' expenses, '.implode(' and ', $totals).'.';
    }

    /* Filters ----------------------------------------------------------------- */

    public function updatedCategory(): void
    {
        $this->toastSuccess(
            $this->category === '' ? 'Every category' : $this->category.' only',
            $this->summary(),
        );
    }

    public function updatedPeriod(): void
    {
        $this->toastSuccess('Period changed', $this->summary());
    }

    public function updatedBilling(): void
    {
        $this->toastSuccess('Rebilling filter changed', $this->summary());
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->category = '';
        $this->period = 'all';
        $this->billing = '';

        $this->resolvedExpenses = null;

        $this->toastSuccess('Filters cleared', $this->summary());
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Expenses</h1>
            <p class="text-sm text-secondary-foreground mt-1">What the business costs you to run.</p>
        </div>
        <a href="{{ route('accounting.expense-create') }}" wire:navigate class="kt-btn kt-btn-primary gap-2">
            <i class="ki-filled ki-plus"></i> Record expense
        </a>
    </div>

    {{-- Totals. Each currency added on its own; the reporting figure comes from
         the rate every row froze on the day it was incurred. --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">

        <div class="kt-card">
            <div class="kt-card-content p-5">
                <div class="flex items-center gap-2 text-sm text-secondary-foreground">
                    <i class="ki-filled ki-wallet text-destructive"></i> Spent
                </div>
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 mt-2">
                    @forelse ($totals as $total)
                        <span class="text-2xl font-semibold text-mono">{{ $total['formatted'] }}</span>
                    @empty
                        <span class="text-2xl font-semibold text-muted-foreground">—</span>
                    @endforelse
                </div>
                <p class="text-xs text-muted-foreground mt-1">
                    {{ $shownCount }} of {{ $totalCount }} {{ $totalCount === 1 ? 'expense' : 'expenses' }}
                </p>
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-content p-5">
                <div class="flex items-center gap-2 text-sm text-secondary-foreground">
                    <i class="ki-filled ki-dollar text-primary"></i> In USD, as reported
                </div>
                <div class="text-2xl font-semibold text-mono mt-2">{{ $reportingTotal }}</div>
                <p class="text-xs text-muted-foreground mt-1">
                    Converted at the rate each expense froze on its own date.
                    @if ($unconverted > 0)
                        {{ $unconverted }} {{ $unconverted === 1 ? 'row has' : 'rows have' }} no rate and {{ $unconverted === 1 ? 'is' : 'are' }} left out.
                    @endif
                </p>
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-content p-5">
                <div class="flex items-center gap-2 text-sm text-secondary-foreground">
                    <i class="ki-filled ki-arrow-up text-warning"></i> Recoverable, not yet invoiced
                </div>
                <div class="text-2xl font-semibold text-mono mt-2">{{ $unbilledTotal }}</div>
                <p class="text-xs text-muted-foreground mt-1">
                    {{ $unbilledCount }} billable {{ $unbilledCount === 1 ? 'cost' : 'costs' }} no invoice carries yet.
                </p>
            </div>
        </div>

    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start">

        <div class="kt-card lg:col-span-2">
            <div class="kt-card-header flex-wrap gap-3">
                <h3 class="kt-card-title">Expenses</h3>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="kt-input max-w-[200px]">
                        <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                        <input type="text" placeholder="Vendor, note or receipt…" aria-label="Search expenses"
                               wire:model.live.debounce.300ms="search">
                    </div>
                    <select class="kt-select max-w-[150px]" aria-label="Category" wire:model.live="category">
                        <option value="">Every category</option>
                        @foreach ($categoryOptions as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                    <select class="kt-select max-w-[150px]" aria-label="Period" wire:model.live="period">
                        @foreach ($periods as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    <select class="kt-select max-w-[190px]" aria-label="Rebilling" wire:model.live="billing">
                        @foreach ($billingOptions as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @if ($activeFilters > 0)
                        <button wire:click="clearFilters" class="kt-btn kt-btn-sm kt-btn-ghost gap-1">
                            <i class="ki-filled ki-cross text-xs"></i> Clear
                        </button>
                    @endif
                </div>
            </div>
            <div class="kt-card-table">
                <div class="kt-scrollable-x-auto">
                    <table class="kt-table align-middle text-sm">
                        <thead>
                            <tr>
                                <th class="w-[120px]">Date</th>
                                <th class="min-w-[200px]">Vendor</th>
                                <th class="w-[130px]">Category</th>
                                <th class="w-[130px]">Rebilling</th>
                                <th class="w-[150px] text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr wire:key="expense-{{ $row['id'] }}">
                                    <td class="text-secondary-foreground">{{ $row['date'] }}</td>
                                    <td>
                                        <div class="font-medium text-mono">{{ $row['vendor'] }}</div>
                                        @if ($row['description'])
                                            <div class="text-xs text-muted-foreground line-clamp-1">{{ $row['description'] }}</div>
                                        @elseif ($row['receipt'])
                                            <div class="text-xs text-muted-foreground">Receipt {{ $row['receipt'] }}</div>
                                        @endif
                                    </td>
                                    <td><span class="kt-badge kt-badge-sm kt-badge-outline">{{ $row['category'] }}</span></td>
                                    <td>
                                        <span class="{{ $billingBadges[$row['billing']]['class'] }}"
                                              @if ($row['rebilledOn']) title="On {{ $row['rebilledOn'] }}" @endif>
                                            {{ $billingBadges[$row['billing']]['label'] }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="font-medium text-mono">{{ $row['amount'] }}</div>
                                        @if ($row['reporting'])
                                            <div class="text-xs text-muted-foreground">
                                                {{ $row['reporting'] }} at {{ $row['rate'] }}
                                            </div>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5">
                                        <div class="flex flex-col items-center justify-center text-center py-12">
                                            <i class="ki-filled ki-wallet text-3xl text-muted-foreground mb-3"></i>
                                            @if ($totalCount === 0)
                                                <p class="text-sm text-secondary-foreground mb-4">
                                                    Nothing recorded yet. The first one takes about thirty seconds.
                                                </p>
                                                <a href="{{ route('accounting.expense-create') }}" wire:navigate
                                                   class="kt-btn kt-btn-primary kt-btn-sm gap-2">
                                                    <i class="ki-filled ki-plus"></i> Record expense
                                                </a>
                                            @else
                                                <p class="text-sm text-secondary-foreground mb-4">
                                                    None of the {{ $totalCount }} recorded expenses match this filter.
                                                </p>
                                                <button wire:click="clearFilters" class="kt-btn kt-btn-outline kt-btn-sm gap-2">
                                                    <i class="ki-filled ki-cross"></i> Clear the filters
                                                </button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">By category</h3>
                <span class="text-sm text-muted-foreground" wire:loading wire:target="search">
                    <i class="ki-filled ki-loading animate-spin"></i>
                </span>
            </div>
            <div class="kt-card-content p-5 flex flex-col gap-4">
                @forelse ($categories as $entry)
                    <div class="flex flex-col gap-1.5" wire:key="cat-{{ $entry['name'] }}">
                        <div class="flex items-baseline justify-between gap-3 text-sm">
                            <span class="text-secondary-foreground truncate">{{ $entry['name'] }}</span>
                            <span class="text-mono font-medium shrink-0">{{ $entry['formatted'] }}</span>
                        </div>
                        <div class="h-1.5 rounded-full bg-muted overflow-hidden">
                            <div class="h-full rounded-full bg-primary/70" style="width: {{ $entry['width'] }}%"></div>
                        </div>
                        <span class="text-xs text-muted-foreground">
                            {{ $entry['count'] }} {{ $entry['count'] === 1 ? 'expense' : 'expenses' }}
                        </span>
                    </div>
                @empty
                    <p class="text-sm text-muted-foreground text-center py-6">
                        Nothing to break down yet.
                    </p>
                @endforelse

                @if ($categories !== [])
                    <p class="text-xs text-muted-foreground border-t border-border pt-3">
                        Each category is added in USD from the rate its rows froze, so two currencies never share a total.
                    </p>
                @endif
            </div>
        </div>

    </div>
</div>
