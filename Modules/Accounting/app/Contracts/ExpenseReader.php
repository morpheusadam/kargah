<?php

namespace Modules\Accounting\Contracts;

/**
 * What other modules — and the API — may know about expenses.
 *
 * See `InvoiceReader` for why this exists at all: `07-platform.md` assumed a
 * `Contracts` namespace in Accounting that had never been written. **Arrays
 * out, never models** — a controller in Platform must never receive an
 * `Expense`.
 *
 * @phpstan-type MoneyArray array{amount: string, currency: string, formatted: string}
 * @phpstan-type ExpenseArray array{
 *     id: int, vendor: string, category: string, description: ?string,
 *     amount: MoneyArray,
 *     reporting: ?array{currency: string, rate: ?string, amount: ?MoneyArray},
 *     is_billable: bool, is_rebilled: bool, rebilled_on_invoice_id: ?int,
 *     spent_on: ?string,
 *     company: ?array{id: int, name: string}
 * }
 */
interface ExpenseReader
{
    /** One expense, or null when it does not exist. */
    public function find(int $id): ?array;

    /**
     * A page of expenses, newest first.
     *
     * `$billable` narrows to `is_billable` when stated; left null it returns
     * every expense regardless of billability.
     *
     * @return array{items: list<ExpenseArray>, next_cursor: ?string, prev_cursor: ?string, per_page: int}
     */
    public function paginate(string $search = '', ?bool $billable = null, ?string $cursor = null, int $perPage = 20): array;
}
