<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Database\Seeders\AccountingDatabaseSeeder;
use Modules\Accounting\Models\CryptoPayment;
use Modules\Accounting\Models\Currency;
use Modules\Accounting\Models\ExchangeRate;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\InvoiceLine;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;
use Modules\Core\Models\Link;
use Modules\Core\Support\MorphMap;
use Tests\TestCase;

/**
 * The model layer of the money module.
 *
 * These are not "does Eloquent work" tests. Each one pins a property the rest
 * of the module is entitled to assume and that nothing else would catch if it
 * quietly stopped holding: that money leaves a model as a string, that the
 * ledger cannot be edited, and that an invoice can be deleted without taking
 * the record of what was received against it.
 */
class AccountingModelTest extends TestCase
{
    use RefreshDatabase;

    /* Money never comes back as a float ------------------------------------- */

    /**
     * The single property everything else rests on.
     *
     * A `decimal:6` cast hands back a string. If any of these ever came back as
     * a float, the money layer would be undone at the first `$invoice->total`
     * and nothing downstream would notice until a total was wrong.
     */
    public function test_every_money_attribute_on_an_invoice_reads_back_as_a_string(): void
    {
        $invoice = Invoice::factory()->taxed('20')->paid()->create();

        $fresh = $invoice->fresh();

        foreach (['subtotal', 'tax_percent', 'tax_amount', 'total', 'reporting_rate', 'reporting_amount'] as $attribute) {
            $this->assertIsString($fresh->{$attribute}, $attribute.' must read back as a string, not a float');
            $this->assertIsNotFloat($fresh->{$attribute});
        }

        // Six decimals, because that is the storage scale — not two, and not
        // however many PHP felt like printing.
        $this->assertMatchesRegularExpression('/^-?\d+\.\d{6}$/', $fresh->total);
    }

    public function test_every_money_attribute_on_a_line_a_payment_and_an_expense_reads_back_as_a_string(): void
    {
        $line = InvoiceLine::factory()->create()->fresh();
        $payment = Payment::factory()->create()->fresh();
        $expense = Expense::factory()->create()->fresh();
        $chain = CryptoPayment::factory()->confirmed()->create()->fresh();
        $rate = ExchangeRate::factory()->create()->fresh();
        $entry = LedgerEntry::factory()->create()->fresh();

        foreach (['quantity', 'unit_price', 'amount'] as $attribute) {
            $this->assertIsString($line->{$attribute});
        }

        foreach (['amount', 'settlement_rate', 'applied_amount', 'fx_gain_loss'] as $attribute) {
            $this->assertIsString($payment->{$attribute});
        }

        foreach (['amount', 'reporting_rate', 'reporting_amount'] as $attribute) {
            $this->assertIsString($expense->{$attribute});
        }

        $this->assertIsString($chain->amount);
        $this->assertIsString($chain->network_fee);
        $this->assertIsString($rate->rate);
        $this->assertIsString($entry->amount);
        $this->assertIsString($entry->reporting_amount);

        // The ordering key is decimal(20,10), not decimal(20,6), and not a float.
        $this->assertIsString($line->position);
        $this->assertMatchesRegularExpression('/^-?\d+\.\d{10}$/', $line->position);
    }

    public function test_a_factory_made_invoice_round_trips_its_total_through_the_money_layer(): void
    {
        $invoice = Invoice::factory()->taxed('18')->create()->fresh();

        $stored = (string) $invoice->total;

        $this->assertSame(
            $stored,
            Money::toStorage(Money::fromStorage($stored, $invoice->currency)),
            'a stored total must survive a trip through Money unchanged',
        );

        // And the total really is the subtotal plus the tax, not a third number.
        $this->assertSame(
            $stored,
            Money::toStorage(
                Money::fromStorage((string) $invoice->subtotal, $invoice->currency)
                    ->plus(Money::fromStorage((string) $invoice->tax_amount, $invoice->currency), Money::ROUNDING),
            ),
        );
    }

    public function test_a_factory_made_line_amount_is_the_product_of_its_quantity_and_unit_price(): void
    {
        $line = InvoiceLine::factory()->create()->fresh();

        $this->assertSame((string) $line->amount, $line->computedAmount(Currencies::USD));
    }

    /* The ledger is append only --------------------------------------------- */

