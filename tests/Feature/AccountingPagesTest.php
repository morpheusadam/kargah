<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Models\RecurringInvoice;
use Modules\Accounting\Services\ExchangeRates;
use Modules\Accounting\Services\PaymentRecorder;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;
use Modules\Project\Models\Card;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The expense, client and report pages, reading and writing real rows.
 *
 * These pages used to draw fixtures, so the thing worth testing is not that
 * they render — `SmokeTest` covers that — but that what they render came out of
 * the database and that what they claim to save is actually saved.
 *
 * Every figure asserted here is a decimal string. A page that shows a total one
 * cent out looks exactly like a page that does not, which is why the assertions
 * are on the rendered text rather than on an internal array.
 */
class AccountingPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /** The pages this suite owns. */
    public static function pageProvider(): array
    {
        return [
            'expenses' => ['/accounting/expenses'],
            'expense create' => ['/accounting/expenses/create'],
            'clients' => ['/accounting/clients'],
            'client show' => ['/accounting/clients/1'],
            'reports' => ['/accounting/reports'],
        ];
    }

    /* Rendering ------------------------------------------------------------- */

    #[DataProvider('pageProvider')]
    public function test_each_page_renders_against_the_seeder(string $url): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->get($url)->assertOk();
    }

    /**
     * The harder half: an install with nothing in it.
     *
     * An empty database is what a page sees on its first day, and it is where a
     * total divides by a peak of zero and a client id points at nothing.
     */
    #[DataProvider('pageProvider')]
    public function test_each_page_renders_against_an_empty_database(string $url): void
    {
        $this->get($url)->assertOk();
    }

    public function test_a_client_that_does_not_exist_gets_an_empty_state_rather_than_a_five_hundred(): void
    {
        $this->get('/accounting/clients/999999')
            ->assertOk()
            ->assertSee('That client is not here');
    }

    public function test_the_expenses_page_shows_real_rows_and_totals_them_in_php(): void
    {
        $this->expense('Hostinger', 'Hosting', '71.88', days: 3);
        $this->expense('KeenThemes', 'Software', '49.00', days: 5);

        $this->get('/accounting/expenses')
            ->assertOk()
            ->assertSee('Hostinger')
            ->assertSee('KeenThemes')
            // 71.88 + 49.00, added through Money rather than by SQL.
            ->assertSee('$120.88');
    }

    public function test_the_expenses_page_says_so_when_there_is_nothing_to_show(): void
    {
        $this->get('/accounting/expenses')
            ->assertOk()
            ->assertSee('Nothing recorded yet');
    }

    public function test_the_expenses_page_marks_what_is_recoverable_and_what_has_been_rebilled(): void
    {
        $customer = $this->customerWithCompany();
        $invoice = $this->invoice($customer, '500.00', issuedDaysAgo: 20);

        $this->expense('Figma', 'Software', '45.00', days: 4, attributes: ['is_billable' => true]);
        $this->expense('DigitalOcean', 'Hosting', '120.00', days: 8, attributes: [
            'is_billable' => true,
            'rebilled_on_invoice_id' => $invoice->id,
        ]);
        $this->expense('Apple Store', 'Hardware', '1299.00', days: 12);

        $this->get('/accounting/expenses')
            ->assertOk()
            ->assertSee('Recoverable')
            ->assertSee('Rebilled')
            ->assertSee('Absorbed')
            // Only Figma is billable and not yet on an invoice.
            ->assertSee('$45.00');
    }

    public function test_the_expenses_page_filters_by_category(): void
    {
        $this->expense('Hostinger', 'Hosting', '71.88', days: 3);
        $this->expense('KeenThemes', 'Software', '49.00', days: 5);

        Livewire::test('accounting::expenses')
            ->set('category', 'Hosting')
            ->assertSee('Hostinger')
            ->assertDontSee('KeenThemes');
    }

    /* Saving an expense ------------------------------------------------------- */

    public function test_saving_an_expense_persists_it(): void
    {
        Livewire::test('accounting::expense-edit')
            ->set('vendor', 'Hostinger')
            ->set('category', 'Hosting')
            ->set('amount', '71.88')
            ->set('currency', Currencies::USD)
            ->set('spentOn', now()->toDateString())
            ->set('receiptReference', 'HG-2026-07-981')
            ->call('save')
            ->assertRedirect(route('accounting.expenses'));

        $expense = Expense::query()->firstOrFail();

        $this->assertSame('Hostinger', $expense->vendor);
        $this->assertSame('71.880000', (string) $expense->amount);
        $this->assertSame('HG-2026-07-981', $expense->receipt_reference);
        $this->assertIsString($expense->amount, 'The amount came back as a number, which means a float got in.');
    }

    /**
     * The reporting figure is frozen at save, exactly as an invoice freezes its
     * own. Re-deriving it at read time would make last March's cost move every
     * time the lira does.
     */
    public function test_saving_an_expense_freezes_its_reporting_figure_at_the_rate_for_the_date(): void
    {
        $spentOn = now()->subDays(3)->toDateString();

        // Only USD/TRY is stored; the reader inverts it for TRY/USD.
        app(ExchangeRates::class)->record(Currencies::USD, Currencies::TRY, '40.000000', 'frankfurter', $spentOn);

        Livewire::test('accounting::expense-edit')
            ->set('vendor', 'Harbour ofis kirası')
            ->set('category', 'Other')
            ->set('amount', '1000.00')
            ->set('currency', Currencies::TRY)
            ->set('spentOn', $spentOn)
            ->call('save');

        $expense = Expense::query()->firstOrFail();

        $this->assertSame('1000.000000', (string) $expense->amount);
        $this->assertSame(Currencies::USD, $expense->reporting_currency);
        $this->assertSame('0.025000', (string) $expense->reporting_rate);
        $this->assertSame('25.000000', (string) $expense->reporting_amount);

        // And it stays put when the market moves.
        app(ExchangeRates::class)->record(Currencies::USD, Currencies::TRY, '50.000000', 'frankfurter', now());

        $this->assertSame('25.000000', (string) $expense->fresh()->reporting_amount);
    }

    /**
     * "When no rate is available, leave it null and say so rather than
     * inventing one."
     */
    public function test_an_expense_with_no_rate_available_is_saved_with_no_reporting_figure(): void
    {
        Livewire::test('accounting::expense-edit')
            ->set('vendor', 'Beşiktaş muhasebeci')
            ->set('category', 'Other')
            ->set('amount', '2500.00')
            ->set('currency', Currencies::TRY)
            ->set('spentOn', '2020-01-01')
            ->call('save');

        $expense = Expense::query()->firstOrFail();

        $this->assertSame('2500.000000', (string) $expense->amount);
        $this->assertNull($expense->reporting_rate, 'A rate was invented for a date with no rate on file.');
        $this->assertNull($expense->reporting_amount);
    }

    public function test_saving_an_expense_without_an_amount_saves_nothing(): void
    {
        Livewire::test('accounting::expense-edit')
            ->set('vendor', 'Namecheap')
            ->set('amount', '')
            ->call('save')
            ->assertHasErrors('amount');

        $this->assertSame(0, Expense::query()->count());
    }

    public function test_save_and_add_another_keeps_the_form_open_for_the_next_one(): void
    {
        Livewire::test('accounting::expense-edit')
            ->set('vendor', 'Amazon SES')
            ->set('category', 'Email')
            ->set('amount', '12.40')
            ->call('saveAndAddAnother')
            ->assertSet('vendor', '')
            ->assertSet('amount', '')
            // The category and date survive: one expense usually means three.
            ->assertSet('category', 'Email');

        $this->assertSame(1, Expense::query()->count());
    }

    /* Deleting an expense ------------------------------------------------------- */

    public function test_deleting_an_expense_that_posted_nothing_to_the_ledger_removes_it(): void
    {
        $expense = $this->expense('Namecheap', 'Domains', '14.98', days: 2);

        Livewire::test('accounting::expense-edit', ['expense' => (string) $expense->id])
            ->call('delete')
            ->assertRedirect(route('accounting.expenses'));

        $this->assertNull(Expense::query()->find($expense->id), 'The expense survived its own deletion.');
        $this->assertNotNull(
            Expense::withTrashed()->find($expense->id),
            'The expense was hard-deleted. `deleted_at` exists so the row stays readable.',
        );
    }

    /**
     * 🔴 The whole point of the reversal.
     *
     * Not "an entry was written" — that would pass for a contra entry of the
     * wrong sign, the wrong amount or against the wrong row. What has to be true
     * is that `LedgerEntry::standing()`, which is what a balance is summed from,
     * adds up to exactly what it did before the expense ever existed. Both rows
     * stay in the table; neither counts.
     */
    public function test_deleting_an_expense_reverses_its_ledger_entry_and_the_standing_balance_returns(): void
    {
        // An unrelated entry, so "the balance came back" cannot be satisfied by
        // the ledger simply being empty at both ends.
        LedgerEntry::query()->create([
            'entry_type' => LedgerEntry::TYPE_ADJUSTMENT,
            'currency' => Currencies::USD,
            'amount' => '250.000000',
            'description' => 'Opening balance',
            'occurred_at' => now()->subMonth(),
        ]);

        $before = $this->standingTotal();

        $expense = $this->expense('Hostinger', 'Hosting', '71.88', days: 3);

        LedgerEntry::query()->create([
            'entry_type' => LedgerEntry::TYPE_EXPENSE,
            'reference_type' => $expense->getMorphClass(),
            'reference_id' => $expense->id,
            'currency' => Currencies::USD,
            // Money out is a negative entry, exactly as the seeder writes it.
            'amount' => '-71.880000',
            'description' => 'Hostinger — Hosting',
            'occurred_at' => $expense->spent_on,
        ]);

        $this->assertNotSame($before, $this->standingTotal(), 'The expense entry never counted, so the test proves nothing.');

        Livewire::test('accounting::expense-edit', ['expense' => (string) $expense->id])->call('delete');

        $this->assertSame($before, $this->standingTotal(), 'The standing balance did not come back after the reversal.');

        // Reversed, not deleted: the original and its contra both remain.
        $this->assertSame(
            2,
            LedgerEntry::query()->where('reference_id', $expense->id)->where('reference_type', $expense->getMorphClass())->count(),
            'The trail is a gap rather than a correction.',
        );
        $this->assertSame(1, LedgerEntry::query()->ofType(LedgerEntry::TYPE_REVERSAL)->count());
        $this->assertNotNull(Expense::withTrashed()->find($expense->id)->deleted_at);
    }

    /**
     * A cost already passed on to a client stays put.
     *
     * Asserted on behaviour — the row is still there and the ledger did not move
     * — rather than on the wording of the refusal, which somebody should be free
     * to improve.
     */
    public function test_an_expense_already_rebilled_onto_an_invoice_is_not_deleted(): void
    {
        $customer = $this->customerWithCompany();
        $invoice = $this->invoice($customer, '500.00', issuedDaysAgo: 20);

        $expense = $this->expense('DigitalOcean', 'Hosting', '120.00', days: 8, attributes: [
            'is_billable' => true,
            'rebilled_on_invoice_id' => $invoice->id,
        ]);

        $entries = LedgerEntry::query()->count();

        Livewire::test('accounting::expense-edit', ['expense' => (string) $expense->id])
            ->call('delete')
            ->assertNoRedirect();

        $this->assertNotNull(Expense::query()->find($expense->id), 'A cost on an issued invoice was deleted anyway.');
        $this->assertNull($expense->fresh()->deleted_at);
        $this->assertSame($entries, LedgerEntry::query()->count(), 'A refused delete still wrote to the ledger.');
    }

    /** The list is the only way to the editor, and so the only way to a delete. */
    public function test_the_expenses_list_links_each_row_to_its_editor(): void
    {
        $expense = $this->expense('Figma', 'Software', '45.00', days: 4);

        $this->get('/accounting/expenses')
            ->assertOk()
            ->assertSee(route('accounting.expense-edit', ['expense' => $expense->id]), escape: false);
    }

    /**
     * What `LedgerEntry::standing()` adds up to, in USD, as a decimal string.
     *
     * Added through `Money`, never `SUM()`: a decimal column on SQLite has
     * NUMERIC affinity, so a SQL sum of money is a sum of doubles and this
     * assertion would pass or fail on the last bit of a float.
     */
    private function standingTotal(): string
    {
        return Money::toStorage(Money::sum(
            LedgerEntry::query()->standing()->get()->map(fn (LedgerEntry $entry): string => (string) $entry->amount),
            Currencies::USD,
        ));
    }

    /* Clients ------------------------------------------------------------------ */

    /**
     * The figure a client sees is the figure the invoices say, to the cent.
     */
    public function test_a_clients_outstanding_total_matches_what_the_invoices_actually_say(): void
    {
        $customer = $this->customerWithCompany();

        $paidInPart = $this->invoice($customer, '1500.00', issuedDaysAgo: 30);
        $this->invoice($customer, '500.00', issuedDaysAgo: 10);

        app(PaymentRecorder::class)->record($paidInPart, '500.00', Currencies::USD, now()->subDays(2));

        // 1500 billed less 500 received, plus 500 untouched.
        $this->get('/accounting/clients')
            ->assertOk()
            ->assertSee('Sam Okafor')
            ->assertSee('$2,000.00')   // billed to date
            ->assertSee('$1,500.00');  // outstanding

        $this->get('/accounting/clients/'.$customer->id)
            ->assertOk()
            ->assertSee('$2,000.00')
            ->assertSee('$1,500.00');
    }

    public function test_a_client_with_nothing_owed_says_so_rather_than_showing_a_zero(): void
    {
        $customer = $this->customerWithCompany();
        $invoice = $this->invoice($customer, '800.00', issuedDaysAgo: 20);

        app(PaymentRecorder::class)->record($invoice, '800.00', Currencies::USD, now()->subDays(1));

        $this->get('/accounting/clients')
            ->assertOk()
            ->assertSee('Nothing owed');
    }

    public function test_the_clients_page_hides_archived_clients_until_asked(): void
    {
        $this->customerWithCompany();
        Customer::factory()->create(['name' => 'Retired Contact', 'archived_at' => now()]);

        Livewire::test('accounting::clients')
            ->assertSee('Sam Okafor')
            ->assertDontSee('Retired Contact')
            ->call('setFilter', 'archived')
            ->assertSee('Retired Contact')
            ->assertDontSee('Sam Okafor')
            // Silent by design: the list the filter changed is the notification.
            ->assertNotDispatched('toast');
    }

    public function test_adding_a_client_persists(): void
    {
        $company = Company::factory()->create(['name' => 'Northwind Ltd']);

        Livewire::test('accounting::clients')
            ->call('startAdding')
            ->set('newName', 'Helen Vasquez')
            ->set('newEmail', 'helen@northwind.example')
            ->set('newCompanyId', (string) $company->id)
            ->call('addClient')
            ->assertSet('creating', false);

        $customer = Customer::query()->where('email', 'helen@northwind.example')->firstOrFail();

        $this->assertSame('Helen Vasquez', $customer->name);
        $this->assertSame($company->id, $customer->company_id);
    }

    /* One client's page --------------------------------------------------------- */

    public function test_archiving_a_client_persists_and_can_be_undone(): void
    {
        $customer = $this->customerWithCompany();

        $component = Livewire::test('accounting::client-show', ['client' => (string) $customer->id])
            ->call('archive');

        $this->assertNotNull($customer->fresh()->archived_at, 'Archiving a client changed nothing.');

        $component->call('archive');

        $this->assertNull($customer->fresh()->archived_at, 'Restoring a client changed nothing.');
    }

    /* Deleting a client ---------------------------------------------------------- */

    /**
     * The invoice carries no snapshot of who it was for — only `customer_id` —
     * so a deleted client is an invoice that cannot name its own buyer.
     */
    public function test_a_client_with_invoices_is_refused_rather_than_deleted(): void
    {
        $customer = $this->customerWithCompany();
        $this->invoice($customer, '1200.00', issuedDaysAgo: 15);

        $component = Livewire::test('accounting::client-show', ['client' => (string) $customer->id]);

        $this->assertFalse($component->viewData('deletable'), 'The page offered to delete a client it will refuse.');

        $component->call('delete')->assertNoRedirect();

        $this->assertNotNull(Customer::withTrashed()->find($customer->id), 'A client with invoices was deleted.');
        $this->assertNull($customer->fresh()->archived_at, 'A refused delete archived the client behind their back.');
    }

    /** A voided or soft-deleted invoice is still a document with their name on it. */
    public function test_a_client_whose_only_invoice_was_deleted_is_still_refused(): void
    {
        $customer = $this->customerWithCompany();
        $this->invoice($customer, '600.00', issuedDaysAgo: 40)->delete();

        Livewire::test('accounting::client-show', ['client' => (string) $customer->id])
            ->call('delete')
            ->assertNoRedirect();

        $this->assertNotNull(Customer::withTrashed()->find($customer->id));
    }

    public function test_a_client_with_a_recurring_schedule_is_refused(): void
    {
        $customer = $this->customerWithCompany();

        RecurringInvoice::query()->create([
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'title' => 'Monthly retainer',
            'currency' => Currencies::USD,
            'cadence' => 'monthly',
            'next_run_on' => now()->addMonth()->toDateString(),
            'lines' => [['description' => 'Retainer', 'quantity' => '1', 'unit_price' => '900.00']],
        ]);

        Livewire::test('accounting::client-show', ['client' => (string) $customer->id])
            ->call('delete')
            ->assertNoRedirect();

        $this->assertNotNull(Customer::withTrashed()->find($customer->id));
    }

    /**
     * A client is not Accounting's alone. Project holds cards against one, and a
     * card whose client vanished is the same defect as an invoice's.
     */
    public function test_a_client_a_card_still_points_at_is_refused(): void
    {
        $customer = $this->customerWithCompany();

        Card::factory()->create(['customer_id' => $customer->id]);

        Livewire::test('accounting::client-show', ['client' => (string) $customer->id])
            ->call('delete')
            ->assertNoRedirect();

        $this->assertNotNull(Customer::withTrashed()->find($customer->id));
    }

    /**
     * The one case delete exists for: a client typed in by mistake.
     *
     * `forceDelete`, not a soft one — `⚡clients` validates the email as
     * `unique:customers,email`, which counts a soft-deleted row, so a soft
     * delete would hold the mistyped address hostage while claiming the client
     * was gone. The assertion is that the address can be used again.
     */
    public function test_a_client_nothing_refers_to_is_deleted_and_frees_their_email(): void
    {
        $customer = Customer::factory()->create([
            'name' => 'Hlen Vasquez',
            'email' => 'helen@northwind.example',
            'archived_at' => null,
        ]);

        $component = Livewire::test('accounting::client-show', ['client' => (string) $customer->id]);

        $this->assertTrue($component->viewData('deletable'), 'The page will not offer a delete it would allow.');

        $component->call('delete')->assertRedirect(route('accounting.clients'));

        $this->assertNull(
            Customer::withTrashed()->find($customer->id),
            'The client was only soft-deleted, so their email address is still spoken for.',
        );

        Livewire::test('accounting::clients')
            ->call('startAdding')
            ->set('newName', 'Helen Vasquez')
            ->set('newEmail', 'helen@northwind.example')
            ->call('addClient')
            ->assertHasNoErrors('newEmail');

        $this->assertSame('Helen Vasquez', Customer::query()->where('email', 'helen@northwind.example')->firstOrFail()->name);
    }

    public function test_adding_a_note_persists_against_the_client(): void
    {
        $customer = $this->customerWithCompany();

        Livewire::test('accounting::client-show', ['client' => (string) $customer->id])
            ->set('tab', 'notes')
            ->set('draftNote', 'Procurement pay on the 15th and the last working day only.')
            ->call('addNote')
            ->assertSet('draftNote', '')
            ->assertSee('Procurement pay on the 15th');

        $this->assertStringContainsString(
            'Procurement pay on the 15th and the last working day only.',
            (string) $customer->fresh()->notes,
        );
    }

    public function test_a_second_note_does_not_overwrite_the_first(): void
    {
        $customer = $this->customerWithCompany();
        $customer->forceFill(['notes' => 'Signed the master services agreement.'])->save();

        Livewire::test('accounting::client-show', ['client' => (string) $customer->id])
            ->set('draftNote', 'Rate reviewed each January.')
            ->call('addNote');

        $notes = (string) $customer->fresh()->notes;

        $this->assertStringContainsString('Signed the master services agreement.', $notes);
        $this->assertStringContainsString('Rate reviewed each January.', $notes);
    }

    public function test_a_clients_invoices_and_expenses_tabs_read_real_rows(): void
    {
        $customer = $this->customerWithCompany();
        $invoice = $this->invoice($customer, '1200.00', issuedDaysAgo: 15);

        $this->expense('Figma', 'Software', '45.00', days: 6, attributes: [
            'company_id' => $customer->company_id,
            'is_billable' => true,
            'rebilled_on_invoice_id' => $invoice->id,
        ]);

        $this->get('/accounting/clients/'.$customer->id.'?tab=invoices')
            ->assertOk()
            ->assertSee($invoice->number)
            ->assertSee('$1,200.00');

        $this->get('/accounting/clients/'.$customer->id.'?tab=expenses')
            ->assertOk()
            ->assertSee('Figma')
            ->assertSee('$45.00')
            ->assertSee('Rebilled');
    }

    /* Reports -------------------------------------------------------------------- */

    public function test_the_reports_page_totals_match_what_the_invoices_and_expenses_say(): void
    {
        $customer = $this->customerWithCompany();

        $this->invoice($customer, '1000.00', issuedDaysAgo: 40);
        $this->invoice($customer, '500.00', issuedDaysAgo: 20);
        $this->expense('Hostinger', 'Hosting', '200.00', days: 10);

        $this->get('/accounting/reports?period=all')
            ->assertOk()
            ->assertSee('$1,500.00')   // invoiced
            ->assertSee('$200.00')     // expenses
            ->assertSee('$1,300.00');  // invoiced less expenses
    }

    public function test_the_reports_page_ages_what_is_still_owed(): void
    {
        $customer = $this->customerWithCompany();

        // Issued ninety days ago on thirty-day terms: sixty days late.
        $this->invoice($customer, '980.00', issuedDaysAgo: 90);
        // Issued last week on the same terms: not due for another three.
        $this->invoice($customer, '320.00', issuedDaysAgo: 7);

        $ageing = collect(Livewire::test('accounting::reports')->viewData('ageing'))
            ->keyBy('label');

        $this->assertSame('$320.00', $ageing['Not due yet']['total']);
        $this->assertSame('$0.00', $ageing['1 to 30 days late']['total']);
        $this->assertSame('$980.00', $ageing['31 to 60 days late']['total']);
        $this->assertSame('$0.00', $ageing['Over 60 days late']['total']);
    }

    /**
     * "Unrealised revaluation is a report, not a row."
     *
     * `InvoicingTest` asserts the service writes nothing. This asserts the page
     * that shows the service's answer writes nothing either — a report that
     * quietly books a gain is worse than no report.
     */
    public function test_the_unrealised_section_writes_no_ledger_rows(): void
    {
        $customer = $this->customerWithCompany();
        $issuedOn = now()->subDays(30);

        app(ExchangeRates::class)->record(Currencies::USD, Currencies::TRY, '40.000000', 'frankfurter', $issuedOn);
        app(ExchangeRates::class)->record(Currencies::USD, Currencies::TRY, '50.000000', 'frankfurter', now());

        $invoice = $this->invoice($customer, '40000.00', issuedDaysAgo: 30, attributes: [
            'currency' => Currencies::TRY,
            'reporting_currency' => Currencies::USD,
            'reporting_rate' => '0.025000',
            'reporting_amount' => '1000.000000',
        ]);

        $before = LedgerEntry::query()->count();

        $this->get('/accounting/reports?period=all')
            ->assertOk()
            ->assertSee('Unrealised revaluation')
            ->assertSee($invoice->number)
            // 40,000 TRY frozen at 0.025 is $1,000; at today's 0.02 it is $800.
            ->assertSee('$1,000.00')
            ->assertSee('$800.00');

        $this->assertSame(
            $before,
            LedgerEntry::query()->count(),
            'Rendering the unrealised revaluation wrote to the ledger. Nothing has happened yet.',
        );
    }

    public function test_the_reports_page_breaks_the_period_down_by_currency(): void
    {
        $customer = $this->customerWithCompany();

        $this->invoice($customer, '1000.00', issuedDaysAgo: 10);
        $this->invoice($customer, '18000.00', issuedDaysAgo: 12, attributes: ['currency' => Currencies::TRY]);

        $this->get('/accounting/reports?period=all')
            ->assertOk()
            ->assertSee('By currency')
            ->assertSee('$1,000.00')
            ->assertSee('₺18,000.00');
    }

    public function test_the_reports_page_holds_up_against_the_seeder(): void
    {
        $this->seed(DatabaseSeeder::class);

        $before = LedgerEntry::query()->count();

        $this->get('/accounting/reports?period=all')
            ->assertOk()
            ->assertSee('Receivables by age')
            ->assertSee('Realised foreign exchange');

        $this->assertSame($before, LedgerEntry::query()->count(), 'Reading a report wrote to the ledger.');
    }

    /* The promise the fixtures made -------------------------------------------- */

    /**
     * Every "Not connected yet" was a page apologising for itself. There should
     * be none left in the pages this suite owns.
     */
    public function test_no_page_still_says_it_is_not_connected(): void
    {
        $offenders = [];

        foreach ($this->ownedPages() as $path) {
            if (str_contains((string) file_get_contents($path), 'Not connected yet')) {
                $offenders[] = basename($path);
            }
        }

        $this->assertSame([], $offenders, "A page is still apologising instead of doing the thing:\n".implode("\n", $offenders));
    }

    public function test_no_page_formats_money_by_hand(): void
    {
        $offenders = [];

        foreach ($this->ownedPages() as $path) {
            foreach (['number_format(', '(float)', 'floatval(', 'round('] as $needle) {
                if (str_contains((string) file_get_contents($path), $needle)) {
                    $offenders[] = basename($path).' uses '.$needle;
                }
            }
        }

        $this->assertSame([], $offenders, "Money is formatted through Money::format(), never by hand:\n".implode("\n", $offenders));
    }

    /** @return list<string> */
    private function ownedPages(): array
    {
        $directory = base_path('Modules/Accounting/resources/views/components');

        return array_map(
            fn (string $name): string => $directory.DIRECTORY_SEPARATOR.'⚡'.$name.'.blade.php',
            ['expenses', 'expense-edit', 'clients', 'client-show', 'reports'],
        );
    }

    /* Fixtures ------------------------------------------------------------------- */

    private function customerWithCompany(): Customer
    {
        $company = Company::factory()->create([
            'name' => 'Northwind Ltd',
            'country' => 'GB',
            'is_domestic' => false,
            'default_currency' => Currencies::USD,
        ]);

        return Customer::factory()->for($company)->create([
            'name' => 'Sam Okafor',
            'email' => 'sam@northwind.example',
            'archived_at' => null,
        ]);
    }

    /** An issued invoice, which is the only kind anybody owes anything on. */
    private function invoice(Customer $customer, string $total, int $issuedDaysAgo, array $attributes = []): Invoice
    {
        $issuedOn = now()->subDays($issuedDaysAgo);

        return Invoice::query()->create(array_merge([
            'number' => 'INV-'.str_pad((string) (Invoice::query()->count() + 1), 4, '0', STR_PAD_LEFT),
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'status' => 'sent',
            'currency' => Currencies::USD,
            'subtotal' => $total,
            'tax_percent' => '0',
            'tax_amount' => '0',
            'total' => $total,
            'reporting_currency' => Currencies::USD,
            'reporting_rate' => '1.000000',
            'reporting_amount' => $total,
            'issued_on' => $issuedOn->toDateString(),
            'due_on' => $issuedOn->copy()->addDays(30)->toDateString(),
            'sent_at' => $issuedOn,
            'created_by' => $this->user->id,
        ], $attributes));
    }

    private function expense(string $vendor, string $category, string $amount, int $days, array $attributes = []): Expense
    {
        return Expense::query()->create(array_merge([
            'vendor' => $vendor,
            'category' => $category,
            'currency' => Currencies::USD,
            'amount' => $amount,
            'reporting_currency' => Currencies::USD,
            'reporting_rate' => '1.000000',
            'reporting_amount' => $amount,
            'spent_on' => now()->subDays($days)->toDateString(),
            'created_by' => $this->user->id,
        ], $attributes));
    }
}
