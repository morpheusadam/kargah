<?php

namespace Modules\Accounting\Services;

use Brick\Money\Money as BrickMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Modules\Accounting\Contracts\ExpenseReader as ExpenseReaderContract;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;

/** `ExpenseReader` over the real table. Read only — there is no issue-style action for an expense. */
class ExpenseReader implements ExpenseReaderContract
{
    public function find(int $id): ?array
    {
        $expense = $this->query()->find($id);

        return $expense === null ? null : $this->shape($expense);
    }

    public function paginate(string $search = '', ?bool $billable = null, ?string $cursor = null, int $perPage = 20): array
    {
        $perPage = max(1, min(100, $perPage));
        $query = $this->query();

        $term = trim($search);

        if ($term !== '') {
            $like = '%'.$term.'%';

            $query->where(fn (Builder $q) => $q
                ->where('vendor', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('category', 'like', $like));
        }

        if ($billable !== null) {
            $query->where('is_billable', $billable);
        }

        $decoded = $cursor === null || $cursor === ''
            ? null
            : rescue(fn (): ?Cursor => Cursor::fromEncoded($cursor), null, false);

        $paginator = $query->orderByDesc('id')->cursorPaginate($perPage, ['*'], 'cursor', $decoded);

        return [
            'items' => $paginator->getCollection()->map(fn (Expense $expense): array => $this->shape($expense))->all(),
            'next_cursor' => $paginator->nextCursor()?->encode(),
            'prev_cursor' => $paginator->previousCursor()?->encode(),
            'per_page' => $perPage,
        ];
    }

    /** See the contract. One query, and no conversion performed anywhere in it. */
    public function expensesByMonth(int $months = 12): array
    {
        // Deliberately the same window InvoiceReader builds, called rather
        // than reimplemented: the two series are joined on this key, so a
        // month that exists in one and not the other would silently drop out
        // of the chart.
        $window = InvoiceReader::monthWindow($months);

        $firstMonth = array_key_first($window);
        $lastMonth = array_key_last($window);

        $expenses = Expense::query()
            ->whereBetween('spent_on', [
                $firstMonth.'-01',
                Carbon::parse($lastMonth.'-01')->endOfMonth()->toDateString(),
            ])
            ->get(['id', 'currency', 'amount', 'reporting_currency', 'reporting_amount', 'spent_on']);

        $totals = array_map(fn (): BrickMoney => Money::zero(Currencies::TRY), $window);
        $counted = 0;
        $excluded = 0;

        foreach ($expenses as $expense) {
            $key = $expense->spent_on->format('Y-m');

            if (! array_key_exists($key, $totals)) {
                continue;
            }

            $lira = $this->lira($expense);

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

    /**
     * What one expense is worth in lira, or null when nothing on the row says.
     * Never derives a rate — see the contract's docblock.
     */
    private function lira(Expense $expense): ?BrickMoney
    {
        if ($expense->currency === Currencies::TRY) {
            return Money::fromStorage((string) $expense->amount, Currencies::TRY);
        }

        if ($expense->reporting_currency !== Currencies::TRY || $expense->reporting_amount === null) {
            return null;
        }

        return Money::fromStorage((string) $expense->reporting_amount, Currencies::TRY);
    }

    private function query(): Builder
    {
        return Expense::query()->with('company');
    }

    private function shape(Expense $expense): array
    {
        return [
            'id' => $expense->id,
            'vendor' => $expense->vendor,
            'category' => $expense->category,
            'description' => $expense->description,
            'amount' => $this->money((string) $expense->amount, $expense->currency),
            'reporting' => $expense->reporting_currency === null ? null : [
                'currency' => $expense->reporting_currency,
                'rate' => $expense->reporting_rate === null ? null : (string) $expense->reporting_rate,
                'amount' => $expense->reporting_amount === null
                    ? null
                    : $this->money((string) $expense->reporting_amount, $expense->reporting_currency),
            ],
            'is_billable' => (bool) $expense->is_billable,
            'is_rebilled' => $expense->isRebilled(),
            'rebilled_on_invoice_id' => $expense->rebilled_on_invoice_id,
            'spent_on' => $expense->spent_on?->toDateString(),
            'company' => $expense->company === null ? null : [
                'id' => $expense->company->id,
                'name' => $expense->company->name,
            ],
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