    public function test_reversing_an_entry_leaves_the_original_untouched_and_writes_a_negated_row(): void
    {
        $entry = LedgerEntry::factory()->create([
            'currency' => Currencies::USD,
            'amount' => '1250.500000',
            'reporting_currency' => Currencies::USD,
            'reporting_amount' => '1250.500000',
        ]);

        $before = $entry->fresh()->toArray();

        $reversal = $entry->reverse('Duplicate payment recorded in error.');

        $this->assertSame('-1250.500000', $reversal->fresh()->amount);
        $this->assertSame('-1250.500000', $reversal->fresh()->reporting_amount);
        $this->assertSame(LedgerEntry::TYPE_REVERSAL, $reversal->entry_type);
        $this->assertSame($entry->id, $reversal->reverses_id);
        $this->assertSame('Duplicate payment recorded in error.', $reversal->description);

        // The original is evidence. It does not change because it was wrong.
        $this->assertSame($before, $entry->fresh()->toArray());
        $this->assertSame('1250.500000', $entry->fresh()->amount);
        $this->assertTrue($entry->fresh()->isReversed());
        $this->assertTrue($reversal->isReversal());

        // Two rows, and they net to nothing.
        $this->assertSame(2, LedgerEntry::count());
        $this->assertSame('0.000000', Money::toStorage(
            Money::sum(LedgerEntry::pluck('amount')->map(fn ($a): string => (string) $a), Currencies::USD),
        ));
    }

    public function test_the_reversal_carries_the_same_reference_as_the_entry_it_undoes(): void
    {
        $invoice = Invoice::factory()->create();
        $entry = LedgerEntry::factory()->forReference($invoice)->create();

        $reversal = $entry->reverse();

        $this->assertSame('invoice', $reversal->reference_type);
        $this->assertSame($invoice->id, (int) $reversal->reference_id);
        $this->assertCount(2, $invoice->ledgerEntries()->get());
        $this->assertCount(0, LedgerEntry::standing()->get());
    }

    public function test_an_entry_cannot_be_reversed_twice(): void
    {
        $entry = LedgerEntry::factory()->create();
        $entry->reverse();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('has already been reversed');

        $entry->fresh()->reverse();
    }

    public function test_a_ledger_entry_cannot_be_updated_or_deleted(): void
    {
        $entry = LedgerEntry::factory()->create();

        try {
            $entry->update(['description' => 'quietly rewritten']);
            $this->fail('updating a ledger entry should have thrown');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('append only', $e->getMessage());
        }

        try {
            $entry->delete();
            $this->fail('deleting a ledger entry should have thrown');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('cannot be deleted', $e->getMessage());
        }

        $this->assertSame(1, LedgerEntry::count());
    }

