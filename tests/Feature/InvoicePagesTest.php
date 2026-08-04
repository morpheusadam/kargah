<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Accounting\Database\Seeders\AccountingDatabaseSeeder;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\InvoiceLine;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Models\RecurringInvoice;
use Modules\Accounting\Services\ExchangeRates;
use Modules\Accounting\Services\InvoiceIssuer;
use Modules\Accounting\Services\PaymentRecorder;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;
use Tests\TestCase;

/**
 * The four invoice pages, against real data.
 *
 * These pages are where the money layer meets a person, so what is asserted
 * here is not "the page returned 200" but "the page said the thing an
 * accountant would ask for": the rate, the date it is for, the chain and the
 * hash, and the fact that an issued invoice cannot be edited.
 *
 * Each page is exercised twice over — against the seeder, and against an empty
 * database. An empty install is the first thing a new user sees, and a page
 * that only works once there is data in it is a page that is broken on day one.
 */
class InvoicePagesTest extends TestCase
{
    use RefreshDatabase;

    /** The components this task owns, by their Livewire name and their file. */
    private const PAGES = [
        'invoices' => '⚡invoices.blade.php',
        'invoice-edit' => '⚡invoice-edit.blade.php',
        'invoice-show' => '⚡invoice-show.blade.php',
        'recurring' => '⚡recurring.blade.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'Nima Fazlipour']));
    }

