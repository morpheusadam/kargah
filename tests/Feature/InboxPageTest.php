<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Database\Seeders\CoreDatabaseSeeder;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;
use Modules\Mailbox\Database\Seeders\MailboxDatabaseSeeder;
use Modules\Mailbox\Models\Email;
use Modules\Mailbox\Models\EmailAttachment;
use Modules\Mailbox\Models\EmailThread;
use Modules\Mailbox\Models\MailAccount;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Tests\TestCase;

/**
 * The inbox, reading the local mail store.
 *
 * Phase 4's acceptance criteria that belong to this page are all here: an email
 * from a known address shows its customer, converting one to a card links both
 * ways, and the page renders inside its budget with ten thousand messages
 * stored. The rest of the file guards the two things about this page that are
 * easy to break silently — an island nobody names, and a list query that starts
 * dragging whole message bodies across the wire.
 */
class InboxPageTest extends TestCase
{
    use RefreshDatabase;

    private MailAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'Nima Fazlipour']));

        $this->account = MailAccount::factory()->create([
            'name' => 'Studio inbox',
            'email' => 'nima@kargah.dev',
            'last_synced_at' => now()->subMinutes(9),
        ]);
    }

    /* Helpers ------------------------------------------------------------------ */

    private function email(array $attributes = []): Email
    {
        return Email::factory()->for($this->account, 'account')->create($attributes);
    }

    /** A customer whose address the sync would have resolved. */
    private function customer(string $name, string $email): Customer
    {
        return Customer::factory()
            ->for(Company::factory()->create(['name' => 'Northwind Ltd']))
            ->create(['name' => $name, 'email' => $email]);
    }

    private function boardList(string $board = 'Client Work', string $list = 'To Do'): BoardList
    {
        return BoardList::factory()
            ->for(Board::factory()->create(['name' => $board, 'slug' => 'client-work']))
            ->create(['name' => $list]);
    }

    /* Rendering ---------------------------------------------------------------- */

    /**
     * Against the module's own seeder, which is what a fresh install sees.
     *
     * The seeder is the phase's other half — messages across threads, several
     * of them from addresses belonging to Core's customers — so this is the
     * test that proves the page and the data agree about the shape of a row.
     */
    public function test_the_inbox_renders_against_the_seeder(): void
    {
        // Core first, because several of the senders are its customers and the
        // resolution join is what makes those rows interesting. The root
        // `DatabaseSeeder` would pull in every other module too, and a page
        // test should not fail because a module it does not read is mid-build.
        $this->seed(CoreDatabaseSeeder::class);
        $this->seed(MailboxDatabaseSeeder::class);

        $this->assertGreaterThan(0, Email::query()->count(), 'The Mailbox seeder produced no messages to render.');
        $this->assertGreaterThan(
            0,
            Email::query()->whereNotNull('customer_id')->count(),
            'No seeded message resolved to a customer, so the CRM join is untested.',
        );

        $newest = Email::query()->inFolder('INBOX')->recent()->firstOrFail();

        $this->get('/mail/inbox')
            ->assertOk()
            ->assertSee($newest->subject)
            ->assertSee($newest->senderLabel());
    }

    /** And against nothing at all, which is what a fresh install sees first. */
    public function test_the_inbox_renders_against_an_empty_database(): void
    {
        $this->account->forceDelete();

        $this->get('/mail/inbox')
            ->assertOk()
            ->assertSee('This folder is empty')
            ->assertSee('No mailbox is connected yet', false)
            ->assertSee('No message open');
    }

    public function test_the_heading_counts_what_is_actually_unread(): void
    {
        Email::factory()->count(4)->for($this->account, 'account')->unread()->create();
        Email::factory()->count(3)->for($this->account, 'account')->read()->create();

        Livewire::test('mailbox::inbox')->assertViewHas('unreadTotal', 4);
    }

    /* Opening a message --------------------------------------------------------- */

    public function test_selecting_a_message_marks_it_read(): void
    {
        $email = $this->email(['is_read' => false, 'subject' => 'Re: Invoice INV-0041']);

        Livewire::test('mailbox::inbox')
            ->call('selectEmail', $email->id)
            ->assertSet('selected', $email->id);

        $this->assertTrue($email->fresh()->is_read, 'Opening a message left it unread.');
    }

    public function test_a_deep_link_from_another_module_opens_the_message_and_its_folder(): void
    {
        // `EmailReader::forCustomer()` hands other modules
        // route('mail.inbox', ['email' => id]); a client page that links to a
        // message has to land on it, wherever it was filed.
        $email = $this->email(['is_read' => false, 'folder' => 'Archive', 'subject' => 'Contract signed']);

        $this->get(route('mail.inbox', ['email' => $email->id]))
            ->assertOk()
            ->assertSee('Contract signed');

        $this->assertTrue($email->fresh()->is_read);
    }

    public function test_a_message_that_was_deleted_while_the_page_was_open_says_so(): void
    {
        $component = Livewire::test('mailbox::inbox')->call('selectEmail', 999_999);

        $this->assertSame(
            'That message is gone',
            collect($component->effects['dispatches'] ?? [])->firstWhere('name', 'toast')['params'][0]['message'] ?? null,
        );
    }

    /* The acceptance criterion: a known sender shows their customer -------------- */

    public function test_an_email_from_a_known_address_shows_its_customer(): void
    {
        $customer = $this->customer('Sam Okafor', 'sam@northwind.example');

        $email = $this->email([
            'from_name' => 'Sam Okafor',
            'from_email' => 'sam@northwind.example',
            'customer_id' => $customer->id,
            'subject' => 'Re: Invoice INV-0041',
        ]);

        // In the list…
        $this->get('/mail/inbox')
            ->assertOk()
            ->assertSee('Sam Okafor');

        // …and in the reading pane, with a link to the client's own page.
        $this->get(route('mail.inbox', ['email' => $email->id]))
            ->assertOk()
            ->assertSee('Sam Okafor')
            ->assertSee('/accounting/clients/'.$customer->id, false);
    }

    public function test_a_message_from_a_stranger_claims_no_customer(): void
    {
        $email = $this->email([
            'from_name' => 'GitHub',
            'from_email' => 'noreply@github.com',
            'customer_id' => null,
        ]);

        $this->get(route('mail.inbox', ['email' => $email->id]))
            ->assertOk()
            ->assertDontSee('/accounting/clients/', false);
    }

    /* The acceptance criterion: converting to a card links both ways -------------- */

    public function test_converting_an_email_to_a_card_links_both_ways_and_the_card_lands_on_the_board(): void
    {
        $customer = $this->customer('Rita Vance', 'rita@acmestudio.example');
        $list = $this->boardList();

        $email = $this->email([
            'subject' => 'Scope change for the landing page',
            'from_name' => 'Rita Vance',
            'from_email' => 'rita@acmestudio.example',
            'customer_id' => $customer->id,
            'body_text' => 'Can we add two more sections before launch?',
        ]);

        Livewire::test('mailbox::inbox')
            ->call('selectEmail', $email->id)
            ->call('openConvert')
            ->assertSet('convertOpen', true)
            ->call('convertToCard', $list->id)
            ->assertSet('convertOpen', false)
            ->assertDispatched('toast');

        $card = Card::query()->where('title', 'Scope change for the landing page')->firstOrFail();

        // Both ends of the same link row answer.
        $this->assertTrue($email->fresh()->isLinkedTo($card), 'The message does not know its card.');
        $this->assertTrue($card->isLinkedTo($email), 'The card does not know its message.');
        $this->assertSame($card->id, $email->fresh()->linked('card', 'converted_to')->first()->id);
        $this->assertSame($email->id, $card->linked('email', 'converted_to')->first()->id);

        // The card inherits the customer the sender was already resolved to.
        $this->assertSame($customer->id, $card->customer_id);

        // And it is on the board, at the bottom of the list that was picked.
        $this->assertSame($list->id, $card->board_list_id);
        $this->get('/projects?board=client-work')
            ->assertOk()
            ->assertSee('Scope change for the landing page');
    }

    public function test_the_reading_pane_shows_the_card_a_message_became(): void
    {
        $list = $this->boardList();
        $email = $this->email(['subject' => 'Retainer renewal — September onwards']);

        Livewire::test('mailbox::inbox')
            ->call('selectEmail', $email->id)
            ->call('convertToCard', $list->id)
            ->assertViewHas('openCards', fn ($cards): bool => $cards->count() === 1);
    }

    public function test_converting_without_a_message_open_says_so_rather_than_throwing(): void
    {
        $list = $this->boardList();

        Livewire::test('mailbox::inbox')
            ->call('convertToCard', $list->id)
            ->assertDispatched('toast');

        $this->assertSame(0, Card::query()->count());
    }

    /* Filters, folders and search -------------------------------------------------- */

    public function test_the_list_shows_only_the_folder_that_is_open(): void
    {
        $this->email(['subject' => 'Still in the inbox', 'folder' => 'INBOX']);
        $this->email(['subject' => 'Filed away last week', 'folder' => 'Archive']);

        $subjects = fn ($paginator): array => collect($paginator->items())->pluck('subject')->all();

        Livewire::test('mailbox::inbox')
            ->assertViewHas('emails', fn ($emails): bool => $subjects($emails) === ['Still in the inbox'])
            ->call('setFolder', 'Archive')
            ->assertViewHas('emails', fn ($emails): bool => $subjects($emails) === ['Filed away last week']);
    }

    public function test_the_unread_and_starred_filters_narrow_the_list(): void
    {
        $this->email(['subject' => 'Unread and plain', 'is_read' => false, 'is_starred' => false]);
        $this->email(['subject' => 'Read and starred', 'is_read' => true, 'is_starred' => true]);

        $subjects = fn ($paginator): array => collect($paginator->items())->pluck('subject')->all();

        Livewire::test('mailbox::inbox')
            ->call('toggleUnreadOnly')
            ->assertViewHas('emails', fn ($emails): bool => $subjects($emails) === ['Unread and plain'])
            ->call('toggleUnreadOnly')
            ->call('toggleStarredOnly')
            ->assertViewHas('emails', fn ($emails): bool => $subjects($emails) === ['Read and starred']);
    }

    public function test_the_search_reaches_the_subject_the_sender_and_the_body(): void
    {
        // Both name and address are pinned: the factory picks a sender pair at
        // random, and a search that matches an address nobody meant to seed is
        // a test that fails one run in six for no reason.
        $this->email([
            'subject' => 'Purchase order number',
            'from_name' => 'Sam Okafor', 'from_email' => 'sam@northwind.example',
            'body_text' => 'Nothing useful here.',
        ]);
        $this->email([
            'subject' => 'Unrelated',
            'from_name' => 'Marta Lindqvist', 'from_email' => 'marta@orbitstudio.example',
            'body_text' => 'Finance wants the PO number on the line.',
        ]);
        $this->email([
            'subject' => 'Another one',
            'from_name' => 'Joris Bakker', 'from_email' => 'joris@acmestudio.example',
            'body_text' => 'Nothing useful here either.',
        ]);

        $count = fn ($paginator): int => count($paginator->items());

        Livewire::test('mailbox::inbox')
            ->set('search', 'purchase order')
            ->assertViewHas('emails', fn ($emails): bool => $count($emails) === 1)
            ->set('search', 'PO number')
            ->assertViewHas('emails', fn ($emails): bool => $count($emails) === 1)
            ->set('search', 'Marta')
            ->assertViewHas('emails', fn ($emails): bool => $count($emails) === 1)
            ->set('search', 'nothing at all like this')
            ->assertViewHas('emails', fn ($emails): bool => $count($emails) === 0);
    }

    public function test_a_folder_the_sync_has_never_seen_falls_back_to_the_inbox(): void
    {
        $this->email(['subject' => 'Still in the inbox']);

        Livewire::withQueryParams(['folder' => 'Made up'])
            ->test('mailbox::inbox')
            ->assertSet('folder', 'INBOX');
    }

    /* Actions that persist ----------------------------------------------------------- */

    public function test_starring_and_unstarring_persist(): void
    {
        $email = $this->email(['is_starred' => false]);

        Livewire::test('mailbox::inbox')->call('toggleStar', $email->id);
        $this->assertTrue($email->fresh()->is_starred);

        Livewire::test('mailbox::inbox')->call('toggleStar', $email->id);
        $this->assertFalse($email->fresh()->is_starred);
    }

    public function test_marking_read_and_unread_persist(): void
    {
        $email = $this->email(['is_read' => true]);

        Livewire::test('mailbox::inbox')->call('markUnread', $email->id);
        $this->assertFalse($email->fresh()->is_read);

        Livewire::test('mailbox::inbox')->call('markRead', $email->id);
        $this->assertTrue($email->fresh()->is_read);
    }

    public function test_archiving_moves_the_message_out_of_the_inbox(): void
    {
        $email = $this->email(['folder' => 'INBOX']);

        Livewire::test('mailbox::inbox')->call('archive', $email->id);

        $this->assertSame('Archive', $email->fresh()->folder);
    }

    public function test_a_bulk_action_writes_one_statement_for_the_whole_selection(): void
    {
        $ids = Email::factory()->count(5)->for($this->account, 'account')->create()->pluck('id')->all();

        $component = Livewire::test('mailbox::inbox');

        foreach ($ids as $id) {
            $component->call('toggleChecked', $id);
        }

        DB::enableQueryLog();
        $component->call('archiveChecked');
        $updates = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'update'));
        DB::disableQueryLog();

        $this->assertCount(1, $updates, 'Archiving five messages wrote five statements instead of one.');
        $this->assertSame(5, Email::query()->inFolder('Archive')->count());
        $this->assertSame([], $component->get('checked'));
    }

    /* Islands -------------------------------------------------------------------------- */

    /**
     * An island inside a `@foreach` shares one compile-time token with every
     * iteration, and the client finds the fragment to morph by token alone — so
     * asking for the seventh row morphs the first. The list and the pane are
     * therefore one directive each, at the top level of the file.
     */
    public function test_the_page_declares_exactly_two_islands_and_names_them_both(): void
    {
        $source = $this->source();

        $this->assertSame(2, substr_count($source, '@island('), 'A third island, or one inside a loop, breaks fragment targeting.');
        $this->assertStringContainsString("@island(name: 'list')", $source);
        $this->assertStringContainsString("@island(name: 'pane')", $source);
        $this->assertStringContainsString("renderIsland('list')", $source);
        $this->assertStringContainsString("renderIsland('pane')", $source);
    }

    /**
     * An island nobody names keeps whatever the DOM already had — the fragment
     * comes back with `mode=skip` and the morph engine walks straight past it.
     * Every action that changes what the list shows has to name it.
     */
    public function test_an_action_that_changes_the_list_names_its_island(): void
    {
        $email = $this->email(['is_read' => false]);
        $this->email(['folder' => 'Archive']);

        $changesTheList = [
            ['toggleStar', [$email->id]],
            ['markUnread', [$email->id]],
            ['archive', [$email->id]],
            ['setFolder', ['Archive']],
            ['toggleUnreadOnly', []],
            ['toggleStarredOnly', []],
            ['goToCursor', ['']],
            ['checkAllOnPage', []],
        ];

        foreach ($changesTheList as [$method, $arguments]) {
            $component = Livewire::test('mailbox::inbox')->call($method, ...$arguments);

            $this->assertNotEmpty(
                $component->effects['islandFragments'] ?? [],
                $method.'() changed the list but never named the island, so the browser keeps the old rows.',
            );
        }
    }

    /**
     * And the whole point of the split: an action that changes neither region
     * must not re-send twenty-five rows to say so.
     */
    public function test_an_action_that_changes_neither_region_names_no_island(): void
    {
        $email = $this->email();

        $touchesNothing = [
            ['toggleChecked', [$email->id]],
            ['openConvert', []],
            ['dismissPanels', []],
        ];

        foreach ($touchesNothing as [$method, $arguments]) {
            $component = Livewire::test('mailbox::inbox')
                ->call('selectEmail', $email->id)
                ->call($method, ...$arguments);

            // `selectEmail` named the pane on the previous round trip; each
            // call is its own request, so this is only the second one's work.
            $this->assertEmpty(
                $component->effects['islandFragments'] ?? [],
                $method.'() re-sent an island that nothing on screen needed.',
            );
        }
    }

    /**
     * Opening a message you have already read is the case islands exist for:
     * the pane changes, the list does not, and only the pane travels.
     */
    public function test_opening_a_message_that_was_already_read_sends_the_pane_and_not_the_list(): void
    {
        $read = $this->email(['is_read' => true]);
        $unread = $this->email(['is_read' => false]);

        $fragments = fn ($component): array => $component->effects['islandFragments'] ?? [];

        $onlyPane = Livewire::test('mailbox::inbox')->call('selectEmail', $read->id);
        $this->assertCount(1, $fragments($onlyPane));
        $this->assertStringContainsString('name=pane', $fragments($onlyPane)[0]);

        // An unread one does change a row, so it names the list as well.
        $both = Livewire::test('mailbox::inbox')->call('selectEmail', $unread->id);
        $this->assertCount(2, $fragments($both));
        $this->assertStringContainsString('name=list', implode('', $fragments($both)));
    }

    /* Cursor pagination ------------------------------------------------------------------ */

    /**
     * Offset pagination scans and discards every row it skips; at page 400 of
     * an inbox that is the whole table. The ordering is
     * `coalesce(received_at, created_at)` because a cursor compares on its
     * ordering column and `NULL < ?` is never true — a message that arrived
     * without a date would fall out of every page after the first, which is
     * exactly what the undated message below is here to catch.
     */
    public function test_the_list_pages_forward_and_back_without_losing_or_repeating_a_message(): void
    {
        Email::factory()->count(59)->for($this->account, 'account')->create();
        $this->email(['subject' => 'Arrived without a date', 'received_at' => null]);

        $ids = [];
        $component = Livewire::test('mailbox::inbox');
        $cursor = '';

        for ($page = 0; $page < 3; $page++) {
            $component->call('goToCursor', $cursor);

            $paginator = $component->viewData('emails');
            $ids = [...$ids, ...collect($paginator->items())->pluck('id')->all()];
            $cursor = (string) $paginator->nextCursor()?->encode();
        }

        $this->assertCount(60, $ids, 'Paging three times did not walk every message.');
        $this->assertSame($ids, array_values(array_unique($ids)), 'A message appeared on two pages.');
        $this->assertSame(Email::query()->count(), count(array_unique($ids)));
    }

    public function test_a_corrupt_cursor_is_the_first_page_rather_than_a_stack_trace(): void
    {
        Email::factory()->count(3)->for($this->account, 'account')->create();

        Livewire::withQueryParams(['cursor' => 'not-a-real-cursor'])
            ->test('mailbox::inbox')
            ->assertViewHas('emails', fn ($emails): bool => count($emails->items()) === 3);
    }

    /* The message itself -------------------------------------------------------------------- */

    public function test_remote_html_is_shown_as_text_and_never_rendered(): void
    {
        $email = $this->email([
            'subject' => 'Newsletter',
            'body_text' => null,
            'body_html' => '<html><head><style>p{color:red}</style></head><body>'
                .'<script>alert(1)</script><p>The proposal is attached.</p></body></html>',
        ]);

        $response = $this->get(route('mail.inbox', ['email' => $email->id]))->assertOk();

        $response->assertSee('The proposal is attached.');
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertDontSee('p{color:red}', false);
        $response->assertSee('arrived as HTML');
    }

    public function test_attachments_are_listed_as_metadata_and_say_so(): void
    {
        $email = $this->email(['has_attachments' => true, 'subject' => 'Contract signed']);

        EmailAttachment::factory()->create([
            'email_id' => $email->id,
            'filename' => 'bluepeak-agreement-signed.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 294_912,
        ]);

        // An inline image is part of the body, not an attachment anyone wants listed.
        EmailAttachment::factory()->inline()->create(['email_id' => $email->id]);

        $this->get(route('mail.inbox', ['email' => $email->id]))
            ->assertOk()
            ->assertSee('bluepeak-agreement-signed.pdf')
            ->assertSee('288 KB')
            ->assertDontSee('signature.png')
            ->assertSee('stored by the Data module');
    }

    public function test_the_pane_shows_the_recipients_and_the_date(): void
    {
        $email = $this->email([
            'subject' => 'Hand-over notes',
            'to' => [['name' => 'Nima Fazlipour', 'email' => 'nima@kargah.dev']],
            'cc' => [['name' => 'Helen Vasquez', 'email' => 'helen@northwind.example']],
            'received_at' => now()->subDays(2)->setTime(11, 5),
        ]);

        $this->get(route('mail.inbox', ['email' => $email->id]))
            ->assertOk()
            ->assertSee('nima@kargah.dev')
            ->assertSee('helen@northwind.example')
            ->assertSee($email->received_at->format('j M Y'));
    }

    public function test_the_rest_of_a_conversation_is_reachable_from_the_open_message(): void
    {
        $thread = EmailThread::factory()->create(['subject' => 'Retainer renewal']);

        $first = $this->email([
            'email_thread_id' => $thread->id,
            'subject' => 'Retainer renewal — September onwards',
            'received_at' => now()->subDays(3),
        ]);
        $this->email([
            'email_thread_id' => $thread->id,
            'subject' => 'Re: Retainer renewal — September onwards',
            'from_name' => 'Helen Vasquez',
            'received_at' => now()->subDays(1),
        ]);

        Livewire::test('mailbox::inbox')
            ->call('selectEmail', $first->id)
            ->assertViewHas('threadMessages', fn ($messages): bool => $messages->count() === 2);
    }

    /* The performance criterion ------------------------------------------------------------- */

    /**
     * "The inbox renders in under 200 ms with 10,000 messages stored."
     *
     * Measured warm — the first request compiles the component's Blade and the
     * budget in the spec is a warm one. Three samples and the median, because a
     * single sample on a machine that is also running a test suite measures the
     * machine as much as the page.
     */
    public function test_the_inbox_renders_inside_its_budget_with_ten_thousand_messages(): void
    {
        $this->storeTenThousandMessages();

        $this->get('/mail/inbox')->assertOk();

        $samples = [];

        for ($run = 0; $run < 3; $run++) {
            $started = hrtime(true);
            $response = $this->get('/mail/inbox');
            $samples[] = (hrtime(true) - $started) / 1_000_000;

            $response->assertOk();
        }

        sort($samples);
        $median = $samples[1];

        $this->assertLessThan(
            200,
            $median,
            'The inbox took '.round($median).' ms with 10,000 messages stored (samples: '
                .implode(' ms, ', array_map(fn (float $ms): string => (string) round($ms), $samples)).' ms).',
        );
    }

    /**
     * The budget is only held by not asking for the whole table.
     *
     * A count is a proxy that survives a slow machine: 10,000 rows stored and a
     * page that still issues a handful of queries is the property the timing
     * test is really asserting.
     */
    public function test_the_inbox_reads_a_page_of_messages_rather_than_the_table(): void
    {
        $this->storeTenThousandMessages();

        $this->get('/mail/inbox')->assertOk();

        DB::enableQueryLog();
        $this->get('/mail/inbox')->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThan(15, count($queries), 'The inbox issued '.count($queries).' queries to draw one page.');

        $list = collect($queries)->first(fn (array $query): bool => str_contains($query['query'], 'list_preview'));

        $this->assertNotNull($list, 'The list query was not issued at all.');
        $this->assertStringContainsString('limit 26', $list['query'], 'The list query is not bounded to one page.');

        // `body_html` appears inside the preview expression as a fallback and
        // nowhere else: selecting the column itself would put a megabyte of
        // newsletter markup on the wire to draw twenty-five one-line previews.
        $this->assertStringNotContainsString('"body_html"', $list['query']);
        $this->assertStringNotContainsString('"body_text"', $list['query']);
    }

    public function test_the_list_rows_carry_content_visibility(): void
    {
        Email::factory()->count(3)->for($this->account, 'account')->create();

        // Skips layout and paint for the rows scrolled out of view. One CSS
        // declaration, and it composes with Livewire's morphing, which is what
        // a JavaScript virtualiser does not.
        $this->get('/mail/inbox')
            ->assertOk()
            ->assertSee('content-visibility: auto', false)
            ->assertSee('contain-intrinsic-size', false);
    }

    /* No fixtures left behind ------------------------------------------------------------------- */

    /**
     * A phase is not done while its page still claims it cannot do the thing.
     *
     * Sending is the one honest exception — it is phase 5, there are no
     * delivery providers yet, and a reply that says it went out and did not is
     * worse than a button that says when it will work. It has to say that,
     * though, rather than "not connected yet".
     */
    public function test_nothing_on_the_page_says_it_is_not_connected(): void
    {
        $source = $this->source();

        $this->assertStringNotContainsString('Not connected yet', $source);
        $this->assertStringNotContainsString('toastInfo(\'Not connected', $source);

        // The one "not yet" that is allowed names the phase it is waiting for.
        $this->assertStringContainsString('Sending arrives with the next phase', $source);

        // And no fixture array survives anywhere in it.
        $this->assertStringNotContainsString("'snippet' =>", $source);
        $this->assertStringNotContainsString('me@kargah.dev', $source);
    }

    public function test_the_page_never_renders_a_message_body_unescaped(): void
    {
        $source = $this->source();

        $this->assertStringNotContainsString('{!!', $source, 'An unescaped echo in a page that prints mail from strangers.');
    }

    /* Fixtures for the two heavy tests -------------------------------------------------------- */

    /**
     * Ten thousand messages, inserted in chunks.
     *
     * The factory would be ten thousand round trips and several minutes; the
     * page under test does not care how the rows got there, only that they are
     * there.
     */
    private function storeTenThousandMessages(): void
    {
        $now = now();
        $rows = [];

        for ($i = 1; $i <= 10_000; $i++) {
            $rows[] = [
                'mail_account_id' => $this->account->id,
                'email_thread_id' => null,
                'message_id' => '<bulk-'.$i.'@kargah.local>',
                'uid' => $i,
                'subject' => 'Retainer renewal — message '.$i,
                'from_name' => 'Sam Okafor',
                'from_email' => 'sam@northwind.example',
                'to' => json_encode([['name' => 'Nima Fazlipour', 'email' => 'nima@kargah.dev']]),
                'cc' => null,
                'body_text' => str_repeat('The September retainer covers the same scope as August. ', 30),
                'body_html' => null,
                'has_attachments' => false,
                'customer_id' => null,
                'is_read' => $i % 3 === 0,
                'is_starred' => $i % 17 === 0,
                'folder' => 'INBOX',
                'received_at' => $now->copy()->subMinutes($i),
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) === 500) {
                Email::query()->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            Email::query()->insert($rows);
        }

        $this->assertSame(10_000, Email::query()->count());
    }

    private function source(): string
    {
        return (string) file_get_contents(
            base_path('Modules/Mailbox/resources/views/components/⚡inbox.blade.php'),
        );
    }
}