    public function test_the_ledger_has_no_updated_at_column_to_write_to(): void
    {
        $entry = LedgerEntry::factory()->create();

        $this->assertNull(LedgerEntry::UPDATED_AT);
        $this->assertNotNull($entry->created_at);
        $this->assertFalse(Schema::hasColumn('ledger_entries', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('ledger_entries', 'deleted_at'));
    }

    /* The trail outlives the document ---------------------------------------- */

    public function test_deleting_an_invoice_does_not_delete_its_ledger_entries(): void
    {
        $invoice = Invoice::factory()->create();
        $payment = Payment::factory()->create(['invoice_id' => $invoice->id]);
        LedgerEntry::factory()->forReference($invoice)->create(['description' => 'Payment on '.$invoice->number]);

        $invoiceId = $invoice->id;

        // Not a soft delete: the row is genuinely gone, and its lines and
        // payments go with it because those columns are real foreign keys.
        $invoice->forceDelete();

        $this->assertNull(Invoice::withTrashed()->find($invoiceId));
        $this->assertNull(Payment::withTrashed()->find($payment->id));

        $entries = LedgerEntry::query()->where('reference_type', 'invoice')->where('reference_id', $invoiceId)->get();

        $this->assertCount(1, $entries);
        $this->assertSame('invoice', $entries->first()->reference_type);
        $this->assertNull($entries->first()->reference);
    }

    /* Relationships ---------------------------------------------------------- */

    public function test_an_invoice_reaches_its_lines_payments_and_chain_detail(): void
    {
        $invoice = Invoice::factory()->create();
        InvoiceLine::factory()->count(3)->forInvoice($invoice)->create();

        $payment = Payment::factory()->crypto()->create(['invoice_id' => $invoice->id]);
        $chain = CryptoPayment::factory()->confirmed()->forPayment($payment)->create();

        $this->assertCount(3, $invoice->lines);
        $this->assertCount(1, $invoice->payments);
        $this->assertTrue($payment->chainDetail->is($chain));
        $this->assertTrue($chain->payment->is($payment));
        $this->assertTrue($invoice->lines->first()->invoice->is($invoice));
    }

    public function test_an_expense_belongs_to_a_company_and_to_the_invoice_it_was_rebilled_on(): void
    {
        $company = Company::factory()->create();
        $invoice = Invoice::factory()->create();

        $expense = Expense::factory()->rebilledOn($invoice)->create(['company_id' => $company->id]);

        $this->assertTrue($expense->company->is($company));
        $this->assertTrue($expense->rebilledOn->is($invoice));
        $this->assertTrue($expense->isRebilled());
        $this->assertCount(0, Expense::unbilled()->get());

        Expense::factory()->billable()->create();
        $this->assertCount(1, Expense::unbilled()->get());
    }

    /**
     * A card becomes an invoice line through Core's `links` table. Accounting
     * holds no foreign key into Project, which is what lets either module be
     * built, moved or dropped without touching the other.
     */
    public function test_an_invoice_and_a_line_can_be_linked_to_a_record_in_another_module(): void
    {
        $invoice = Invoice::factory()->create();
        $line = InvoiceLine::factory()->forInvoice($invoice)->create();
        $customer = Customer::factory()->create();

        $line->linkTo($customer, 'billed_for');

        $this->assertTrue($line->isLinkedTo($customer));
        $this->assertTrue($customer->linked('invoice_line')->contains->is($line));
        $this->assertSame('invoice_line', Link::first()->source_type);

        $invoice->linkTo($customer, 'billed_to');
        $this->assertTrue($invoice->isLinkedTo($customer));
    }

    public function test_every_accounting_model_used_polymorphically_has_a_short_alias(): void
    {
        $this->assertSame('invoice', MorphMap::aliasFor(Invoice::class));
        $this->assertSame('invoice_line', MorphMap::aliasFor(InvoiceLine::class));
        $this->assertSame('payment', MorphMap::aliasFor(Payment::class));
        $this->assertSame('expense', MorphMap::aliasFor(Expense::class));
        $this->assertSame('ledger_entry', MorphMap::aliasFor(LedgerEntry::class));
    }

    /* Crypto ------------------------------------------------------------------ */

    public function test_a_crypto_payment_is_final_only_once_it_is_deep_enough_and_has_not_failed(): void
    {
        $shallow = CryptoPayment::factory()->create(['confirmations' => CryptoPayment::FINAL_CONFIRMATIONS - 1]);
        $exact = CryptoPayment::factory()->create(['confirmations' => CryptoPayment::FINAL_CONFIRMATIONS, 'status' => 'confirmed']);
        $failed = CryptoPayment::factory()->failed()->create(['confirmations' => 5_000]);

        $this->assertFalse($shallow->isFinal());
        $this->assertSame(1, $shallow->confirmationsRemaining());

        $this->assertTrue($exact->isFinal());
        $this->assertSame(0, $exact->confirmationsRemaining());

        // Depth never rescues a failed transfer.
        $this->assertFalse($failed->isFinal());
        $this->assertTrue($failed->hasFailed());
    }

    public function test_the_explorer_link_follows_the_chain_and_not_the_token(): void
    {
        $tron = CryptoPayment::factory()->create(['tx_hash' => 'abc123', 'chain' => CryptoPayment::CHAIN_TRON]);
        $eth = CryptoPayment::factory()->ethereum()->create(['tx_hash' => '0xdef456']);
        $other = CryptoPayment::factory()->create(['tx_hash' => 'zzz', 'chain' => 'solana']);

        $this->assertSame('https://tronscan.org/#/transaction/abc123', $tron->explorerUrl());
        $this->assertSame('https://etherscan.io/tx/0xdef456', $eth->explorerUrl());
        $this->assertNull($other->explorerUrl());
    }

    public function test_the_on_chain_amount_is_kept_apart_from_what_the_payment_says_it_settled(): void
    {
        $payment = Payment::factory()->crypto()->create(['amount' => '2750.000000']);
        $chain = CryptoPayment::factory()->create([
            'payment_id' => $payment->id,
            'amount' => '2749.981200',
        ]);

        // A wallet rounding down by a few micro-units is normal. The delta is a
        // business decision, not something to hide by assuming the two match.
        $this->assertSame('-0.018800', $chain->deltaAgainstPayment());
    }

    /* Currencies and rates ---------------------------------------------------- */

    public function test_a_currency_is_keyed_on_its_code_rather_than_an_id(): void
    {
        $tether = Currency::factory()->tether()->create();

        $this->assertSame('USDT', $tether->getKey());
        $this->assertFalse($tether->incrementing);
        $this->assertSame('code', $tether->getKeyName());
        $this->assertTrue($tether->is_crypto);
        $this->assertSame(6, $tether->minor_unit);
        $this->assertTrue(Currency::query()->find('USDT')->isSupported());
    }

    public function test_an_exchange_rate_carries_no_timestamps_only_the_moment_it_was_fetched(): void
    {
        $rate = ExchangeRate::factory()->create();

        $this->assertFalse($rate->usesTimestamps());
        $this->assertFalse(Schema::hasColumn('exchange_rates', 'created_at'));
        $this->assertFalse(Schema::hasColumn('exchange_rates', 'updated_at'));
        $this->assertNotNull($rate->fetched_at);
    }

    public function test_a_rate_inverts_without_touching_a_float(): void
    {
        $rate = ExchangeRate::factory()->create(['rate' => '40.000000']);

        $this->assertSame('0.025000', $rate->inverted());
        $this->assertSame('USD/TRY', $rate->pair());
        $this->assertFalse($rate->isOfficial());
        $this->assertTrue(ExchangeRate::factory()->tcmbBuy()->create()->isOfficial());
    }

    /* The seeder --------------------------------------------------------------- */

    /**
     * The property the deploy script depends on.
     *
     * Running the seeder twice must leave the database exactly as the first run
     * did. Anything keyed on a surrogate id rather than a natural one shows up
     * here as a doubled table.
     */
    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(CoreDatabaseSeeder::class);

        $this->seed(AccountingDatabaseSeeder::class);
        $first = $this->accountingRowCounts();

        $this->seed(AccountingDatabaseSeeder::class);
        $second = $this->accountingRowCounts();

        $this->assertSame($first, $second, 'a second run of the seeder changed the row counts');

        // And it seeded something, so the assertion above is not comparing two
        // empty databases and calling it a pass.
        $this->assertSame(3, $first['currencies']);
        $this->assertSame(7, $first['invoices']);
        $this->assertGreaterThan(100, $first['exchange_rates']);
        $this->assertGreaterThan(0, $first['ledger_entries']);
    }

