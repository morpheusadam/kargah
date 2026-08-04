<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
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

    /* The reporting currency ---------------------------------------------------- */

    /**
     * 🔴 The assertion that protects everything already in the book.
     *
     * `reporting_currency`, `reporting_rate` and `reporting_amount` are frozen
     * on an invoice at issue and are the record of what was believed then.
     * Changing the setting affects invoices issued *from now on* and nothing
     * else: no backfill, no recompute. So a book that has run for a while is a
     * mixed one — older invoices in dollars, newer in lira — and that is the
     * normal state rather than a defect to be tidied away.
     *
     * If this ever fails, some well-meaning change is rewriting history, and
     * every figure the owner has already filed moves with it.
     */
    public function test_an_invoice_issued_before_the_setting_changed_still_reports_in_the_currency_it_froze(): void
    {
        config(['accounting.reporting_currency' => Currencies::USD]);

        $old = $this->issuer()->issue($this->draft(['number' => 'INV-8001']));

        $this->assertSame(Currencies::USD, $old->reporting_currency);

        $frozenRate = (string) $old->reporting_rate;
        $frozenAmount = (string) $old->reporting_amount;

        // The owner switches to declaring in lira and raises the next invoice.
        config(['accounting.reporting_currency' => Currencies::TRY]);

        $new = $this->issuer()->issue($this->draft(['number' => 'INV-8002']));

        $this->assertSame(Currencies::TRY, $new->reporting_currency, 'The new invoice did not pick up the new setting.');

        $reread = Invoice::query()->find($old->id);

        $this->assertSame(
            Currencies::USD,
            $reread->reporting_currency,
            'An already-issued invoice was moved to the new reporting currency. Nothing may backfill history.',
        );
        $this->assertSame($frozenRate, (string) $reread->reporting_rate);
        $this->assertSame($frozenAmount, (string) $reread->reporting_amount);
    }

    /** Configuration decides it, not a default buried in a signature. */
    public function test_the_reporting_currency_comes_from_configuration(): void
    {
        config(['accounting.reporting_currency' => Currencies::USD]);

        $this->assertSame(
            Currencies::USD,
            $this->issuer()->issue($this->draft(['number' => 'INV-8003']))->reporting_currency,
        );

        config(['accounting.reporting_currency' => Currencies::TRY]);

        $this->assertSame(
            Currencies::TRY,
            $this->issuer()->issue($this->draft(['number' => 'INV-8004']))->reporting_currency,
        );
    }

    /** The owner is in Turkey and declares in lira, so that is what Kargah ships. */
    public function test_the_shipped_default_reporting_currency_is_lira(): void
    {
        $this->assertSame(Currencies::TRY, config('accounting.reporting_currency'));
        $this->assertSame(Currencies::TRY, InvoiceIssuer::reportingCurrency());
    }

    /**
     * A mistyped setting must not cost the owner an invoice.
     *
     * Refusing to issue because a config line says "TL" instead of "TRY" would
     * be a far worse failure than freezing the shipped default, so it falls
     * back rather than throwing.
     */
    public function test_a_setting_that_is_not_a_supported_currency_falls_back_rather_than_throwing(): void
    {
        config(['accounting.reporting_currency' => 'TL']);

        $this->assertSame(Currencies::TRY, InvoiceIssuer::reportingCurrency());
        $this->assertSame(
            Currencies::TRY,
            $this->issuer()->issue($this->draft(['number' => 'INV-8005']))->reporting_currency,
        );
    }

    /**
     * 🔴 The gap lira reporting opens, pinned so nobody discovers it in
     * production.
     *
     * `accounting:fetch-rates` only ever stores USD/TRY and USDT/USD, and
     * `rateFor()` inverts a stored pair but will not chain two of them — so
     * there is no USDT→TRY rate and a stablecoin invoice freezes null reporting
     * figures. What matters is that it is still *issued*: an invoice that
     * refused to exist because a rate was missing would be the serious
     * regression, and a null figure the page counts out loud is not.
     */
    public function test_a_tether_invoice_reporting_in_lira_is_issued_without_a_figure_rather_than_refused(): void
    {
        config(['accounting.reporting_currency' => Currencies::TRY]);

        $invoice = $this->issuer()->issue(
            $this->draft(['currency' => Currencies::USDT, 'number' => 'INV-8006']),
        );

        $this->assertNotNull($invoice->sent_at, 'A missing USDT/TRY rate stopped the invoice being issued.');
        $this->assertSame(Currencies::TRY, $invoice->reporting_currency);
        $this->assertNull($invoice->reporting_rate);
        $this->assertNull($invoice->reporting_amount);
    }

    /**
     * The two lira figures answer two different questions and stay two figures.
     *
     * With lira as the reporting currency a domestic Turkish invoice now
     * computes a lira amount twice — once for the owner's own books at the
     * market rate, once for the document at the TCMB *buying* rate Turkish tax
     * procedure names. They are not duplication: they come from different rates
     * and only one of them is legally pinned. Merging them would let a
     * Frankfurter mid-market rate onto a document where the law names TCMB's.
     */
    public function test_the_reporting_figure_and_the_legal_lira_figure_are_two_different_numbers(): void
    {
        config(['accounting.reporting_currency' => Currencies::TRY]);

        $invoice = $this->issuer()->issue(
            $this->draft(['company_id' => $this->turkish->id, 'number' => 'INV-8007']),
        );

        // The market rate, for the books.
        $this->assertSame('34.152700', (string) $invoice->reporting_rate);
        $this->assertSame('51229.050000', (string) $invoice->reporting_amount);

        // The TCMB buying rate, for the document. A different rate, from a
        // different source, recorded with its own date.
        $this->assertSame('34.081500', (string) $invoice->issue_rate_to_try);
        $this->assertSame('tcmb_evds', $invoice->issue_rate_source);
        $this->assertSame('51122.250000', (string) $invoice->try_equivalent);

        $this->assertNotSame(
            (string) $invoice->reporting_amount,
            (string) $invoice->try_equivalent,
            'The two lira figures collapsed into one, which loses the rate type the law pins.',
        );
    }

    /* The KDV export-of-services exemption ---------------------------------------- */

    /**
     * 🔴 Off unless somebody turned it on. Being abroad is not consent.
     *
     * The export-of-services zero rate needs four cumulative conditions to hold
     * and it is a judgement the freelancer makes per invoice. An invoice to a
     * foreign client with nothing set carries the KDV it was raised at, because
     * software inferring the exemption is software answering a tax question on
     * somebody else's liability.
     */
    public function test_an_invoice_to_a_foreign_client_still_carries_kdv_unless_the_exemption_is_applied(): void
    {
        $invoice = $this->issuer()->issue(
            $this->draft(['tax_percent' => '20', 'number' => 'INV-8010']),
        );

        $this->assertFalse($this->foreign->is_domestic);
        $this->assertNull($invoice->kdv_exemption_code, 'A zero-rating was inferred from the client being abroad.');
        $this->assertSame('20.000000', (string) $invoice->tax_percent);
        $this->assertSame('300.000000', (string) $invoice->tax_amount);
        $this->assertSame('1800.000000', (string) $invoice->total);
    }

    /** With the exemption applied, KDV is zero and every total agrees with it. */
    public function test_an_exempt_invoice_carries_no_kdv_and_its_totals_agree(): void
    {
        $draft = $this->draft(['tax_percent' => '20', 'number' => 'INV-8011']);

        // As the invoice builder writes it once a person has confirmed each
        // condition. `forceFill` because the column is not mass-assignable.
        $draft->forceFill(['kdv_exemption_code' => '302'])->save();

        $invoice = $this->issuer()->issue($draft);

        $this->assertSame('302', $invoice->kdv_exemption_code);
        $this->assertSame('1500.000000', (string) $invoice->subtotal);
        $this->assertSame('0.000000', (string) $invoice->tax_amount);
        $this->assertSame(
            '0.000000',
            (string) $invoice->tax_percent,
            'A zero-rated invoice kept a 20% rate that the document flatly contradicts.',
        );
        $this->assertSame('1500.000000', (string) $invoice->total);
    }

    /** The artefact a tax office reads has to say which exemption, and its code. */
    public function test_the_document_states_the_exemption_and_its_code(): void
    {
        $draft = $this->draft(['tax_percent' => '20', 'number' => 'INV-8012']);
        $draft->forceFill(['kdv_exemption_code' => '302'])->save();

        $invoice = $this->issuer()->issue($draft);

        $html = view('accounting::documents.invoice', app(InvoiceDocument::class)->data($invoice->fresh()))->render();

        $this->assertStringContainsString('302', $html);
        $this->assertStringContainsString(
            (string) config('accounting.tax.kdv_exemptions.302.label'),
            $html,
            'The document names a code with no reason beside it.',
        );
    }

    /**
     * The control is not reachable where the exemption plainly cannot apply.
     *
     * Condition two is that the client's residence or business centre is
     * abroad. For a domestic Turkish buyer it fails outright, and for an
     * invoice billed to a person rather than a company there is no evidence
     * either way — so in both cases the question is not asked at all. A
     * disabled control is an invitation to look for the way round it.
     */
    public function test_the_exemption_is_not_offered_where_it_plainly_cannot_apply(): void
    {
        $domestic = Livewire::test('accounting::invoice-edit', [
            'invoice' => (string) $this->draft(['company_id' => $this->turkish->id, 'number' => 'INV-8020'])->id,
        ]);

        $this->assertSame([], $domestic->viewData('exemptions'));
        $domestic->assertDontSee('Apply the zero rate');

        // Billed to the person, not a company. `draft()` falls back to the
        // foreign company when the key is null, so this clears it afterwards.
        $orphan = $this->draft(['number' => 'INV-8021']);
        $orphan->forceFill(['company_id' => null])->save();

        $noCompany = Livewire::test('accounting::invoice-edit', ['invoice' => (string) $orphan->id]);

        $this->assertSame([], $noCompany->viewData('exemptions'));
    }

    /**
     * 🔴 Applying it is something a person did, one condition at a time.
     *
     * Three of the four confirmed is not confirmation, and the invoice keeps
     * its KDV until every one has been ticked and the exemption applied.
     */
    public function test_applying_the_exemption_needs_every_condition_confirmed(): void
    {
        $invoice = $this->draft(['tax_percent' => '20', 'number' => 'INV-8022']);

        $page = Livewire::test('accounting::invoice-edit', ['invoice' => (string) $invoice->id]);

        $this->assertNotSame([], $page->viewData('exemptions'), 'A foreign buyer was not offered the question at all.');
        $this->assertNull($page->viewData('appliedExemption'), 'The exemption did not start off.');

        $page->set('kdvConfirmed.302.0', true)
            ->set('kdvConfirmed.302.1', true)
            ->set('kdvConfirmed.302.2', true)
            ->call('applyExemption', '302');

        $this->assertNull($page->viewData('appliedExemption'), 'Three of four conditions was enough to zero-rate it.');

        $page->set('kdvConfirmed.302.3', true)->call('applyExemption', '302');

        $this->assertSame('302', $page->viewData('appliedExemption'));

        $page->call('save');

        $saved = $invoice->fresh();

        $this->assertSame('302', $saved->kdv_exemption_code);
        $this->assertSame('0.000000', (string) $saved->tax_percent);
        $this->assertSame('0.000000', (string) $saved->tax_amount);
        $this->assertSame('1500.000000', (string) $saved->total);
    }

    /**
     * The checkboxes are a user interface; the server is the authority.
     *
     * Livewire state arrives from the browser, so a payload that simply asserts
     * the code without any of the confirmations behind it writes nothing.
     */
    public function test_an_exemption_code_with_no_confirmations_behind_it_is_not_written(): void
    {
        $invoice = $this->draft(['tax_percent' => '20', 'number' => 'INV-8023']);

        Livewire::test('accounting::invoice-edit', ['invoice' => (string) $invoice->id])
            ->set('kdvExemptionCode', '302')
            ->call('save');

        $saved = $invoice->fresh();

        $this->assertNull($saved->kdv_exemption_code, 'An unconfirmed exemption reached the column.');
        $this->assertSame('300.000000', (string) $saved->tax_amount, 'KDV was dropped without an exemption behind it.');
    }

    /** Withdrawing a confirmation withdraws the exemption, rather than leaving a stale one applied. */
    public function test_unticking_a_condition_withdraws_the_exemption(): void
    {
        $invoice = $this->draft(['tax_percent' => '20', 'number' => 'INV-8024']);

        $page = Livewire::test('accounting::invoice-edit', ['invoice' => (string) $invoice->id])
            ->set('kdvConfirmed.302.0', true)
            ->set('kdvConfirmed.302.1', true)
            ->set('kdvConfirmed.302.2', true)
            ->set('kdvConfirmed.302.3', true)
            ->call('applyExemption', '302');

        $this->assertSame('302', $page->viewData('appliedExemption'));

        $page->set('kdvConfirmed.302.2', false)->call('save');

        $this->assertNull($page->viewData('appliedExemption'));
        $this->assertNull($invoice->fresh()->kdv_exemption_code);
    }

    /** An ordinary invoice says nothing about exemptions, because none applies. */
    public function test_the_document_of_an_ordinary_invoice_claims_no_exemption(): void
    {
        $invoice = $this->issuer()->issue($this->draft(['tax_percent' => '20', 'number' => 'INV-8013']));

        $html = view('accounting::documents.invoice', app(InvoiceDocument::class)->data($invoice->fresh()))->render();

        $this->assertStringNotContainsString('KDV exemption', $html);

        // 🔴 The sentence, not the bare digits.
        //
        // This assertion used to read `assertStringNotContainsString('302',
        // $html)` and was **flaky**: measured on 4 August 2026 it failed once in
        // six consecutive runs, because the customer's address comes from faker
        // and any postcode, apartment number or tax number containing `302`
        // failed it. The run that caught it printed
        // `South Judgeshire, AZ 30241-1232`.
        //
        // A test that passes five times in six is worse than no test — it
        // trains the next person to re-run instead of to look. `302` is three
        // digits and this document is full of numbers the test does not
        // control; the exemption is the only thing that ever prints the
        // *phrase*, so the phrase is what can be asserted on.
        $this->assertStringNotContainsString('exemption code 302', $html);
        $this->assertStringNotContainsString('Zero-rated', $html);
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
