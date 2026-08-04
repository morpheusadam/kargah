<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Services\PaymentRecorder;
use Modules\Accounting\Support\Currencies;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;
use Tests\TestCase;

/**
 * The three reports a freelancer actually opens: aged receivables, profit and
 * loss, and the tax summary.
 *
 * `AccountingPagesTest` already proves the page renders and that its USD
 * headline figures come out of the database. This suite covers the parts where
 * a wrong number has consequences outside the repository:
 *
 *  - **the ageing boundaries**, because off by one on a date bucket is the
 *    classic defect here and it silently moves an invoice out of the band that
 *    would have made somebody pick up the phone;
 *  - **that two currencies are never added together**, because a mixed total
 *    looks exactly like a correct one;
 *  - **that a row with no frozen lira rate is counted rather than converted**,
 *    because inventing a rate for a date that has passed is indefensible;
 *  - **that the geçici vergi rate is configuration**, because Turkish brackets
 *    are revalued annually and a hardcoded one goes wrong every January without
 *    anybody noticing;
 *  - **that the profit and loss is on the cash basis it claims to be on**,
 *    against a fixture computed by hand.
 *
 * Every expected figure below is a decimal string worked out on paper. A total
 * that is one kuruş out looks exactly like a total that is not.
 */
class AccountingReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /* Aged receivables ---------------------------------------------------------- */

    /**
     * 🔴 The boundaries, one invoice per edge.
     *
     * Days are counted midnight to midnight, so an invoice due today is not
     * late at all and one due yesterday is one day late. The bands are
     * inclusive on both ends. 45 days is the case the brief names, and it is
     * surrounded here by 30/31 and 60/61 and 90/91 — the three places an
     * off-by-one actually shows up.
     */
    public function test_each_ageing_boundary_puts_an_invoice_in_the_band_it_belongs_to(): void
    {
        $customer = $this->foreignCustomer();

        $expected = [
            1 => '1 to 30 days',
            30 => '1 to 30 days',
            31 => '31 to 60 days',
            45 => '31 to 60 days',
            60 => '31 to 60 days',
            61 => '61 to 90 days',
            90 => '61 to 90 days',
            91 => 'Over 90 days',
        ];

        foreach (array_merge([0], array_keys($expected)) as $late) {
            $this->liraInvoice($customer, '1000.00', dueDaysAgo: $late, number: 'AGE-'.$late);
        }

        $chase = collect(Livewire::test('accounting::reports')->viewData('chase'))->keyBy('number');

        foreach ($expected as $late => $band) {
            $this->assertTrue($chase->has('AGE-'.$late), 'An invoice '.$late.' days overdue is missing from the list.');

            $this->assertSame(
                $late,
                $chase['AGE-'.$late]['late'],
                'An invoice due '.$late.' days ago was counted as '.$chase['AGE-'.$late]['late'].' days late.',
            );

            $this->assertSame(
                $band,
                $chase['AGE-'.$late]['bucket'],
                $late.' days overdue landed in "'.$chase['AGE-'.$late]['bucket'].'" instead of "'.$band.'".',
            );
        }

        $this->assertFalse(
            $chase->has('AGE-0'),
            'An invoice due today was called overdue. Money is not late until the day after it was promised.',
        );
    }

    /** The bucket totals, not just the labels: 45 days must add into 31 to 60 and nowhere else. */
    public function test_a_forty_five_day_old_invoice_totals_into_the_thirty_one_to_sixty_column(): void
    {
        $customer = $this->foreignCustomer();

        $this->liraInvoice($customer, '4500.00', dueDaysAgo: 45);
        $this->liraInvoice($customer, '3000.00', dueDaysAgo: 30);

        $aged = Livewire::test('accounting::reports')->viewData('aged');

        // Columns are current, 1-30, 31-60, 61-90, over 90.
        $lira = array_column($aged['lira'], 'total');

        $this->assertSame('₺0.00', $lira[0]);
        $this->assertSame('₺3,000.00', $lira[1]);
        $this->assertSame('₺4,500.00', $lira[2]);
        $this->assertSame('₺0.00', $lira[3]);
        $this->assertSame('₺0.00', $lira[4]);
        $this->assertSame('₺7,500.00', $aged['liraTotal']);
    }

    /**
     * 🔴 Two currencies are two figures. Never one.
     *
     * Adding them needs a rate, and a rate needs a date and a source before
     * anybody can argue with the result — so a dollar invoice and a lira
     * invoice produce two rows, and no cell anywhere holds their sum.
     */
    public function test_two_currencies_are_never_added_into_one_figure(): void
    {
        $customer = $this->foreignCustomer();

        $this->liraInvoice($customer, '40000.00', dueDaysAgo: 10);
        $this->dollarInvoice($customer, '1000.00', dueDaysAgo: 10);

        $aged = Livewire::test('accounting::reports')->viewData('aged');

        $totals = collect($aged['currencies'])->pluck('total', 'currency');

        $this->assertCount(2, $totals, 'The currencies collapsed into '.$totals->count().' row(s).');
        $this->assertSame('₺40,000.00', $totals[Currencies::TRY]);
        $this->assertSame('$1,000.00', $totals[Currencies::USD]);

        // 40,000 + 1,000 = 41,000 is the figure a mixed total would produce.
        $this->assertNotContains('₺41,000.00', $totals->all());
        $this->assertNotContains('$41,000.00', $totals->all());
    }

    /**
     * A row that never got a rate is counted out loud, not converted.
     *
     * The dollar invoice below froze no lira figure — which is the ordinary
     * case for a foreign client — so it stays out of the lira column entirely
     * and the page carries the count instead.
     */
    public function test_an_invoice_with_no_frozen_lira_rate_is_counted_and_left_out_of_the_lira_column(): void
    {
        $customer = $this->foreignCustomer();

        $this->liraInvoice($customer, '40000.00', dueDaysAgo: 10);
        $this->dollarInvoice($customer, '1000.00', dueDaysAgo: 10);

        $aged = Livewire::test('accounting::reports')->viewData('aged');

        $this->assertSame(1, $aged['unconverted'], 'The invoice with no frozen lira rate was not counted.');
        $this->assertSame('₺40,000.00', $aged['liraTotal'], 'An invoice with no lira rate got into the lira total.');

        $chase = collect(Livewire::test('accounting::reports')->viewData('chase'))->keyBy('currency');

        $this->assertNull($chase[Currencies::USD]['lira'], 'A lira figure was invented for a dollar invoice.');
        $this->assertNull($chase[Currencies::USD]['rate']);
        $this->assertNotNull($chase[Currencies::TRY]['lira']);
    }

    /** The report has to name who to chase, or it is a number nobody can act on. */
    public function test_the_overdue_list_names_the_client_and_the_invoice(): void
    {
        $customer = $this->foreignCustomer();
        $invoice = $this->liraInvoice($customer, '2400.00', dueDaysAgo: 45);

        $this->get('/accounting/reports?period=all')
            ->assertOk()
            ->assertSee('Aged receivables')
            ->assertSee('Overdue, worst first')
            ->assertSee($invoice->number)
            ->assertSee('Sam Okafor')
            ->assertSee('₺2,400.00');
    }

    /* Profit and loss ------------------------------------------------------------ */

    /**
     * The cash basis, against a fixture worked out on paper.
     *
     * Invoiced ₺30,000, collected ₺12,000, spent ₺2,500. On the cash basis the
     * income is the ₺12,000 that landed and the net is ₺9,500. The ₺30,000 is
     * the accrual answer and is carried separately — if the two ever swap, this
     * test is the thing that notices.
     */
    public function test_the_profit_and_loss_is_on_the_cash_basis_it_says_it_is(): void
    {
        $customer = $this->foreignCustomer();

        $invoice = $this->liraInvoice($customer, '30000.00', dueDaysAgo: -20);

        app(PaymentRecorder::class)->record(
            $invoice, '12000.00', Currencies::TRY, now(), settlementRate: '1.000000',
        );

        $this->expense('Harbour ofis kirası', 'Other', '2500.00', Currencies::TRY);

        $pnl = Livewire::test('accounting::reports')->set('period', 'all')->viewData('pnl');

        $this->assertSame('₺12,000.00', $pnl['income'], 'Income is not what was collected.');
        $this->assertSame('₺2,500.00', $pnl['expenses']);
        $this->assertSame('₺9,500.00', $pnl['net']);
        $this->assertFalse($pnl['isLoss']);

        // The accrual figure, kept separate so nobody reads one as the other.
        $this->assertSame('₺30,000.00', $pnl['invoiced']);
        $this->assertSame(1, $pnl['collections']);
        $this->assertSame(0, $pnl['incomeUnpriced']);
        $this->assertSame(0, $pnl['expensesUnpriced']);
    }

    /** A cost with no lira figure is left out of the total and counted, never guessed at. */
    public function test_a_cost_with_no_lira_figure_is_counted_rather_than_converted(): void
    {
        $this->expense('Harbour ofis kirası', 'Other', '2500.00', Currencies::TRY);
        $this->expense('DigitalOcean', 'Hosting', '120.00', Currencies::USD, reportingCurrency: null);

        $pnl = Livewire::test('accounting::reports')->set('period', 'all')->viewData('pnl');

        $this->assertSame('₺2,500.00', $pnl['expenses'], 'A cost with no lira rate got into the lira total.');
        $this->assertSame(1, $pnl['expensesUnpriced'], 'The cost with no lira rate was not counted.');
    }

    /** The comparison is against the period before, of the same shape. */
    public function test_the_profit_and_loss_compares_against_the_previous_period(): void
    {
        $this->expense('Namecheap', 'Domains', '1000.00', Currencies::TRY, on: now()->startOfMonth());
        $this->expense('Namecheap', 'Domains', '400.00', Currencies::TRY, on: now()->startOfMonth()->subDay());

        $pnl = Livewire::test('accounting::reports')->set('period', 'month')->viewData('pnl');

        $this->assertSame('₺1,000.00', $pnl['expenses'], 'Last month leaked into this month.');
        $this->assertSame('₺-1,000.00', $pnl['net']);
        $this->assertSame('₺-400.00', $pnl['previousNet']);
        $this->assertSame('₺-600.00', $pnl['change']);
        $this->assertFalse($pnl['improved']);
    }

    /* Tax ------------------------------------------------------------------------- */

    /**
     * 🔴 The assertion that proves the rate is not hardcoded.
     *
     * Turkish brackets are revalued every year. If this figure does not move
     * when the config does, a literal has crept back into the page and it will
     * be quietly wrong from the next revaluation onwards.
     */
    public function test_the_gecici_vergi_estimate_follows_the_configured_rate(): void
    {
        $customer = $this->foreignCustomer();
        $invoice = $this->liraInvoice($customer, '10000.00', dueDaysAgo: -20);

        app(PaymentRecorder::class)->record(
            $invoice, '10000.00', Currencies::TRY, now(), settlementRate: '1.000000',
        );

        config(['accounting.tax.gecici_vergi_percent' => '15', 'accounting.tax.year' => '2026']);

        $tax = Livewire::test('accounting::reports')->viewData('tax');

        $this->assertSame('₺10,000.00', $tax['quarterNet']);
        $this->assertSame('₺1,500.00', $tax['gecici']);
        $this->assertSame('15', $tax['geciciPercent']);
        $this->assertSame('2026', $tax['year']);

        config(['accounting.tax.gecici_vergi_percent' => '27.5', 'accounting.tax.year' => '2031']);

        $tax = Livewire::test('accounting::reports')->viewData('tax');

        $this->assertSame(
            '₺2,750.00',
            $tax['gecici'],
            'The provisional tax figure did not move with the configured rate, so it is hardcoded somewhere.',
        );
        $this->assertSame('2031', $tax['year'], 'The page is not showing the year its rate belongs to.');
    }

    /** The threshold is configuration too, and crossing it is said out loud. */
    public function test_the_first_bracket_threshold_comes_from_configuration(): void
    {
        $customer = $this->foreignCustomer();
        $invoice = $this->liraInvoice($customer, '200000.00', dueDaysAgo: -20);

        app(PaymentRecorder::class)->record(
            $invoice, '200000.00', Currencies::TRY, now(), settlementRate: '1.000000',
        );

        config(['accounting.tax.gecici_vergi_threshold' => '190000']);

        $tax = Livewire::test('accounting::reports')->viewData('tax');

        $this->assertSame('₺190,000.00', $tax['threshold']);
        $this->assertTrue($tax['overThreshold'], 'Earnings above the first bracket were not flagged.');

        config(['accounting.tax.gecici_vergi_threshold' => '500000']);

        $this->assertFalse(
            Livewire::test('accounting::reports')->viewData('tax')['overThreshold'],
            'The threshold did not move with the configured value.',
        );
    }

    /**
     * KDV is totalled from what the invoices froze, never by applying a rate again.
     *
     * One invoice at 20% and one zero-rated. Re-applying the standard rate to
     * the second would invent a liability that does not exist — the export of
     * services exemption is a judgement per invoice and software must not make
     * it.
     */
    public function test_kdv_is_added_from_what_each_invoice_froze(): void
    {
        $customer = $this->foreignCustomer();

        $this->liraInvoice($customer, '12000.00', dueDaysAgo: 5, attributes: [
            'subtotal' => '10000.00',
            'tax_percent' => '20',
            'tax_amount' => '2000.00',
            'total' => '12000.00',
        ]);

        $this->liraInvoice($customer, '5000.00', dueDaysAgo: 5, attributes: [
            'subtotal' => '5000.00',
            'tax_percent' => '0',
            'tax_amount' => '0',
            'total' => '5000.00',
        ]);

        $tax = Livewire::test('accounting::reports')->set('period', 'all')->viewData('tax');

        $lira = collect($tax['kdv'])->firstWhere('currency', Currencies::TRY);

        $this->assertSame('₺2,000.00', $lira['charged'], 'KDV was re-derived rather than read off the invoices.');
        $this->assertSame('₺15,000.00', $lira['net']);
        $this->assertSame(1, $lira['zeroRated'], 'The zero-rated invoice was not counted as one.');
        $this->assertSame('₺2,000.00', $tax['kdvLira']);
    }

    /**
     * 🔴 Stopaj is not computed for a foreign payer, because nobody could
     * establish that it applies to one.
     *
     * The sources describe the Article 94 obligation only for Turkish
     * tax-liable payers. Kargah's usual client is abroad, so the page counts
     * those invoices and prints the open question rather than a number.
     */
    public function test_no_withholding_is_computed_for_a_foreign_client(): void
    {
        $customer = $this->foreignCustomer();
        $this->liraInvoice($customer, '10000.00', dueDaysAgo: 5);

        $tax = Livewire::test('accounting::reports')->set('period', 'all')->viewData('tax');

        $this->assertSame([], $tax['stopajRows'], 'A withholding figure was computed for a foreign payer.');
        $this->assertSame(1, $tax['stopajForeign']);
    }

    public function test_withholding_is_shown_for_a_domestic_turkish_payer(): void
    {
        $customer = $this->domesticCustomer();

        $this->liraInvoice($customer, '12000.00', dueDaysAgo: 5, attributes: [
            'subtotal' => '10000.00',
            'tax_percent' => '20',
            'tax_amount' => '2000.00',
            'total' => '12000.00',
        ]);

        $tax = Livewire::test('accounting::reports')->set('period', 'all')->viewData('tax');

        // 20% of the fee before KDV: 10,000 not 12,000.
        $this->assertSame('₺2,000.00', $tax['stopajRows'][0]['withheld']);
        $this->assertSame(0, $tax['stopajForeign']);
    }

    /* The reporting currency, and a mixed book ------------------------------------- */

    /**
     * 🔴 A mixed book is the normal state, and every headline figure has to
     * survive it.
     *
     * The reporting currency is a setting, and changing it never rewrites an
     * invoice that has already been issued — so older invoices report in one
     * currency and newer ones in another. Both belong in the totals, as **two
     * figures**. This page used to filter for dollars and drop everything else
     * on the floor with no count and no note, which looks exactly like a book
     * that simply earned less.
     */
    public function test_the_headline_totals_group_by_reporting_currency_and_never_add_them(): void
    {
        $customer = $this->foreignCustomer();

        // Raised after the switch to lira.
        $this->liraInvoice($customer, '40000.00', dueDaysAgo: 10, number: 'MIX-TRY', attributes: [
            'reporting_currency' => Currencies::TRY,
            'reporting_rate' => '1.000000',
            'reporting_amount' => '40000.00',
        ]);

        // Raised before it, and untouched by it.
        $this->dollarInvoice($customer, '1000.00', dueDaysAgo: 10);

        $kpis = collect(Livewire::test('accounting::reports')->set('period', 'all')->viewData('kpis'))
            ->keyBy('label');

        $invoiced = $kpis['Invoiced']['values'];

        $this->assertCount(2, $invoiced, 'The two reporting currencies collapsed into '.count($invoiced).' figure(s).');
        $this->assertContains('₺40,000.00', $invoiced);
        $this->assertContains('$1,000.00', $invoiced);

        // 40,000 + 1,000 = 41,000 is what a mixed total would produce.
        foreach ($invoiced as $figure) {
            $this->assertStringNotContainsString('41,000', $figure);
        }

        // And the same rule on the receivables card.
        $outstanding = $kpis['Outstanding today']['values'];

        $this->assertCount(2, $outstanding);
        $this->assertContains('₺40,000.00', $outstanding);
        $this->assertContains('$1,000.00', $outstanding);
    }

    /** An ageing bucket totals per reporting currency too, and empty buckets keep the columns. */
    public function test_the_ageing_card_shows_one_figure_per_reporting_currency(): void
    {
        $customer = $this->foreignCustomer();

        $this->liraInvoice($customer, '40000.00', dueDaysAgo: 45, number: 'AGE-TRY', attributes: [
            'reporting_currency' => Currencies::TRY,
            'reporting_rate' => '1.000000',
            'reporting_amount' => '40000.00',
        ]);
        $this->dollarInvoice($customer, '1000.00', dueDaysAgo: 45);

        $ageing = collect(Livewire::test('accounting::reports')->viewData('ageing'))->keyBy('label');

        $this->assertStringContainsString('$1,000.00', $ageing['31 to 60 days late']['total']);
        $this->assertStringContainsString('₺40,000.00', $ageing['31 to 60 days late']['total']);

        // An empty bucket still names both currencies, so the column reads down.
        $this->assertStringContainsString('$0.00', $ageing['Not due yet']['total']);
        $this->assertStringContainsString('₺0.00', $ageing['Not due yet']['total']);
    }

    /* The KDV export-of-services exemption ------------------------------------------ */

    /**
     * Exempt turnover and KDV-bearing turnover are two different numbers, and a
     * return asks for both.
     */
    public function test_the_tax_summary_separates_exempt_turnover_from_kdv_bearing_turnover(): void
    {
        $customer = $this->foreignCustomer();

        $this->liraInvoice($customer, '12000.00', dueDaysAgo: 5, number: 'KDV-STD', attributes: [
            'subtotal' => '10000.00',
            'tax_percent' => '20',
            'tax_amount' => '2000.00',
            'total' => '12000.00',
        ]);

        $this->exempt(
            $this->liraInvoice($customer, '5000.00', dueDaysAgo: 5, number: 'KDV-302'),
            '302',
        );

        $tax = Livewire::test('accounting::reports')->set('period', 'all')->viewData('tax');

        $row = collect($tax['kdv'])->firstWhere('currency', Currencies::TRY);

        $this->assertSame('₺10,000.00', $row['bearingNet'], 'Exempt turnover leaked into the KDV-bearing figure.');
        $this->assertSame('₺5,000.00', $row['exemptNet']);
        $this->assertSame(1, $row['exemptCount']);
        $this->assertSame('₺15,000.00', $row['net']);
        $this->assertSame('₺2,000.00', $row['charged']);

        // Named by the code it was zero-rated under, which is what a return asks.
        $this->assertCount(1, $tax['exemptRows']);
        $this->assertSame('302', $tax['exemptRows'][0]['code']);
        $this->assertSame(Currencies::TRY, $tax['exemptRows'][0]['currency']);
        $this->assertSame('₺5,000.00', $tax['exemptRows'][0]['net']);
        $this->assertSame(1, $tax['exemptRows'][0]['count']);
    }

    /**
     * 🔴 An invoice that merely carries no KDV is not an exemption claim.
     *
     * Software must not decide the export-of-services question, and a report
     * that counted every zero-tax invoice as zero-rated under 302 would be
     * making exactly that claim on the operator's behalf — on a page they might
     * hand to a mali müşavir.
     */
    public function test_an_invoice_at_zero_percent_is_not_reported_as_an_exemption_claim(): void
    {
        $customer = $this->foreignCustomer();

        // Zero KDV, but nobody applied an exemption to it.
        $this->liraInvoice($customer, '5000.00', dueDaysAgo: 5, number: 'KDV-ZERO');

        $tax = Livewire::test('accounting::reports')->set('period', 'all')->viewData('tax');

        $this->assertSame([], $tax['exemptRows'], 'A zero-rated invoice was reported as an exemption nobody claimed.');
        $this->assertSame(0, $tax['exemptCount']);

        $row = collect($tax['kdv'])->firstWhere('currency', Currencies::TRY);

        // Still counted in the wider "at zero" column, which is a different question.
        $this->assertSame(1, $row['zeroRated']);
        $this->assertSame('₺0.00', $row['exemptNet']);
    }

    /** The page names the exemption and says out loud that Kargah did not apply it. */
    public function test_the_page_names_the_exemption_without_reading_as_advice(): void
    {
        $customer = $this->foreignCustomer();

        $this->exempt($this->liraInvoice($customer, '5000.00', dueDaysAgo: 5, number: 'KDV-PAGE'), '302');

        $this->get('/accounting/reports?period=all')
            ->assertOk()
            ->assertSee('Zero-rated turnover, by exemption code')
            ->assertSee('302')
            ->assertSee('mali müşavir', escape: false);
    }

    /* The page itself -------------------------------------------------------------- */

    public function test_the_tax_section_never_reads_as_advice(): void
    {
        $this->get('/accounting/reports?period=all')
            ->assertOk()
            ->assertSee('Tax summary')
            ->assertSee('mali müşavir', escape: false)
            ->assertSee('Geçici vergi', escape: false);
    }

    /** Reading a report changes nothing. It is the one thing every report owes. */
    public function test_the_new_sections_write_nothing(): void
    {
        $customer = $this->foreignCustomer();
        $invoice = $this->liraInvoice($customer, '10000.00', dueDaysAgo: 45);

        app(PaymentRecorder::class)->record(
            $invoice, '1000.00', Currencies::TRY, now(), settlementRate: '1.000000',
        );

        $this->expense('Hostinger', 'Hosting', '480.00', Currencies::TRY);

        $ledger = LedgerEntry::query()->count();
        $invoices = Invoice::query()->count();

        $this->get('/accounting/reports?period=all')->assertOk();

        $this->assertSame($ledger, LedgerEntry::query()->count(), 'Rendering a report wrote to the ledger.');
        $this->assertSame($invoices, Invoice::query()->count());
    }

    public function test_every_new_section_survives_an_empty_database(): void
    {
        $view = Livewire::test('accounting::reports')->set('period', 'all');

        $this->assertSame('₺0.00', $view->viewData('pnl')['net']);
        $this->assertSame([], $view->viewData('aged')['currencies']);
        $this->assertSame([], $view->viewData('chase'));
        $this->assertSame([], $view->viewData('byCategory'));
        $this->assertSame('₺0.00', $view->viewData('tax')['gecici']);
    }

    public function test_expenses_are_broken_down_by_category(): void
    {
        $this->expense('Hostinger', 'Hosting', '4800.00', Currencies::TRY);
        $this->expense('Hetzner', 'Hosting', '1200.00', Currencies::TRY);
        $this->expense('Figma', 'Software', '900.00', Currencies::TRY);

        $rows = collect(Livewire::test('accounting::reports')->set('period', 'all')->viewData('byCategory'))
            ->keyBy('category');

        $this->assertSame('₺6,000.00', $rows['Hosting']['lira']);
        $this->assertSame(2, $rows['Hosting']['count']);
        $this->assertSame('₺900.00', $rows['Software']['lira']);
        // Biggest first, so the bar is a ratio against a real peak.
        $this->assertSame('Hosting', $rows->keys()->first());
    }

    /* Fixtures ---------------------------------------------------------------------- */

    private function foreignCustomer(): Customer
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

    private function domesticCustomer(): Customer
    {
        $company = Company::factory()->create([
            'name' => 'Bosphorus Yazılım A.Ş.',
            'country' => 'TR',
            'is_domestic' => true,
            'default_currency' => Currencies::TRY,
        ]);

        return Customer::factory()->for($company)->create([
            'name' => 'Elif Demir',
            'email' => 'elif@bosphorus.example',
            'archived_at' => null,
        ]);
    }

    /**
     * An issued lira invoice, due `$dueDaysAgo` days ago.
     *
     * Raised in lira on purpose: that is the first of the three routes an
     * invoice can freeze a lira figure by, and it needs no rate on file, so the
     * fixture states no rate it would then have to defend. A negative
     * `$dueDaysAgo` puts the due date in the future.
     */
    private function liraInvoice(
        Customer $customer,
        string $total,
        int $dueDaysAgo,
        ?string $number = null,
        array $attributes = [],
    ): Invoice {
        $due = now()->startOfDay()->subDays($dueDaysAgo);
        $issued = $due->copy()->subDays(30);

        return Invoice::query()->create(array_merge([
            'number' => $number ?? 'INV-'.str_pad((string) (Invoice::query()->withTrashed()->count() + 1), 4, '0', STR_PAD_LEFT),
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'status' => 'sent',
            'currency' => Currencies::TRY,
            'subtotal' => $total,
            'tax_percent' => '0',
            'tax_amount' => '0',
            'total' => $total,
            'issued_on' => $issued->toDateString(),
            'due_on' => $due->toDateString(),
            'sent_at' => $issued,
            'created_by' => $this->user->id,
        ], $attributes));
    }

    /**
     * An issued dollar invoice reported in dollars — so it froze **no** lira
     * figure at all, which is Kargah's ordinary foreign-client case.
     */
    private function dollarInvoice(Customer $customer, string $total, int $dueDaysAgo): Invoice
    {
        return $this->liraInvoice($customer, $total, $dueDaysAgo, attributes: [
            'currency' => Currencies::USD,
            'reporting_currency' => Currencies::USD,
            'reporting_rate' => '1.000000',
            'reporting_amount' => $total,
        ]);
    }

    /**
     * Apply a KDV exemption to an invoice, the way the builder does.
     *
     * `forceFill` because `kdv_exemption_code` is deliberately not mass
     * assignable — nothing should be able to zero-rate an invoice by putting a
     * key in an array.
     */
    private function exempt(Invoice $invoice, string $code): Invoice
    {
        $invoice->forceFill([
            'kdv_exemption_code' => $code,
            'tax_percent' => '0',
            'tax_amount' => '0',
        ])->save();

        return $invoice->refresh();
    }

    private function expense(
        string $vendor,
        string $category,
        string $amount,
        string $currency,
        ?string $reportingCurrency = Currencies::TRY,
        mixed $on = null,
    ): Expense {
        return Expense::query()->create([
            'vendor' => $vendor,
            'category' => $category,
            'currency' => $currency,
            'amount' => $amount,
            'reporting_currency' => $reportingCurrency,
            'reporting_rate' => $reportingCurrency === null ? null : '1.000000',
            'reporting_amount' => $reportingCurrency === null ? null : $amount,
            'spent_on' => ($on ?? now())->toDateString(),
            'created_by' => $this->user->id,
        ]);
    }
}