    private function seedTheBook(): void
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(AccountingDatabaseSeeder::class);
    }

    /**
     * The market on the day the invoices below are issued.
     *
     * The same figures `InvoicingTest` uses, so a rate that appears in an
     * assertion here can be traced to the row that produced it.
     */
    private function recordRates(): void
    {
        $rates = app(ExchangeRates::class);

        $rates->record(Currencies::USD, Currencies::TRY, '34.152700', 'frankfurter', '2026-07-01');
        $rates->record(Currencies::USD, Currencies::TRY, '34.081500', 'tcmb_evds', '2026-07-01', ExchangeRates::TCMB_BUY);
        $rates->record(Currencies::USDT, Currencies::USD, '0.999200', 'coingecko', '2026-07-01');
    }

    private function draft(array $attributes = [], array $lines = []): Invoice
    {
        $invoice = Invoice::query()->create([
            'number' => $attributes['number'] ?? 'INV-'.fake()->unique()->numberBetween(1000, 9999),
            'company_id' => $attributes['company_id'] ?? null,
            'customer_id' => $attributes['customer_id'] ?? null,
            'currency' => $attributes['currency'] ?? Currencies::USD,
            'tax_percent' => $attributes['tax_percent'] ?? '0',
            'issued_on' => $attributes['issued_on'] ?? '2026-07-01',
            'due_on' => $attributes['due_on'] ?? '2026-07-31',
            'status' => 'draft',
        ]);

        foreach ($lines === [] ? [['Retainer, July', '1', '1500.00']] : $lines as $i => [$description, $quantity, $price]) {
            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'description' => $description,
                'quantity' => $quantity,
                'unit_price' => $price,
                'amount' => Money::toStorage(Money::lineTotal($quantity, $price, $invoice->currency)),
                'position' => (string) (($i + 1) * 1024),
            ]);
        }

        return app(InvoiceIssuer::class)->recalculate($invoice->refresh());
    }

    /**
     * What every report adds up: the entries that have not been reversed.
     *
     * Summed in PHP through `Money`, never `SUM()` in SQL — on SQLite a decimal
     * column is stored as an IEEE double and a SQL sum of money is approximate.
     */
    private function standingTotal(string $currency = Currencies::USD): string
    {
        return Money::toStorage(Money::sum(
            LedgerEntry::standing()
                ->where('currency', $currency)
                ->pluck('amount')
                ->map(fn ($amount): string => (string) $amount),
            $currency,
        ));
    }

    private function schedule(array $attributes = []): RecurringInvoice
    {
        return RecurringInvoice::query()->create([
            'title' => $attributes['title'] ?? 'Retainer — product design',
            'company_id' => $attributes['company_id'] ?? null,
            'customer_id' => $attributes['customer_id'] ?? null,
            'currency' => $attributes['currency'] ?? Currencies::USD,
            'tax_percent' => $attributes['tax_percent'] ?? '0',
            'cadence' => $attributes['cadence'] ?? 'monthly',
            'day_of_month' => $attributes['day_of_month'] ?? null,
            'next_run_on' => $attributes['next_run_on'] ?? today()->toDateString(),
            'lines' => $attributes['lines'] ?? [
                ['description' => 'Monthly retainer', 'quantity' => '1', 'unit_price' => '2400.00'],
            ],
            'terms' => 'Payment due within 30 days of the invoice date.',
            'is_active' => $attributes['is_active'] ?? true,
        ]);
    }

    /* Rendering ---------------------------------------------------------------- */

    public function test_every_invoice_page_renders_on_an_empty_database(): void
    {
        // Nothing seeded. A page that needs a row to exist before it can draw
        // itself is broken for the first person who ever opens it.
        $this->get('/accounting/invoices')->assertOk()->assertSee('No invoices yet', false);
        $this->get('/accounting/invoices/create')->assertOk()->assertSee('New invoice');
        $this->get('/accounting/recurring')->assertOk()->assertSee('No recurring schedules yet', false);

        // And a link to an invoice that is not there explains itself rather
        // than dead-ending on a 404.
        $this->get('/accounting/invoices/1')->assertOk()->assertSee('That invoice is not here');
        $this->get('/accounting/invoices/1/edit')->assertOk()->assertSee('That invoice is not here');
    }

    public function test_every_invoice_page_renders_against_the_seeder(): void
    {
        $this->seedTheBook();

        $paid = Invoice::query()->where('number', 'INV-0043')->firstOrFail();
        $draft = Invoice::query()->where('number', 'INV-0044')->firstOrFail();

        $this->get('/accounting/invoices')
            ->assertOk()
            ->assertSee('INV-0043')
            ->assertSee('Harbour &amp; Finch', false);

        $this->get('/accounting/invoices/'.$paid->id)->assertOk()->assertSee('INV-0043');
        $this->get('/accounting/invoices/'.$draft->id.'/edit')->assertOk()->assertSee('INV-0044');
        $this->get('/accounting/recurring')->assertOk();
    }

    /**
     * Asserted on view data, not on the response body.
     *
     * The table body is an island, so after an action its markup travels in
     * `effects.islandFragments` rather than in the component's HTML — the body
     * of the response carries `mode=skip` and nothing else. `assertSee` here
     * would be checking the page heading.
     */
    public function test_the_list_filters_the_real_book(): void
    {
        $this->seedTheBook();

        $numbers = fn ($paginator): array => collect($paginator->items())->pluck('number')->sort()->values()->all();

        Livewire::test('accounting::invoices')
            ->assertViewHas('invoices', fn ($invoices): bool => in_array('INV-0038', $numbers($invoices), true))
            ->call('filterBy', 'draft')
            ->assertViewHas('invoices', fn ($invoices): bool => $numbers($invoices) === ['INV-0044'])
            // Silent by design: the tab that changed and the rows under it are
            // the whole of what happened, and both are on screen.
            ->assertNotDispatched('toast');

        Livewire::test('accounting::invoices')
            ->set('search', 'Harbour')
            ->assertViewHas('invoices', fn ($invoices): bool => $numbers($invoices) === ['INV-0042', 'INV-0043']);
    }

    /** The first paint carries the rows; only later actions skip the island. */
    public function test_the_first_load_paints_the_rows_and_the_totals(): void
    {
        $this->seedTheBook();

        $overdue = Invoice::query()->where('number', 'INV-0040')->firstOrFail();

        $this->get('/accounting/invoices')
            ->assertOk()
            ->assertSee('INV-0040')
            ->assertSee($overdue->formattedTotal())
            ->assertSee('Overdue');
    }

    /**
     * An island nobody names keeps whatever the DOM already had — the fragment
     * comes back with `mode=skip` and the morph engine walks straight past it.
     * Every action that changes what the table shows has to name both islands.
     */
    public function test_an_action_that_changes_the_table_sends_both_islands_back(): void
    {
        $this->seedTheBook();

        foreach ([['filterBy', ['paid']], ['goToCursor', ['']]] as [$method, $args]) {
            $component = Livewire::test('accounting::invoices')->call($method, ...$args);

            $this->assertNotEmpty(
                $component->effects['islandFragments'] ?? [],
                $method.'() changed the table but never named an island, so the browser keeps the old rows.',
            );
        }

        $source = file_get_contents(base_path('Modules/Accounting/resources/views/components/⚡invoices.blade.php'));

        // An island inside a `@foreach` shares one compile-time token with every
        // iteration, and the client finds the fragment by token alone.
        $this->assertSame(2, substr_count($source, '@island('));
        $this->assertStringContainsString("renderIsland('tabs')", $source);
        $this->assertStringContainsString("renderIsland('rows')", $source);
    }

    /**
     * Cursor pagination, which is why the list is ordered by primary key.
     *
     * A cursor needs a column that is unique and never null. `issued_on` is
     * neither — a draft has no issue date at all — so the order is by id and
     * the page reads newest first.
     */
    public function test_the_list_pages_forward_and_back_without_repeating_a_row(): void
    {
        Invoice::factory()->count(20)->create();

        $numbers = fn ($paginator): array => collect($paginator->items())->pluck('number')->all();

        $page = Livewire::test('accounting::invoices');

        $first = $numbers($page->viewData('invoices'));
        $cursor = $page->viewData('invoices')->nextCursor()->encode();

        $this->assertCount(15, $first);

        $page->call('goToCursor', $cursor);

        $second = $numbers($page->viewData('invoices'));

        $this->assertCount(5, $second);
        $this->assertSame([], array_intersect($first, $second), 'The second page repeated rows from the first.');

        // And back again, to the same fifteen.
        $page->call('goToCursor', $page->viewData('invoices')->previousCursor()->encode());

        $this->assertSame($first, $numbers($page->viewData('invoices')));
    }

    public function test_a_tampered_cursor_is_the_first_page_rather_than_a_stack_trace(): void
    {
        Invoice::factory()->count(3)->create();

        Livewire::test('accounting::invoices')
            ->call('goToCursor', 'not-a-cursor')
            ->assertViewHas('invoices', fn ($invoices): bool => $invoices->count() === 3);
    }

    /* Issuing ------------------------------------------------------------------- */

    public function test_issuing_from_the_edit_page_freezes_the_rate_and_makes_the_invoice_read_only(): void
    {
        $this->recordRates();

        $foreign = Company::factory()->create(['name' => 'Northwind Ltd', 'country' => 'GB', 'is_domestic' => false]);
        $invoice = $this->draft(['company_id' => $foreign->id, 'currency' => Currencies::TRY, 'number' => 'INV-9001']);

        Livewire::test('accounting::invoice-edit', ['invoice' => (string) $invoice->id])
            ->call('issue')
            ->assertRedirect(route('accounting.invoice-show', ['invoice' => $invoice->id]));

        $issued = $invoice->fresh();

        $this->assertNotNull($issued->sent_at, 'Issuing did not mark the invoice as issued.');
        $this->assertSame('sent', $issued->status);

        // 1500 TRY at the inverse of 34.1527 USD/TRY, frozen onto the row.
        $this->assertSame(Currencies::USD, $issued->reporting_currency);
        $this->assertNotNull($issued->reporting_rate);
        $this->assertNotNull($issued->reporting_amount);

        // A rate move afterwards must not change a thing.
        app(ExchangeRates::class)->record(Currencies::USD, Currencies::TRY, '41.900000', 'frankfurter', '2026-07-08');

        $this->assertSame(
            (string) $issued->reporting_rate,
            (string) $invoice->fresh()->reporting_rate,
            'The invoice re-derived its rate from the market.',
        );

        // And the editor now refuses to be an editor.
        $this->get('/accounting/invoices/'.$invoice->id.'/edit')
            ->assertOk()
            ->assertSee('This invoice cannot be edited')
            ->assertDontSee('Issue invoice');
    }

    public function test_an_issued_invoice_cannot_be_saved_from_a_stale_tab(): void
    {
        $this->recordRates();

        $invoice = $this->draft(['number' => 'INV-9002']);

        $page = Livewire::test('accounting::invoice-edit', ['invoice' => (string) $invoice->id]);

        // Issued in another tab while this one was open.
        app(InvoiceIssuer::class)->issue($invoice->fresh());

        $page->set('items.0.unit_price', '9999.00')
            ->call('save')
            ->assertDispatched('toast');

        $this->assertSame(
            '1500.000000',
            (string) $invoice->fresh()->lines()->first()->unit_price,
            'A stale tab edited an issued invoice.',
        );
    }

    public function test_a_draft_saved_from_the_editor_persists_its_lines_and_totals(): void
    {
        $customer = Customer::factory()->create(['name' => 'Marta Sandoval']);

        Livewire::test('accounting::invoice-edit')
            ->set('number', 'INV-9100')
            ->set('customerId', (string) $customer->id)
            ->set('taxPercent', '20')
            ->set('items', [
                ['id' => null, 'description' => 'Discovery workshop', 'quantity' => '2', 'unit_price' => '750.00'],
                ['id' => null, 'description' => 'Implementation', 'quantity' => '8.5', 'unit_price' => '120.00'],
            ])
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $invoice = Invoice::query()->where('number', 'INV-9100')->firstOrFail();

        // 1500 + 1020, then twenty per cent on top. Strings, throughout.
        $this->assertSame('2520.000000', (string) $invoice->subtotal);
        $this->assertSame('504.000000', (string) $invoice->tax_amount);
        $this->assertSame('3024.000000', (string) $invoice->total);
        $this->assertIsString($invoice->total);
        $this->assertCount(2, $invoice->lines);
        $this->assertNull($invoice->sent_at, 'Saving a draft issued it.');
    }

    /* Payments ------------------------------------------------------------------- */

    public function test_recording_a_payment_from_the_show_page_updates_the_status(): void
    {
        $this->recordRates();

        $invoice = app(InvoiceIssuer::class)->issue($this->draft(['number' => 'INV-9003']));

        Livewire::test('accounting::invoice-show', ['invoice' => (string) $invoice->id])
            ->call('openPayment')
            ->set('paymentAmount', '500.00')
            ->set('paymentPaidAt', '2026-07-20')
            ->call('recordPayment')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $this->assertSame('part_paid', $invoice->fresh()->status);

        Livewire::test('accounting::invoice-show', ['invoice' => (string) $invoice->id])
            ->call('openPayment')
            ->set('paymentAmount', '1000.00')
            ->set('paymentPaidAt', '2026-07-21')
            ->call('recordPayment')
            ->assertHasNoErrors();

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_the_show_page_states_the_rate_and_the_date_behind_every_converted_figure(): void
    {
        $this->recordRates();

        $turkish = Company::factory()->create(['name' => 'Harbour & Finch', 'country' => 'TR', 'is_domestic' => true]);

        $invoice = app(InvoiceIssuer::class)->issue(
            $this->draft(['company_id' => $turkish->id, 'number' => 'INV-9004']),
            Currencies::TRY,
        );

        $page = $this->get('/accounting/invoices/'.$invoice->id)->assertOk();

        // The invoice's own currency, with its symbol.
        $page->assertSee('$1,500.00');

        // The reporting figure, marked as converted, with the rate and the date
        // that produced it — never only the converted number.
        $page->assertSee('reporting currency, converted', false);
        $page->assertSee('34.152700');
        $page->assertSee('1 July 2026');

        // And the Turkish figures: the buying rate, its date, the lira amount.
        $page->assertSee('TCMB buying rate 34.081500', false);
        $page->assertSee('tcmb_evds');
        $page->assertSee('₺51,122.25');
    }

    public function test_the_show_page_links_a_crypto_payment_to_the_explorer(): void
    {
        $this->recordRates();

        $invoice = app(InvoiceIssuer::class)->issue(
            $this->draft(['currency' => Currencies::USDT, 'number' => 'INV-9005']),
        );

        $hash = 'b4f1c0d2e39a7c5188f0aa2c4d6e8b0a1f2c3d4e5f60718293a4b5c6d7e8f900';

        Livewire::test('accounting::invoice-show', ['invoice' => (string) $invoice->id])
            ->call('openPayment')
            ->set('paymentAmount', '1500.00')
            ->set('paymentCurrency', Currencies::USDT)
            ->set('paymentMethod', 'crypto')
            ->set('paymentPaidAt', '2026-07-20')
            ->set('chain', 'tron')
            ->set('txHash', $hash)
            ->set('confirmations', '24')
            ->call('recordPayment')
            ->assertHasNoErrors();

        $payment = $invoice->fresh()->payments()->first();

        $this->assertNotNull($payment->chainDetail, 'The on-chain half was not attached.');
        $this->assertSame('TRC-20', $payment->chainDetail->token_standard);

        $this->get('/accounting/invoices/'.$invoice->id)
            ->assertOk()
            ->assertSee($hash)
            ->assertSee('tronscan.org', false)
            ->assertSee('Tron — TRC-20', false);
    }

    public function test_voiding_an_invoice_keeps_it(): void
    {
        $this->recordRates();

        $invoice = app(InvoiceIssuer::class)->issue($this->draft(['number' => 'INV-9006']));

        Livewire::test('accounting::invoice-show', ['invoice' => (string) $invoice->id])
            ->call('openVoid')
            ->call('voidInvoice')
            ->assertDispatched('toast');

        $voided = Invoice::query()->find($invoice->id);

        $this->assertNotNull($voided, 'Voiding deleted the invoice.');
        $this->assertNotNull($voided->voided_at);
        $this->assertSame('void', $voided->status);
        $this->assertSame(1, $voided->lines()->count(), 'Voiding took the lines with it.');
    }

    /* Reversing a payment ------------------------------------------------------------ */

    /**
     * 🔴 The whole point of a reversal: the ledger comes back to where it was.
     *
     * Asserted through `standing()` because that is the scope every report sums
     * from — an assertion on row counts alone would pass for an implementation
     * that reversed the wrong entry, and an assertion on the raw table would
     * pass for one that reversed nothing.
     */
    public function test_reversing_a_payment_puts_the_standing_ledger_back_and_leaves_both_rows(): void
    {
        $this->recordRates();

        $invoice = app(InvoiceIssuer::class)->issue($this->draft(['number' => 'INV-9010']));

        $before = $this->standingTotal();

        $payment = app(PaymentRecorder::class)->record($invoice, '1500.00', Currencies::USD, '2026-07-20');

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNotSame($before, $this->standingTotal(), 'Recording the payment never reached the ledger.');

        Livewire::test('accounting::invoice-show', ['invoice' => (string) $invoice->id])
            ->call('reversePayment', $payment->id)
            ->assertDispatched('toast');

        // The figure every report adds up is exactly what it was.
        $this->assertSame($before, $this->standingTotal());

        // And both rows are still there to be read: the entry that was wrong,
        // and the entry that says so.
        $original = LedgerEntry::query()->whereNull('reverses_id')->latest('id')->firstOrFail();
        $reversal = LedgerEntry::query()->whereNotNull('reverses_id')->latest('id')->firstOrFail();

        $this->assertSame(2, LedgerEntry::query()->count(), 'A ledger row was removed instead of reversed.');
        $this->assertSame('1500.000000', (string) $original->amount, 'The original entry was edited.');
        $this->assertSame('-1500.000000', (string) $reversal->amount);
        $this->assertSame($original->id, $reversal->reverses_id);
        $this->assertSame(LedgerEntry::TYPE_REVERSAL, $reversal->entry_type);

        // The reason names the payment it undoes, so the trail can be followed
        // without joining anything back to a row that is no longer live.
        $this->assertStringContainsString('INV-9010', (string) $reversal->description);

        // The payment is gone from every query the module makes, and readable
        // to anybody who asks for it by name.
        $this->assertNull(Payment::query()->find($payment->id));
        $this->assertNotNull(Payment::withTrashed()->find($payment->id));
        $this->assertSame(0, Payment::query()->count(), 'A reversed payment is still counted as received.');
    }

    /**
     * 🔴 The step a reversal gets wrong.
     *
     * A fully paid invoice whose only payment is taken back has to read as
     * unpaid again *and* lose its `paid_at` — that timestamp is what a cash-flow
     * report uses to decide which month the money arrived in, and one left
     * behind is money reported in a month nothing happened.
     */
    public function test_reversing_the_only_payment_puts_the_invoice_back_to_unpaid(): void
    {
        $this->recordRates();

        $invoice = app(InvoiceIssuer::class)->issue($this->draft(['number' => 'INV-9011']));
        $payment = app(PaymentRecorder::class)->record($invoice, '1500.00', Currencies::USD, '2026-07-20');

        $this->assertNotNull($invoice->fresh()->paid_at);

        Livewire::test('accounting::invoice-show', ['invoice' => (string) $invoice->id])
            ->call('reversePayment', $payment->id);

        $after = $invoice->fresh();

        $this->assertSame('sent', $after->status);
        $this->assertNull($after->paid_at, 'The invoice reads as unpaid but still says when it was paid.');
        $this->assertSame('1500.000000', app(PaymentRecorder::class)->outstanding($after));
    }

    /** One of two payments taken back leaves the other one standing. */
    public function test_reversing_one_of_two_payments_leaves_the_invoice_part_paid(): void
    {
        $this->recordRates();

        $invoice = app(InvoiceIssuer::class)->issue($this->draft(['number' => 'INV-9012']));

        app(PaymentRecorder::class)->record($invoice, '500.00', Currencies::USD, '2026-07-20');
        $second = app(PaymentRecorder::class)->record($invoice, '1000.00', Currencies::USD, '2026-07-21');

        $this->assertSame('paid', $invoice->fresh()->status);

        Livewire::test('accounting::invoice-show', ['invoice' => (string) $invoice->id])
            ->call('reversePayment', $second->id);

        $after = $invoice->fresh();

        $this->assertSame('part_paid', $after->status);
        $this->assertNull($after->paid_at);
        $this->assertSame('1000.000000', app(PaymentRecorder::class)->outstanding($after));

        // 500 standing, 1000 posted and undone.
        $this->assertSame('500.000000', $this->standingTotal());
        $this->assertSame(3, LedgerEntry::query()->count());
    }

    /**
     * The same payment cannot be taken back twice.
     *
     * Reversing a reversal would net back to the original and say nothing —
     * `LedgerEntry::reverse()` refuses it, and the page has to refuse before
     * getting there so the payment row is not soft-deleted on its own.
     */
    public function test_a_payment_cannot_be_reversed_twice(): void
    {
        $this->recordRates();

        $invoice = app(InvoiceIssuer::class)->issue($this->draft(['number' => 'INV-9013']));
        $payment = app(PaymentRecorder::class)->record($invoice, '1500.00', Currencies::USD, '2026-07-20');

        app(PaymentRecorder::class)->reverse($payment);

        Livewire::test('accounting::invoice-show', ['invoice' => (string) $invoice->id])
            ->call('reversePayment', $payment->id)
            ->assertDispatched('toast');

        $this->assertSame(2, LedgerEntry::query()->count(), 'A second reversal was written.');
        $this->assertSame('0.000000', $this->standingTotal());
    }

    /**
     * The chain record survives, and the page still says so.
     *
     * What a wallet did on chain happened, whatever it was applied to. The
     * `crypto_payments` row has no `deleted_at` and is left exactly where it
     * was; re-recording the same hash re-points it at the new payment.
     */
    public function test_reversing_a_crypto_payment_keeps_the_on_chain_record_readable(): void
    {
        $this->recordRates();

        $invoice = app(InvoiceIssuer::class)->issue(
            $this->draft(['currency' => Currencies::USDT, 'number' => 'INV-9014']),
        );

        $hash = 'c7a2e5f18b3d6094a2c4e6f80b1d3f5a7c9e1b3d5f7092a4c6e8b0d2f4a6c8e0';

        Livewire::test('accounting::invoice-show', ['invoice' => (string) $invoice->id])
            ->call('openPayment')
            ->set('paymentAmount', '1500.00')
            ->set('paymentCurrency', Currencies::USDT)
            ->set('paymentMethod', 'crypto')
            ->set('paymentPaidAt', '2026-07-20')
            ->set('chain', 'tron')
            ->set('txHash', $hash)
            ->set('confirmations', '24')
            ->call('recordPayment')
            ->assertHasNoErrors();

        $payment = $invoice->fresh()->payments()->firstOrFail();

        Livewire::test('accounting::invoice-show', ['invoice' => (string) $invoice->id])
            ->call('reversePayment', $payment->id);

        $trashed = Payment::withTrashed()->findOrFail($payment->id);

        $this->assertNotNull($trashed->chainDetail, 'Reversing the payment took the on-chain record with it.');
        $this->assertSame($hash, $trashed->chainDetail->tx_hash);

        // And the correction is legible on the page, not only in the database —
        // the confirmation promised the reversal stays, so something has to show it.
        $this->get('/accounting/invoices/'.$invoice->id)
            ->assertOk()
            ->assertSee('no longer counted against this invoice', false)
            ->assertSee($trashed->chainDetail->shortHash(), false);
    }

    /* Deleting a draft ---------------------------------------------------------------- */

    public function test_a_draft_invoice_can_be_deleted(): void
    {
        $invoice = $this->draft(['number' => 'INV-9020']);

        Livewire::test('accounting::invoice-show', ['invoice' => (string) $invoice->id])
            ->call('deleteInvoice')
            ->assertRedirect(route('accounting.invoices'));

        $this->assertNull(Invoice::query()->find($invoice->id), 'The draft is still in the book.');

        $trashed = Invoice::withTrashed()->findOrFail($invoice->id);

        // Soft, so the unique number stays taken: `invoice_lines.invoice_id`
        // cascades on a *hard* delete only, which is why the lines are still
        // attached and would come back with the row.
        $this->assertNotNull($trashed->deleted_at);
        $this->assertSame(1, $trashed->lines()->count());

        // A draft posts nothing, so nothing had to be reversed.
        $this->assertSame(0, LedgerEntry::query()->count());

        // And the page it leaves behind explains itself rather than 404ing.
        $this->get('/accounting/invoices/'.$invoice->id)->assertOk()->assertSee('That invoice is not here');
    }

    /**
     * 🔴 An issued invoice refuses, out loud.
     *
     * A sequential invoice number is never reused, so an issued invoice is
     * voided and re-issued under a new number — never deleted. Asserted as a
     * refusal rather than as a missing button, because a missing button is one
     * `wire:click` away from being called anyway.
     */
    public function test_an_issued_invoice_refuses_to_be_deleted(): void
    {
        $this->recordRates();

        $invoice = app(InvoiceIssuer::class)->issue($this->draft(['number' => 'INV-9021']));

        Livewire::test('accounting::invoice-show', ['invoice' => (string) $invoice->id])
            ->call('deleteInvoice')
            ->assertNoRedirect()
            ->assertDispatched('toast');

        $this->assertNotNull(Invoice::query()->find($invoice->id), 'An issued invoice was deleted.');
        $this->assertNull(Invoice::withTrashed()->find($invoice->id)->deleted_at);
    }

    /**
     * A voided invoice refuses too, and that is the decision rather than an
     * oversight: it was issued, it consumed a number, and the record of it
     * having been voided is the only thing that accounts for the gap.
     */
    public function test_a_voided_invoice_refuses_to_be_deleted(): void
    {
        $this->recordRates();

        $invoice = app(InvoiceIssuer::class)->issue($this->draft(['number' => 'INV-9022']));

        Livewire::test('accounting::invoice-show', ['invoice' => (string) $invoice->id])
            ->call('openVoid')
            ->call('voidInvoice')
            ->call('deleteInvoice')
            ->assertDispatched('toast');

        $this->assertNotNull(Invoice::query()->find($invoice->id), 'A voided invoice was deleted.');
        $this->assertSame('void', $invoice->fresh()->status);
    }

    /* Recurring --------------------------------------------------------------------- */

    public function test_the_recurring_generator_raises_a_draft_and_never_issues_it(): void
    {
        $schedule = $this->schedule();

        $this->artisan('accounting:generate-recurring')->assertExitCode(0);

        $invoice = Invoice::query()->where('number', 'like', 'INV-R'.$schedule->id.'-%')->firstOrFail();

        $this->assertSame('draft', $invoice->status);
        $this->assertNull($invoice->sent_at, 'The generator issued an invoice. It must only ever raise a draft.');
        $this->assertNull($invoice->reporting_rate, 'A draft froze a rate.');
        $this->assertSame('2400.000000', (string) $invoice->total);
        $this->assertSame(1, $invoice->lines()->count());
    }

    /**
     * The hard requirement for every job in this project.
     *
     * Cron misses runs and cron doubles runs, and a scheduled job that bills a
     * client twice is the kind of bug that costs a relationship.
     */
    public function test_the_recurring_generator_is_idempotent(): void
    {
        $schedule = $this->schedule(['next_run_on' => today()->toDateString()]);

        $this->artisan('accounting:generate-recurring')->assertExitCode(0);

        $afterFirst = Invoice::query()->count();
        $nextRun = $schedule->fresh()->next_run_on->toDateString();
        $lastRun = $schedule->fresh()->last_run_on->toDateString();

        $this->assertSame(1, $afterFirst);
        $this->assertSame(today()->addMonthNoOverflow()->toDateString(), $nextRun, 'The occurrence was not claimed.');

        // The same day, again.
        $this->artisan('accounting:generate-recurring')->assertExitCode(0);

        $this->assertSame($afterFirst, Invoice::query()->count(), 'A second run raised a second invoice.');
        $this->assertSame($nextRun, $schedule->fresh()->next_run_on->toDateString());
        $this->assertSame($lastRun, $schedule->fresh()->last_run_on->toDateString());
    }

    public function test_raising_a_schedule_twice_from_the_page_raises_one_invoice(): void
    {
        $schedule = $this->schedule(['next_run_on' => today()->addDays(10)->toDateString()]);

        $page = Livewire::test('accounting::recurring');

        $page->call('raiseNow', $schedule->id)->assertDispatched('toast');
        $page->call('raiseNow', $schedule->id)->assertDispatched('toast');

        $this->assertSame(
            1,
            Invoice::query()->where('number', 'like', 'INV-R'.$schedule->id.'-%')->count(),
            'An impatient second click raised a second invoice for the same period.',
        );
    }

    public function test_a_paused_schedule_raises_nothing(): void
    {
        $this->schedule(['is_active' => false]);

        $this->artisan('accounting:generate-recurring')->assertExitCode(0);

        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_a_schedule_created_on_the_page_is_stored_and_billable(): void
    {
        $customer = Customer::factory()->create(['name' => 'Helen Weiss']);

        Livewire::test('accounting::recurring')
            ->call('openForm')
            ->set('formTitle', 'Hosting and maintenance')
            ->set('formCustomerId', (string) $customer->id)
            ->set('formCadence', 'quarterly')
            ->set('formNextRunOn', today()->toDateString())
            ->set('formLines', [
                ['description' => 'Managed hosting', 'quantity' => '3', 'unit_price' => '120.00'],
            ])
            ->call('saveSchedule')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $schedule = RecurringInvoice::query()->where('title', 'Hosting and maintenance')->firstOrFail();

        $this->assertSame('360.000000', $schedule->estimatedTotal());
        $this->assertSame('$360.00', $schedule->formattedTotal());

        $this->artisan('accounting:generate-recurring')->assertExitCode(0);

        $invoice = Invoice::query()->where('number', 'like', 'INV-R'.$schedule->id.'-%')->firstOrFail();

        $this->assertSame('360.000000', (string) $invoice->total);
        $this->assertSame(
            today()->addMonthsNoOverflow(3)->toDateString(),
            $schedule->fresh()->next_run_on->toDateString(),
        );
    }

    /* Honesty ------------------------------------------------------------------------ */

    public function test_no_invoice_page_claims_to_be_unfinished(): void
    {
        $offenders = [];

        foreach (self::PAGES as $file) {
            $path = base_path('Modules/Accounting/resources/views/components/'.$file);

            $this->assertFileExists($path);

            if (str_contains(file_get_contents($path), 'Not connected yet')) {
                $offenders[] = $file;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A page still says it is not connected:\n".implode("\n", $offenders),
        );
    }

    public function test_every_invoice_page_reaches_the_database(): void
    {
        // The fixtures these pages were built on are gone. The cheapest proof
        // is that each one names a model it could only have got from the layer
        // below it.
        foreach (self::PAGES as $file) {
            $source = file_get_contents(base_path('Modules/Accounting/resources/views/components/'.$file));

            $this->assertStringContainsString(
                'Modules\Accounting\\',
                $source,
                $file.' does not reference the Accounting module at all — it is still a fixture.',
            );
        }
    }
}
