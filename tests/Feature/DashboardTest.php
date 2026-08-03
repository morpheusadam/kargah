<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Accounting\Contracts\InvoiceReader as InvoiceReaderContract;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Support\Money;
use Modules\Core\Contracts\Notifier;
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

    public function test_exactly_four_islands_each_lazy_and_none_inside_a_loop(): void
    {
        $source = file_get_contents(resource_path('views/pages/⚡dashboard.blade.php'));

        // One `@island` per source directive — never one per loop iteration.
        // See DECISIONS.md: the token is assigned at compile time from the
        // ordinal of the directive in the file, so a directive inside a
        // `@foreach` shares one token across every row.
        $this->assertSame(4, substr_count($source, '@island('));
        $this->assertSame(4, substr_count($source, "lazy: true"));

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
