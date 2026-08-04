<?php

namespace Modules\Accounting\Contracts;

/**
 * What other modules — and the API — may know about invoices.
 *
 * `07-platform.md` names `Modules\Accounting\Contracts\…` as something the API
 * already consumes; it did not exist. Accounting exposed `InvoiceIssuer`,
 * `PaymentRecorder` and `ExchangeRates` as concrete services with no interface
 * in front of them, so the only way for Platform to read an invoice was to
 * import `Modules\Accounting\Models\Invoice` directly — exactly what
 * `CardReader`, `EmailReader` and `AttachmentService` exist to prevent one
 * module further over. This closes that gap the same way those three do.
 *
 * **Arrays out, never models.** A controller in Platform must never receive an
 * `Invoice`. Every money figure is shaped as
 * `{amount: string, currency: string, formatted: string}` — never a bare
 * decimal and never a float — because a JSON number is a double in every
 * client that parses it.
 *
 * @phpstan-type MoneyArray array{amount: string, currency: string, formatted: string}
 * @phpstan-type InvoiceArray array{
 *     id: int, number: string, status: string, currency: string,
 *     customer: ?array{id: int, name: string},
 *     company: ?array{id: int, name: string},
 *     subtotal: MoneyArray, tax_percent: string, tax_amount: MoneyArray, total: MoneyArray,
 *     reporting: ?array{currency: string, rate: ?string, amount: ?MoneyArray},
 *     outstanding: MoneyArray,
 *     is_issued: bool, is_overdue: bool,
 *     issued_on: ?string, due_on: ?string, sent_at: ?string, paid_at: ?string, voided_at: ?string,
 *     lines: list<array{id: int, description: string, quantity: string, unit_price: MoneyArray, amount: MoneyArray}>
 * }
 */
interface InvoiceReader
{
    /** One invoice, or null when it does not exist. */
    public function find(int $id): ?array;

    /**
     * A page of the invoice book, newest first — the same ordering and the
     * same cursor mechanism `⚡invoices.blade.php` already uses, so a client of
     * the API and a person looking at the page never see a different order.
     *
     * `$status` matches the book's own tabs: `draft`, `sent`, `paid`,
     * `overdue`, or null for all of them.
     *
     * @return array{items: list<InvoiceArray>, next_cursor: ?string, prev_cursor: ?string, per_page: int}
     */
    public function paginate(?string $status = null, string $search = '', ?string $cursor = null, int $perPage = 20): array;

    /**
     * Issue the invoice: freeze its exchange rates onto the document.
     *
     * Calling this on an invoice that is already issued is **not** an error and
     * does **not** re-freeze anything — it returns the invoice's existing,
     * already-frozen figures unchanged. Issuing is the one moment an invoice's
     * numbers may change, and a retried request must not get a second moment.
     *
     * Returns null when the invoice does not exist.
     *
     * 🔴 **`null` means "the configured reporting currency", and is the right
     * thing for a caller to pass.** This defaulted to the literal `'USD'`, which
     * was invisible while that was also Accounting's own default and became a
     * silent disagreement the day `accounting.reporting_currency` moved to lira:
     * an invoice issued through this contract froze dollars while the same
     * invoice issued from the builder froze lira. A caller outside Accounting
     * does not own that decision — see `InvoiceIssuer::reportingCurrency()`.
     */
    public function issue(int $id, ?string $reportingCurrency = null): ?array;

    /**
     * The whole book's outstanding money — **per currency, never added
     * across currencies.**
     *
     * Kargah invoices in USD, TRY and USDT, and "total outstanding" spanning
     * three currencies is not one number. Two ways to make it one were
     * considered and rejected:
     *
     * - Summing raw amounts across currencies is simply wrong — it is the
     *   same category of mistake as `SUM(amount)` in SQL, just committed one
     *   layer up.
     * - Converting every invoice into the owner's reporting currency at its
     *   own frozen `reporting_rate` looked attractive, because that rate
     *   never moves — an issued invoice never changes its numbers. But
     *   `reporting_amount` is frozen to the invoice's *face* total at issue,
     *   not to whatever remains after partial payments, and `reporting_rate`
     *   is null whenever the rate fetch failed at issue time (`InvoiceIssuer`
     *   must never block on a missing rate). Multiplying today's *dynamic*
     *   outstanding balance by an issue-time rate to manufacture a single
     *   reporting-currency figure is a conversion nobody asked this contract
     *   to perform, on a rate that is not guaranteed to exist for every row —
     *   exactly the kind of number 03-accounting.md says nobody could defend
     *   to an accountant.
     *
     * So the answer is exact rather than singular: one figure per currency
     * that actually has an outstanding invoice, in the invoice's own
     * currency, computed the same way `PaymentRecorder::outstanding()`
     * computes it for one invoice — total minus applied payments, never
     * negative — just summed across the book instead of read off one row.
     *
     * Every one of `Currencies::supported()` — USD, TRY, USDT — is always
     * present, even at zero, so an empty book is a real `{amount:
     * '0.000000', ...}` per currency rather than a missing key or an empty
     * list a caller has to special-case.
     *
     * `overdue` is the same shape, restricted to the invoices already
     * outstanding whose due date has passed — the same date test
     * `Invoice::isOverdue()` uses, not a second definition of overdue.
     *
     * @return array{outstanding: list<MoneyArray>, overdue: list<MoneyArray>}
     */
    public function totals(): array;

