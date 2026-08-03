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
     */
    public function issue(int $id, string $reportingCurrency = 'USD'): ?array;
}