    public function test_the_seeded_book_reads_the_way_an_accountant_would_expect(): void
    {
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(AccountingDatabaseSeeder::class);

        // A lira invoice to the domestic Turkish buyer, with KDV on it.
        $lira = Invoice::query()->where('number', 'INV-0042')->first();
        $this->assertSame(Currencies::TRY, $lira->currency);
        $this->assertSame('54000.000000', $lira->subtotal);
        $this->assertSame('10800.000000', $lira->tax_amount);
        $this->assertSame('64800.000000', $lira->total);

        // A stablecoin invoice to the same buyer carries the lira equivalent at
        // the TCMB buying rate, its source and its date — because the buyer is
        // domestic and the invoice is not in lira.
        $tether = Invoice::query()->where('number', 'INV-0043')->first();
        $this->assertSame('2750.000000', $tether->total);
        $this->assertNotNull($tether->issue_rate_to_try);
        $this->assertSame('tcmb_evds', $tether->issue_rate_source);
        $this->assertNotNull($tether->try_equivalent);
        $this->assertStringContainsString('muhasebeci', $tether->rate_note);

        // The draft has no frozen rate at all, which is the whole difference.
        $draft = Invoice::query()->where('number', 'INV-0044')->first();
        $this->assertFalse($draft->isIssued());
        $this->assertNull($draft->reporting_rate);
        $this->assertNull($draft->due_on);

        $this->assertTrue(Invoice::query()->where('number', 'INV-0040')->first()->isOverdue());

        // The on-chain payment is real enough to be checked by a stranger.
        $chain = CryptoPayment::query()->first();
        $this->assertTrue($chain->isFinal());
        $this->assertStringStartsWith('https://tronscan.org/', $chain->explorerUrl());
    }

    /** @return array<string, int> */
    private function accountingRowCounts(): array
    {
        $counts = [];

        foreach (['currencies', 'exchange_rates', 'invoices', 'invoice_lines', 'payments', 'crypto_payments', 'ledger_entries', 'expenses'] as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }
}