    /**
     * Invoiced revenue per month for the trailing `$months` months, in lira.
     *
     * This is the one place in this contract that returns a single figure
     * across currencies, and it is allowed to only because it never performs a
     * conversion. Each invoice contributes the lira figure **it already
     * carries**:
     *
     * - an invoice raised in lira contributes its own `total` — nothing to
     *   convert, so nothing to get wrong;
     * - an invoice raised in dollars or tether contributes `try_equivalent`,
     *   which `InvoiceIssuer` froze at issue against a named TCMB rate on a
     *   named date;
     * - an invoice that has neither is **excluded and counted**, never
     *   converted at today's rate. That is the difference between this and
     *   `totals()`: `totals()` refuses a single figure because outstanding
     *   balance is *dynamic* and multiplying it by an issue-time rate would
     *   invent a number; a revenue series is the invoice's *face* total, which
     *   is exactly what the frozen figure is the frozen figure of.
     *
     * `excluded` therefore has to be shown wherever `months` is shown. "Three
     * invoices are not on this chart because they have no lira rate" is a
     * sentence somebody can act on; a series that quietly drops them is a
     * series that reads as a bad month.
     *
     * Invoiced revenue is **not** cash received. There is deliberately no
     * cash-in equivalent of this method: a `payments` row carries
     * `settlement_rate` to the *invoice's* currency and no rate to lira at
     * all, so a cash-in-lira series could only be produced by re-converting,
     * which is the thing this docblock exists to refuse.
     *
     * `symbol` is the lira sign, so no caller — least of all a chart's
     * JavaScript — needs a currency symbol table of its own. `month` is
     * `YYYY-MM`, which is what a caller joins two series on; `label` is how it
     * reads on an axis.
     *
     * @return array{
     *     currency: string, symbol: string,
     *     months: list<array{month: string, label: string, amount: string, formatted: string}>,
     *     counted: int, excluded: int
     * }
     */
    public function revenueByMonth(int $months = 12): array;

    /**
     * The same trailing-`$months` lira revenue, grouped by client instead of
     * by month, biggest first, with everything past `$limit` rolled into one
     * `is_other` row. It answers concentration risk: how much of the practice
     * rests on one client.
     *
     * Same frozen-figure rule and same `excluded` count as
     * `revenueByMonth()` — see that docblock. An invoice with no client at all
     * is its own row rather than being dropped, because "billed to nobody" is
     * a data problem worth seeing rather than hiding.
     *
     * @return array{
     *     currency: string, symbol: string,
     *     clients: list<array{name: string, amount: string, formatted: string, is_other: bool}>,
     *     counted: int, excluded: int
     * }
     */
    public function revenueByClient(int $months = 12, int $limit = 6): array;

    /**
     * Outstanding money split by how overdue it is — the aging report every
     * source ranks alongside P&L as essential for a freelancer, because unpaid
     * invoices are the cash-flow risk.
     *
     * **Per currency inside each bucket, never converted.** Unlike
     * `revenueByMonth()`, an outstanding balance is what is left after
     * payments, so it is dynamic and no document froze a lira figure for it —
     * exactly the reasoning `totals()` gives for staying per currency. Adding
     * the buckets' `totals` back up gives `totals()['outstanding']` exactly.
     *
     * An invoice that owes nothing — fully paid but not yet marked so — is in
     * no bucket and in no count. It is not money anybody is waiting for, and
     * counting it would overstate "4 invoices overdue" to somebody deciding
     * whether to chase.
     *
     * Buckets are fixed and ordered: `not_due`, `1_30`, `31_60`, `over_60`,
     * measured from `due_on` against the start of today — the same date test
     * `Invoice::isOverdue()` uses.
     *
     * @return array{
     *     buckets: list<array{key: string, label: string, count: int, totals: list<MoneyArray>}>,
     *     count: int
     * }
     */
    public function agedReceivables(): array;
}
