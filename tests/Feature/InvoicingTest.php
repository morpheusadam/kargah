<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\InvoiceLine;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Services\ExchangeRates;
use Modules\Accounting\Services\InvoiceDocument;
use Modules\Accounting\Services\InvoiceIssuer;
use Modules\Accounting\Services\PaymentRecorder;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Services\CardService;
use Modules\Project\Support\Position;
use Tests\TestCase;

/**
 * The acceptance criteria for the Accounting phase, as tests.
 *
 * This is the part where being wrong costs the owner real money, so each
 * criterion from 05-build-order.md gets a test that fails loudly rather than a
 * page that looks plausible.
 */
class InvoicingTest extends TestCase
{
    use RefreshDatabase;

    private Company $foreign;

    private Company $turkish;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $this->foreign = Company::factory()->create(['name' => 'Northwind Ltd', 'country' => 'GB', 'is_domestic' => false]);
        $this->turkish = Company::factory()->create(['name' => 'Harbour & Finch', 'country' => 'TR', 'is_domestic' => true]);
        $this->customer = Customer::factory()->for($this->foreign)->create(['name' => 'Sam Okafor']);

        $rates = app(ExchangeRates::class);

        // The market on the day these invoices are issued.
        $rates->record(Currencies::USD, Currencies::TRY, '34.152700', 'frankfurter', '2026-07-01');
        $rates->record(Currencies::USD, Currencies::TRY, '34.081500', 'tcmb_evds', '2026-07-01', ExchangeRates::TCMB_BUY);
        $rates->record(Currencies::USD, Currencies::TRY, '34.204000', 'tcmb_evds', '2026-07-01', ExchangeRates::TCMB_SELL);
        $rates->record(Currencies::USDT, Currencies::USD, '0.999200', 'coingecko', '2026-07-01');
    }

    private function draft(array $attributes = [], array $lines = []): Invoice
    {
        $invoice = Invoice::query()->create([
            'number' => $attributes['number'] ?? 'INV-'.fake()->unique()->numberBetween(1000, 9999),
            'company_id' => $attributes['company_id'] ?? $this->foreign->id,
            'customer_id' => $this->customer->id,
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

        return $invoice->refresh();
    }

    private function issuer(): InvoiceIssuer
    {
        return app(InvoiceIssuer::class);
    }

    /* The headline criterion ------------------------------------------------- */

    /**
     * "An invoice issued at one rate still shows that rate after the market
     * moves — asserted by a test that changes the rate and re-reads the
     * invoice."
     */
    public function test_an_issued_invoice_keeps_its_rate_after_the_market_moves(): void
    {
        $invoice = $this->issuer()->issue($this->draft(), Currencies::TRY);

        $frozenRate = (string) $invoice->reporting_rate;
        $frozenAmount = (string) $invoice->reporting_amount;

        $this->assertSame('34.152700', $frozenRate);
        $this->assertSame('51229.050000', $frozenAmount);

        // The lira falls out of bed the following week.
        app(ExchangeRates::class)->record(Currencies::USD, Currencies::TRY, '41.900000', 'frankfurter', '2026-07-08');

        $reread = Invoice::query()->find($invoice->id);

        $this->assertSame($frozenRate, (string) $reread->reporting_rate, 'The invoice re-derived its rate from the market.');
        $this->assertSame($frozenAmount, (string) $reread->reporting_amount, 'The invoice restated itself after the market moved.');
    }

    public function test_an_issued_invoice_refuses_to_be_recalculated(): void
    {
        $invoice = $this->issuer()->issue($this->draft());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('never changes its numbers');

        $this->issuer()->recalculate($invoice);
    }

    public function test_a_draft_recalculates_freely(): void
    {
        $invoice = $this->draft([], [
            ['Discovery workshop', '2', '750.00'],
            ['Implementation', '8.5', '120.00'],
        ]);

        $this->issuer()->recalculate($invoice);

        // 1500 + 1020
        $this->assertSame('2520.000000', (string) $invoice->fresh()->subtotal);
        $this->assertSame('2520.000000', (string) $invoice->fresh()->total);
    }

    public function test_tax_is_applied_without_a_float(): void
    {
        $invoice = $this->draft(['tax_percent' => '20']);

        $this->issuer()->recalculate($invoice);

        $this->assertSame('1500.000000', (string) $invoice->fresh()->subtotal);
        $this->assertSame('300.000000', (string) $invoice->fresh()->tax_amount);
        $this->assertSame('1800.000000', (string) $invoice->fresh()->total);
        $this->assertIsString($invoice->fresh()->total);
    }

    /* The Turkish criterion ---------------------------------------------------- */

    /**
     * "A domestic Turkish invoice shows the TCMB buying rate, its date and the
     * lira equivalent."
     */
    public function test_a_domestic_turkish_invoice_carries_the_tcmb_buying_rate_and_the_lira_figure(): void
    {
        $invoice = $this->issuer()->issue($this->draft(['company_id' => $this->turkish->id]));

        // The buying rate, not the selling rate — which one applies is a legal
        // question, and picking the wrong one is the issuer's liability.
        $this->assertSame('34.081500', (string) $invoice->issue_rate_to_try);
        $this->assertSame('tcmb_evds', $invoice->issue_rate_source);
        $this->assertSame('2026-07-01', $invoice->issue_rate_date->toDateString());
        $this->assertSame('51122.250000', (string) $invoice->try_equivalent);
    }

    public function test_a_foreign_invoice_carries_no_lira_figure_at_all(): void
    {
        // If the buyer is foreign, none of the Turkish rules apply. Filling
        // these in anyway would put a number on the document that no rule asked
        // for and nobody can explain.
        $invoice = $this->issuer()->issue($this->draft());

        $this->assertNull($invoice->issue_rate_to_try);
        $this->assertNull($invoice->try_equivalent);
        $this->assertNull($invoice->issue_rate_source);
    }

    public function test_a_tether_invoice_to_a_turkish_buyer_says_how_it_reached_the_lira_figure(): void
    {
        // No authoritative Turkish ruling covers a stablecoin invoice. USD is
        // used as the intermediate and the row says so, because the owner has
        // to be able to hand this to an accountant and be overruled.
        $invoice = $this->issuer()->issue(
            $this->draft(['company_id' => $this->turkish->id, 'currency' => Currencies::USDT]),
        );

        $this->assertNotNull($invoice->try_equivalent);
        $this->assertStringContainsString('through USD', $invoice->rate_note);
        $this->assertStringContainsString('muhasebeci', $invoice->rate_note);
    }

    public function test_a_missing_rate_does_not_block_issuing(): void
    {
        // "Kargah must never block on a tax rule." An invoice with no rate
        // available is issued with the figure left null, not refused.
        $invoice = $this->issuer()->issue(
            $this->draft(['issued_on' => '2020-01-01']),
            Currencies::TRY,
        );

        $this->assertNotNull($invoice->issued_on);
        $this->assertNull($invoice->reporting_rate);
        $this->assertNull($invoice->reporting_amount);
    }

    /* Payments and realised FX -------------------------------------------------- */

    /**
     * "A USD invoice settled in USDT records the chain, the hash and the gain
     * or loss."
     */
    public function test_a_usd_invoice_settled_in_usdt_records_the_chain_the_hash_and_the_difference(): void
    {
        $invoice = $this->issuer()->issue($this->draft());

        // Tether has drifted by the time the client pays.
        app(ExchangeRates::class)->record(Currencies::USDT, Currencies::USD, '1.001500', 'coingecko', '2026-07-20');

        $payment = app(PaymentRecorder::class)->record(
            invoice: $invoice,
            amount: '1500.000000',
            currency: Currencies::USDT,
            paidAt: '2026-07-20',
            method: 'crypto',
        );

        app(PaymentRecorder::class)->attachChainDetail($payment, [
            'chain' => 'tron',
            'tx_hash' => 'b4f1c0d2e39a7c5188f0aa2c4d6e8b0a1f2c3d4e5f60718293a4b5c6d7e8f900',
            'from_address' => 'TJmVQ1x9k8Yq2ZrN6pW4sD3fH5gL7cB0aE',
            'to_address' => 'TWd4kXn2Vb8cL1qP6sR9tY3uH5jK7mN0aZ',
            'amount' => '1500.000000',
            'confirmations' => 24,
            'status' => 'confirmed',
        ]);

        $payment->refresh();
        $chain = $payment->chainDetail;

        $this->assertSame('tron', $chain->chain);
        $this->assertSame('TRC-20', $chain->token_standard);
        $this->assertStringContainsString('tronscan.org', $chain->explorerUrl());
        $this->assertTrue($chain->isFinal());

        // Issued when USDT was worth 0.9992; settled at 1.0015. The client's
        // 1500 USDT is worth more dollars than it was, and the row says so.
        $this->assertSame('1.001500', (string) $payment->settlement_rate);
        $this->assertSame('1502.250000', (string) $payment->applied_amount);
        $this->assertSame('3.450000', (string) $payment->fx_gain_loss);
    }

    public function test_a_payment_in_the_invoices_own_currency_realises_nothing(): void
    {
        $invoice = $this->issuer()->issue($this->draft());

        $payment = app(PaymentRecorder::class)->record(
            invoice: $invoice,
            amount: '1500.00',
            currency: Currencies::USD,
            paidAt: '2026-07-20',
        );

        $this->assertSame('0.000000', (string) $payment->fx_gain_loss);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_a_part_payment_leaves_the_invoice_part_paid(): void
    {
        $invoice = $this->issuer()->issue($this->draft());

        app(PaymentRecorder::class)->record($invoice, '500.00', Currencies::USD, '2026-07-20');

        $this->assertSame('part_paid', $invoice->fresh()->status);
        $this->assertSame('1000.000000', app(PaymentRecorder::class)->outstanding($invoice->fresh()));
    }

    /**
     * Unrealised revaluation is a report, not a row. Nothing has happened yet.
     */
    public function test_revaluing_an_open_invoice_writes_nothing(): void
    {
        $invoice = $this->issuer()->issue($this->draft(), Currencies::TRY);

        app(ExchangeRates::class)->record(Currencies::USD, Currencies::TRY, '41.900000', 'frankfurter', '2026-08-01');

        $before = LedgerEntry::query()->count();

        $revaluation = app(PaymentRecorder::class)->unrealised($invoice, '2026-08-01');

        $this->assertSame($before, LedgerEntry::query()->count(), 'An unrealised revaluation wrote to the ledger.');
        // 1500 USD outstanding: 1500 × 41.9 = 62,850 against 1500 × 34.1527 =
        // 51,229.05 frozen at issue.
        $this->assertSame('41.900000', $revaluation['rate']);
        $this->assertSame('62850.000000', $revaluation['at_today']);
        $this->assertSame('11620.950000', $revaluation['difference']);
    }

    /* The ledger ------------------------------------------------------------------ */

    /** "Deleting an invoice does not delete its ledger entries." */
    public function test_deleting_an_invoice_leaves_its_ledger_entries_alone(): void
    {
        $invoice = $this->issuer()->issue($this->draft());
        app(PaymentRecorder::class)->record($invoice, '1500.00', Currencies::USD, '2026-07-20');

        $entries = LedgerEntry::query()->count();
        $this->assertGreaterThan(0, $entries);

        $invoice->delete();

        $this->assertSame($entries, LedgerEntry::query()->count(), 'Deleting an invoice took the audit trail with it.');
    }

    public function test_a_mistake_is_corrected_by_a_reversing_entry_not_an_edit(): void
    {
        $invoice = $this->issuer()->issue($this->draft());
        app(PaymentRecorder::class)->record($invoice, '1500.00', Currencies::USD, '2026-07-20');

        $original = LedgerEntry::query()->latest('id')->first();
        $reversal = $original->reverse('Payment was applied to the wrong invoice');

        $this->assertSame('1500.000000', (string) $original->fresh()->amount, 'The original entry was edited.');
        $this->assertSame('-1500.000000', (string) $reversal->amount);
        $this->assertSame($original->id, $reversal->reverses_id);
    }

    /* The document ------------------------------------------------------------------ */

    public function test_the_pdf_states_the_rate_that_produced_every_converted_figure(): void
    {
        $invoice = $this->issuer()->issue($this->draft(['company_id' => $this->turkish->id]), Currencies::TRY);

        $data = app(InvoiceDocument::class)->data($invoice->fresh());

        $this->assertNotNull($data['lira']);
        $this->assertSame('34.081500', $data['lira']['rate']);
        $this->assertSame('1 July 2026', $data['lira']['on']);
        $this->assertSame('tcmb_evds', $data['lira']['source']);

        // Never only the converted number.
        $this->assertNotNull($data['reporting']['rate']);
    }

    public function test_the_pdf_renders(): void
    {
        $invoice = $this->issuer()->issue($this->draft());

        $response = $this->get('/accounting/invoices/'.$invoice->id.'/pdf');

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /* The card that became a line ------------------------------------------------------ */

    public function test_a_card_becomes_an_invoice_line_through_links_not_a_foreign_key(): void
    {
        $board = Board::factory()->create();
        $list = BoardList::factory()->for($board)->create([
            'position' => Position::format('1024'),
        ]);
        $card = app(CardService::class)->append($list, 'Build the Acme Studio mail module');

        $invoice = $this->draft();
        $line = $invoice->lines()->first();

        $card->linkTo($line, 'billed_as');

        $this->assertTrue($line->isLinkedTo($card));
        $this->assertSame($card->id, $line->linked('card')->first()->id);
        $this->assertSame($line->id, $card->linked('invoice_line')->first()->id);

        // And there is no column doing this job.
        $this->assertFalse(Schema::hasColumn('invoice_lines', 'card_id'));
    }
}
