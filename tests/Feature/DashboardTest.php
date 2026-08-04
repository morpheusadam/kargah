<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Accounting\Contracts\ExpenseReader as ExpenseReaderContract;
use Modules\Accounting\Contracts\InvoiceReader as InvoiceReaderContract;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Support\Money;
use Modules\Core\Contracts\Notifier;
use Modules\Core\Models\Customer;
use Modules\Mailbox\Models\Email;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Services\CardService;
use Modules\Project\Support\Position;
use Tests\TestCase;

/**
 * The home screen, proven against real rows.
 *
 * Every widget on this page is a Livewire island with `lazy: true`, so the
 * first HTTP response carries a placeholder skeleton for each — that is the
 * point of `lazy`, and it means `assertSee()` on the raw response cannot see
 * a stat, an agenda row or an empty-state sentence. `with()` still runs in
 * full on that same request (Livewire's `SupportWithMethod` calls it for the
 * top-level render and again for every island pass), so every figure this
 * page will eventually show is asserted through `Livewire::test(...)->
 * viewData(...)` instead — the same "assert on view data, not the response
 * body" rule `DECISIONS.md` already states for islands after an action,
 * applied here to islands before one.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Nima Fazlipour']);
        $this->actingAs($this->user);
    }

    public function test_guests_are_redirected(): void
    {
        auth()->logout();

        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_the_page_contains_none_of_the_old_fixture_strings(): void
    {
        $html = $this->get('/dashboard')->assertOk()->getContent();

        foreach ([
            '$3,380', 'Sam Okafor', 'Rita Vance', 'Jonas Reyes',
            'Northwind scope review', 'Startups DE list', 'INV-0042',
            'Fix invoice PDF margins', 'Send resume to 20 agencies',
            'Build Kargah mail module', '0 / 433',
        ] as $fixture) {
            $this->assertStringNotContainsString($fixture, $html, "Fixture string '{$fixture}' is still on the page.");
        }
    }

    public function test_a_fresh_install_has_empty_collections_not_zero_padded_fixtures(): void
    {
        $component = Livewire::test('pages::dashboard');

        $this->assertSame([], $component->viewData('dueCards'));
        $this->assertSame([], $component->viewData('agenda'));
        $this->assertSame([], $component->viewData('recentActivity'));

        $stats = $component->viewData('stats');
        $this->assertSame('0', $stats[0]['value']); // unpaid invoices
        $this->assertSame('Nothing outstanding', $stats[0]['sub']);
        $this->assertSame('0', $stats[1]['value']); // cards due
        $this->assertSame('0', $stats[2]['value']); // mail — a real zero now: EmailReader::unreadCount() has a source
        $this->assertSame('Inbox zero', $stats[2]['sub']);
        $this->assertSame('0', $stats[3]['value']); // notifications
    }

    /**
     * This is the figure the earlier gap made impossible: the tile now shows
     * `InvoiceReader::totals()`'s own real sum, formatted by `brick/money`
     * inside Accounting — never two formatted strings concatenated on this
     * side of the module boundary. Asserted against the contract's own
     * output rather than a hand-computed string, so the test would fail if
     * the dashboard ever went back to reformatting a number itself.
     */
    public function test_unpaid_invoice_money_is_the_books_real_total_from_invoice_reader(): void
    {
        Invoice::factory()->sent()->create([
            'number' => 'INV-9001', 'currency' => 'USD',
            'subtotal' => '450.000000', 'total' => '450.000000',
            'due_on' => now()->addDays(5)->toDateString(),
        ]);
        Invoice::factory()->sent()->create([
            'number' => 'INV-9002', 'currency' => 'USD',
            'subtotal' => '125.000000', 'total' => '125.000000',
            'due_on' => now()->addDays(20)->toDateString(),
        ]);

        $totals = app(InvoiceReaderContract::class)->totals();
        $outstanding = collect($totals['outstanding'])->firstWhere('currency', 'USD');

        $stats = Livewire::test('pages::dashboard')->viewData('stats');

        $this->assertSame('2', $stats[0]['value']);
        $this->assertSame('575.000000', $outstanding['amount']);
        $this->assertStringContainsString($outstanding['formatted'], $stats[0]['sub']);
        $this->assertStringContainsString('You are owed', $stats[0]['sub']);
    }

    public function test_an_overdue_invoice_is_flagged_and_still_carries_its_own_real_amount(): void
    {
        Invoice::factory()->overdue()->create([
            'number' => 'INV-9003', 'currency' => 'USD',
            'subtotal' => '900.000000', 'total' => '900.000000',
        ]);

        $expected = Money::format('900.000000', 'USD');

        $stats = Livewire::test('pages::dashboard')->viewData('stats');

        $this->assertSame('1', $stats[0]['value']);
        $this->assertStringContainsString('1 overdue', $stats[0]['sub']);
        $this->assertStringContainsString($expected, $stats[0]['sub']);
        $this->assertSame('text-destructive', $stats[0]['tone']);
    }

    /**
     * A part-paid invoice contributes what remains, not its face value — the
     * same rule `PaymentRecorder::outstanding()` applies to one invoice,
     * carried through `InvoiceReader::totals()` for the whole book.
     */
    public function test_a_part_paid_invoice_contributes_its_remaining_balance_to_the_total(): void
    {
        $invoice = Invoice::factory()->sent()->create([
            'number' => 'INV-9004', 'currency' => 'USD',
            'subtotal' => '1000.000000', 'total' => '1000.000000',
            'due_on' => now()->addDays(10)->toDateString(),
        ]);
        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'currency' => 'USD',
            'amount' => '400.000000',
            'applied_amount' => '400.000000',
        ]);

        $totals = app(InvoiceReaderContract::class)->totals();
        $outstanding = collect($totals['outstanding'])->firstWhere('currency', 'USD');

        $this->assertSame('600.000000', $outstanding['amount']);

        $stats = Livewire::test('pages::dashboard')->viewData('stats');
        $this->assertStringContainsString($outstanding['formatted'], $stats[0]['sub']);
    }

    /**
     * The unread tile now matches the inbox page's own `unreadTotal()` for
     * the same fixture — the same query, the same definition, not a second
     * one invented for the dashboard.
     */
    public function test_unread_mail_matches_the_inboxs_own_count(): void
    {
        Email::factory()->unread()->count(3)->create();
        Email::factory()->read()->count(2)->create();
        Email::factory()->unread()->inFolder('Archive')->create();

        $inboxUnreadTotal = Livewire::test('mailbox::inbox')->viewData('unreadTotal');

        $stats = Livewire::test('pages::dashboard')->viewData('stats');

        $this->assertSame(4, $inboxUnreadTotal);
        $this->assertSame((string) $inboxUnreadTotal, $stats[2]['value']);
        $this->assertSame('4', $stats[2]['value']);
    }

    /**
     * The mirror-card rule this whole task warns about: shown on two lists,
     * one card, one entry, one count.
     */
    public function test_a_card_mirrored_onto_two_lists_counts_once_on_the_dashboard(): void
    {
        $board = Board::factory()->create(['slug' => 'client-work']);
        $backlog = BoardList::factory()->for($board)->create(['position' => Position::format('1024')]);
        $todo = BoardList::factory()->for($board)->create(['position' => Position::format('2048')]);

        $cards = app(CardService::class);
        $card = $cards->append($backlog, 'Ship the mirrored feature');
        $card->forceFill(['due_on' => now()->addDay()->toDateString()])->save();
        $cards->mirror($card, $todo);

        $component = Livewire::test('pages::dashboard');

        $this->assertSame('1', $component->viewData('stats')[1]['value']);
        $this->assertCount(1, $component->viewData('dueCards'));
    }

    public function test_notifications_stat_reads_the_real_unread_count(): void
    {
        $notifier = app(Notifier::class);
        $notifier->notify($this->user->id, 'test.one', 'First notification');
        $notifier->notify($this->user->id, 'test.two', 'Second notification');

        $stats = Livewire::test('pages::dashboard')->viewData('stats');

        $this->assertSame((string) $notifier->unreadCount($this->user->id), $stats[3]['value']);
        $this->assertSame('2', $stats[3]['value']);
    }

    public function test_recent_activity_is_a_real_row_written_by_another_module(): void
    {
        $board = Board::factory()->create(['slug' => 'client-work']);
        $list = BoardList::factory()->for($board)->create();

        app(CardService::class)->append($list, 'A card that leaves a trail');

        $activity = Livewire::test('pages::dashboard')->viewData('recentActivity');

        $this->assertNotEmpty($activity);
        $this->assertIsArray($activity[0]);
        $this->assertSame($this->user->name, $activity[0]['actor']);
        $this->assertStringContainsString('added to', $activity[0]['description']);
    }

    public function test_the_contracts_return_arrays_never_eloquent_models(): void
    {
        $board = Board::factory()->create(['slug' => 'client-work']);
        $list = BoardList::factory()->for($board)->create();
        $card = app(CardService::class)->append($list, 'Array, not a model');
        $card->forceFill(['due_on' => now()->subDay()->toDateString()])->save();

        Invoice::factory()->overdue()->create(['currency' => 'USD']);

        $component = Livewire::test('pages::dashboard');

        foreach (['dueCards', 'agenda', 'recentActivity', 'stats'] as $key) {
            $collection = $component->viewData($key);
            $this->assertIsArray($collection);

            foreach ($collection as $row) {
                $this->assertIsArray($row, "{$key} must contain arrays, never a model.");
            }
        }
    }

    /* The charts ------------------------------------------------------------- */

    /**
     * The progressive-enhancement promise, asserted rather than described.
     *
     * The chart is handed one payload and the table under it is rendered from
     * another, and if those two ever drift the person without JavaScript is
     * reading a different book from the person with it. Both are checked
     * against the same figure in the same HTTP response — the response a
     * browser with no ApexCharts would get, which is the only reader this
     * test is about.
     *
     * The chart card is deliberately not inside an island, which is what
     * makes this assertable on the raw response at all; the stat tiles are,
     * and `assertSee` cannot reach them.
     */
    public function test_the_fallback_table_and_the_chart_payload_carry_the_same_figure(): void
    {
        Invoice::factory()->sent()->create([
            'number' => 'INV-9101', 'currency' => 'TRY',
            'subtotal' => '12000.000000', 'total' => '12000.000000',
            'issued_on' => now()->startOfMonth()->toDateString(),
            'sent_at' => now()->startOfMonth(),
            'due_on' => now()->addDays(10)->toDateString(),
        ]);

        $html = $this->get('/dashboard')->assertOk()->getContent();

        // What the table prints: Accounting's own formatted string.
        $this->assertStringContainsString(Money::format('12000.000000', 'TRY'), $html);
        $this->assertStringContainsString('data-trend-fallback', $html);

        // What the chart is handed: the same amount, unformatted, inside the
        // payload attribute. json_encode escapes the lira sign, so the amount
        // is what can be compared across both — which is the point, since it
        // is the figure, not the glyph, that must agree.
        $this->assertStringContainsString('12000.000000', $html);
        $this->assertStringContainsString('data-trend-chart', $html);

        // And the bundle tag actually reached the response. `@push('scripts')`
        // inside a Livewire component is discarded silently — no error, no
        // warning, no script — so "the chart never appears" would otherwise
        // look exactly like a passing test. Matched without its directory
        // because Livewire ships an `@script` block inside `wire:effects` as
        // JSON, where every slash in the path is escaped to `\/`.
        $this->assertStringContainsString('apexcharts.min.js', $html);

        $rows = collect(Livewire::test('pages::dashboard')->viewData('trend')['rows']);
        $month = $rows->firstWhere('label', now()->format('M Y'));

        $this->assertSame('12000.000000', $month['revenue']);
        $this->assertSame(Money::format('12000.000000', 'TRY'), $month['revenue_formatted']);
    }

    /**
     * The rule the whole chart rests on: an invoice that never got a rate is
     * excluded **and counted**, never converted at today's rate and never
     * silently dropped. A series that quietly loses four invoices reads as a
     * bad quarter, and nobody can tell from looking at it.
     */
    public function test_an_invoice_with_no_lira_figure_is_excluded_from_the_series_and_counted_out_loud(): void
    {
        // Dollars, issued, and nothing on the document says what it was worth
        // in lira — exactly what the factory produces, and exactly what an
        // invoice raised for a foreign client looks like when the TCMB fetch
        // failed at issue.
        Invoice::factory()->sent()->create([
            'number' => 'INV-9102', 'currency' => 'USD',
            'subtotal' => '1000.000000', 'total' => '1000.000000',
            'issued_on' => now()->startOfMonth()->toDateString(),
            'sent_at' => now()->startOfMonth(),
            'due_on' => now()->addDays(10)->toDateString(),
        ]);

        $revenue = app(InvoiceReaderContract::class)->revenueByMonth(12);

        $this->assertSame(1, $revenue['excluded']);
        $this->assertSame(0, $revenue['counted']);

        $thisMonth = collect($revenue['months'])->firstWhere('month', now()->format('Y-m'));
        $this->assertSame('0.000000', $thisMonth['amount'], 'A rate was invented for an invoice that has none.');

        $trend = Livewire::test('pages::dashboard')->viewData('trend');

        $this->assertNotNull($trend['note'], 'The excluded invoice is nowhere on the page.');
        $this->assertStringContainsString('1 invoice', $trend['note']);
    }

    /**
     * A frozen figure is used, and it is used exactly as frozen. The invoice
     * is in dollars; the lira figure on the series is the one `issue_rate_to_try`
     * produced on the day it was issued, not the dollar amount and not
     * anything re-derived from a rate table read today.
     */
    public function test_a_dollar_invoice_contributes_the_lira_figure_frozen_on_the_document(): void
    {
        Invoice::factory()->sent()->create([
            'number' => 'INV-9103', 'currency' => 'USD',
            'subtotal' => '1000.000000', 'total' => '1000.000000',
            'issue_rate_to_try' => '33.000000',
            'issue_rate_source' => 'tcmb',
            'issue_rate_date' => now()->startOfMonth()->toDateString(),
            'try_equivalent' => '33000.000000',
            'issued_on' => now()->startOfMonth()->toDateString(),
            'sent_at' => now()->startOfMonth(),
            'due_on' => now()->addDays(10)->toDateString(),
        ]);

        $revenue = app(InvoiceReaderContract::class)->revenueByMonth(12);
        $thisMonth = collect($revenue['months'])->firstWhere('month', now()->format('Y-m'));

        $this->assertSame('33000.000000', $thisMonth['amount']);
        $this->assertSame(0, $revenue['excluded']);
    }

    /**
     * Two currencies are never added into one figure. A book owed $980 and
     * ₺64,800 is owed two amounts, and the aging bucket says so — it does not
     * say 65,780 of anything.
     */
    public function test_two_currencies_are_reported_side_by_side_and_never_added(): void
    {
        Invoice::factory()->overdue()->create([
            'number' => 'INV-9104', 'currency' => 'USD',
            'subtotal' => '980.000000', 'total' => '980.000000',
        ]);
        Invoice::factory()->overdue()->create([
            'number' => 'INV-9105', 'currency' => 'TRY',
            'subtotal' => '64800.000000', 'total' => '64800.000000',
        ]);

        $buckets = collect(app(InvoiceReaderContract::class)->agedReceivables()['buckets'])
            ->keyBy('key');

        $late = $buckets['1_30']['totals'];

        $this->assertSame('980.000000', collect($late)->firstWhere('currency', 'USD')['amount']);
        $this->assertSame('64800.000000', collect($late)->firstWhere('currency', 'TRY')['amount']);
        $this->assertSame(2, $buckets['1_30']['count']);

        $line = collect(Livewire::test('pages::dashboard')->viewData('receivables')['buckets'])
            ->firstWhere('key', '1_30')['money'];

        // Both figures on the line, joined — never one number.
        $this->assertStringContainsString(Money::format('980.000000', 'USD'), $line);
        $this->assertStringContainsString(Money::format('64800.000000', 'TRY'), $line);
        $this->assertStringNotContainsString('65,780', $line);
    }

    /**
     * The buckets are a split of the same money `totals()` reports, not a
     * second definition of outstanding. Adding them back up has to give the
     * tile's own figure, or the page is showing two answers to one question.
     */
    public function test_the_aging_buckets_add_back_up_to_the_books_outstanding_total(): void
    {
        Invoice::factory()->sent()->create([
            'number' => 'INV-9106', 'currency' => 'USD',
            'subtotal' => '450.000000', 'total' => '450.000000',
            'due_on' => now()->addDays(5)->toDateString(),
        ]);
        Invoice::factory()->overdue()->create([
            'number' => 'INV-9107', 'currency' => 'USD',
            'subtotal' => '900.000000', 'total' => '900.000000',
        ]);

        $reader = app(InvoiceReaderContract::class);

        $bucketed = Money::sum(
            collect($reader->agedReceivables()['buckets'])
                ->flatMap(fn (array $bucket): array => $bucket['totals'])
                ->where('currency', 'USD')
                ->pluck('amount'),
            'USD',
        );

        $outstanding = collect($reader->totals()['outstanding'])->firstWhere('currency', 'USD')['amount'];

        $this->assertSame($outstanding, Money::toStorage($bucketed));
        $this->assertSame('1350.000000', $outstanding);
    }

    /**
     * A fully-settled invoice whose status has not caught up owes nothing, so
     * it is in no bucket and in no count. Counting it would tell somebody
     * deciding whether to chase that two invoices are late when one is.
     */
    public function test_an_invoice_that_owes_nothing_is_in_no_aging_bucket(): void
    {
        $invoice = Invoice::factory()->overdue()->create([
            'number' => 'INV-9108', 'currency' => 'USD',
            'subtotal' => '500.000000', 'total' => '500.000000',
        ]);
        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'currency' => 'USD',
            'amount' => '500.000000',
            'applied_amount' => '500.000000',
        ]);

        $this->assertSame(0, app(InvoiceReaderContract::class)->agedReceivables()['count']);
    }

    /**
     * Revenue by client is grouped and rolled up inside Accounting — the page
     * receives finished rows. The "other" row exists so a book with thirty
     * clients still draws six slices and one honest remainder rather than
     * thirty unreadable ones.
     */
    public function test_revenue_by_client_groups_inside_accounting_and_rolls_the_tail_into_one_row(): void
    {
        foreach (['Northwind Studio', 'Karaköy Bilişim', 'Atlas Freight'] as $index => $name) {
            $customer = Customer::factory()->create(['name' => $name]);

            Invoice::factory()->sent()->create([
                'number' => 'INV-92'.$index.'0', 'currency' => 'TRY',
                'customer_id' => $customer->id,
                'subtotal' => (string) (($index + 1) * 1000).'.000000',
                'total' => (string) (($index + 1) * 1000).'.000000',
                'issued_on' => now()->startOfMonth()->toDateString(),
                'sent_at' => now()->startOfMonth(),
                'due_on' => now()->addDays(10)->toDateString(),
            ]);
        }

        $revenue = app(InvoiceReaderContract::class)->revenueByClient(12, 2);

        // Biggest first, then everything past the limit as one row.
        $this->assertSame('Atlas Freight', $revenue['clients'][0]['name']);
        $this->assertSame('3000.000000', $revenue['clients'][0]['amount']);
        $this->assertTrue($revenue['clients'][2]['is_other']);
        $this->assertSame('1000.000000', $revenue['clients'][2]['amount']);
        $this->assertSame(3, $revenue['counted']);

        $rows = Livewire::test('pages::dashboard')->viewData('clientRevenue')['rows'];

        $this->assertSame('Atlas Freight', $rows[0]['name']);
        $this->assertSame(Money::format('3000.000000', 'TRY'), $rows[0]['formatted']);
    }

    /**
     * The expense half of the trend obeys the same rule from the other side:
     * an expense reported in dollars carries no lira figure, so it is counted
     * out rather than converted. The factory's default is exactly that, which
     * is why this is the case worth pinning.
     */
    public function test_expenses_join_the_trend_in_lira_and_the_unconvertible_ones_are_counted(): void
    {
        Expense::factory()->create([
            'currency' => 'TRY',
            'amount' => '2500.000000',
            'reporting_currency' => 'TRY',
            'reporting_rate' => '1.000000',
            'reporting_amount' => '2500.000000',
            'spent_on' => now()->startOfMonth()->toDateString(),
        ]);
        Expense::factory()->create([
            'spent_on' => now()->startOfMonth()->toDateString(),
        ]);

        $expenses = app(ExpenseReaderContract::class)->expensesByMonth(12);
        $thisMonth = collect($expenses['months'])->firstWhere('month', now()->format('Y-m'));

        $this->assertSame('2500.000000', $thisMonth['amount']);
        $this->assertSame(1, $expenses['excluded']);

        $trend = Livewire::test('pages::dashboard')->viewData('trend');
        $row = collect($trend['rows'])->firstWhere('label', now()->format('M Y'));

        $this->assertSame('2500.000000', $row['expenses']);
        $this->assertStringContainsString('1 expense', $trend['note']);
    }

    /**
     * The rule this page's own docblock states, enforced rather than trusted:
     * money is summed inside Accounting and read back already summed. A
     * `->sum(` or an `array_sum(` appearing here would mean a figure was
     * added on this side of the module boundary, where there is no `Money`
     * and no currency to check it against.
     */
    public function test_the_dashboard_adds_no_money_of_its_own(): void
    {
        $source = file_get_contents(resource_path('views/pages/⚡dashboard.blade.php'));

        foreach (['array_sum(', '->sum(', 'Money::sum', 'SUM('] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                'The dashboard is adding money itself. It must read it already summed from Accounting.',
            );
        }
    }

    public function test_exactly_four_islands_each_lazy_and_none_inside_a_loop(): void
    {
        $source = file_get_contents(resource_path('views/pages/⚡dashboard.blade.php'));

        // One `@island` per source directive — never one per loop iteration.
        // See DECISIONS.md: the token is assigned at compile time from the
        // ordinal of the directive in the file, so a directive inside a
        // `@foreach` shares one token across every row.
        $this->assertSame(4, substr_count($source, '@island('));
        $this->assertSame(4, substr_count($source, 'lazy: true'));

        foreach (['stats', 'due-cards', 'agenda', 'activity'] as $name) {
            $this->assertStringContainsString("name: '{$name}'", $source);
        }
    }

    /**
     * A bounded number of queries against a seeded database — not a
     * wall-clock budget, which the shared test box cannot promise. The
     * dominant cost here is `InvoiceReader`'s own per-invoice payment lookup
     * inside Accounting (ten invoices, ten extra queries) — not this page's
     * to fix, and reported separately. What this bounds is this page's own
     * contribution staying flat rather than multiplying: `with()` runs once
     * per island pass on first mount (five times in this Livewire version),
     * and every aggregate here is wrapped in `Cache::flexible()` precisely so
     * that repetition reads from cache instead of re-querying.
     */
    public function test_the_page_issues_a_bounded_number_of_queries_on_a_seeded_database(): void
    {
        $board = Board::factory()->create(['slug' => 'client-work']);
        $list = BoardList::factory()->for($board)->create();
        $cards = app(CardService::class);

        for ($i = 0; $i < 10; $i++) {
            $card = $cards->append($list, "Seeded card {$i}");
            $card->forceFill(['due_on' => now()->addDay()->toDateString()])->save();
        }

        for ($i = 0; $i < 10; $i++) {
            Invoice::factory()->sent()->create(['due_on' => now()->addDays($i)->toDateString()]);
        }

        DB::enableQueryLog();
        Livewire::test('pages::dashboard')->html();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(35, $count, 'Query count grew — check a cache key stopped deduplicating repeated with() calls.');
    }
}
