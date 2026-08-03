<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;
use Modules\Project\Contracts\CardReader;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Services\CardService;
use Modules\Project\Support\Position;
use Tests\TestCase;

/**
 * The join between a card and the person it is for.
 *
 * This is the phase's real subject. Boards are worth building first because a
 * card is the thing every other module eventually wants to point at, and this
 * is the proof that pointing at it works — in both directions, and through a
 * contract rather than by one module reaching into another's models.
 */
class CardCustomerTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private BoardList $todo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $company = Company::factory()->create(['name' => 'Northwind Ltd']);
        $this->customer = Customer::factory()->for($company)->create(['name' => 'Sam Okafor', 'email' => 'sam@northwind.example']);

        $board = Board::factory()->create(['name' => 'Client Work', 'slug' => 'client-work']);
        $this->todo = BoardList::factory()->for($board)->create(['name' => 'To Do', 'position' => Position::format('1024')]);
    }

    private function reader(): CardReader
    {
        return app(CardReader::class);
    }

    public function test_a_card_can_belong_to_a_customer_and_be_read_back_from_the_customer(): void
    {
        $card = app(CardService::class)->append($this->todo, 'Send the Northwind retainer proposal');

        $this->assertTrue($this->reader()->assignToCustomer($card->id, $this->customer->id));

        $cards = $this->reader()->forCustomer($this->customer->id);

        $this->assertCount(1, $cards);
        $this->assertSame('Send the Northwind retainer proposal', $cards->first()['title']);
        $this->assertSame('Client Work', $cards->first()['board']);
        $this->assertSame('To Do', $cards->first()['list']);
        $this->assertSame(1, $this->reader()->countForCustomer($this->customer->id));
    }

    public function test_the_contract_hands_back_arrays_rather_than_another_module_s_models(): void
    {
        // Accounting and Mailbox may read cards; neither may hold one. A model
        // crossing the boundary is how a modular monolith becomes a monolith.
        $card = app(CardService::class)->append($this->todo, 'Fix invoice PDF margins');
        $this->reader()->assignToCustomer($card->id, $this->customer->id);

        $row = $this->reader()->forCustomer($this->customer->id)->first();

        $this->assertIsArray($row);
        $this->assertSame(
            ['id', 'title', 'board', 'list', 'due_on', 'due_state', 'is_archived', 'url'],
            array_keys($row),
        );
    }

    public function test_detaching_a_customer_leaves_the_card_on_the_board(): void
    {
        $card = app(CardService::class)->append($this->todo, 'Collect testimonials from past clients');
        $this->reader()->assignToCustomer($card->id, $this->customer->id);

        $this->assertTrue($this->reader()->assignToCustomer($card->id, null));

        $this->assertSame(0, $this->reader()->countForCustomer($this->customer->id));
        $this->assertDatabaseHas('cards', ['id' => $card->id, 'customer_id' => null]);
    }

    public function test_an_archived_card_is_out_of_the_count_but_still_findable(): void
    {
        $card = app(CardService::class)->append($this->todo, 'Register the kargah.dev domain');
        $this->reader()->assignToCustomer($card->id, $this->customer->id);
        app(CardService::class)->archive($card);

        $this->assertSame(0, $this->reader()->countForCustomer($this->customer->id));
        $this->assertCount(0, $this->reader()->forCustomer($this->customer->id));
        $this->assertCount(1, $this->reader()->forCustomer($this->customer->id, includeArchived: true));
    }

    public function test_assigning_a_card_that_does_not_exist_says_so_rather_than_throwing(): void
    {
        $this->assertFalse($this->reader()->assignToCustomer(999_999, $this->customer->id));
    }

    public function test_attaching_a_card_to_a_customer_is_recorded(): void
    {
        $card = app(CardService::class)->append($this->todo, 'Scope the Bluepeak booking widget');

        $this->reader()->assignToCustomer($card->id, $this->customer->id);

        $this->assertDatabaseHas('activity_log', ['event' => 'card.customer_set']);
    }

    /**
     * Deleting a customer must not delete their work — the foreign key is
     * `nullOnDelete` for exactly this reason.
     */
    public function test_deleting_a_customer_leaves_their_cards_on_the_board(): void
    {
        $card = app(CardService::class)->append($this->todo, 'Rewrite portfolio landing copy');
        $this->reader()->assignToCustomer($card->id, $this->customer->id);

        $this->customer->forceDelete();

        $this->assertDatabaseHas('cards', ['id' => $card->id, 'customer_id' => null]);
        $this->assertSame(1, Card::query()->active()->count());
    }

    /* Both ways through Core's generic link table ---------------------------- */

    public function test_a_card_and_a_company_can_be_linked_and_read_back_from_either_end(): void
    {
        $card = app(CardService::class)->append($this->todo, 'Build the Acme Studio mail module');
        $company = Company::query()->firstOrFail();

        $card->linkTo($company, 'references');

        $this->assertTrue($card->isLinkedTo($company));
        $this->assertTrue($company->isLinkedTo($card));
        $this->assertSame($card->id, $company->linked('card')->first()->id);
        $this->assertSame($company->id, $card->linked('company')->first()->id);
    }

    public function test_linking_the_same_pair_twice_leaves_one_row(): void
    {
        $card = app(CardService::class)->append($this->todo, 'Q3 expense reconciliation');
        $company = Company::query()->firstOrFail();

        $card->linkTo($company, 'references');
        $card->linkTo($company, 'references');

        $this->assertDatabaseCount('links', 1);
    }

    /* The customer's own page ---------------------------------------------------- */

    public function test_the_customers_page_lists_the_cards_that_point_at_them(): void
    {
        $card = app(CardService::class)->append($this->todo, 'Send the Northwind retainer proposal');
        $this->reader()->assignToCustomer($card->id, $this->customer->id);

        $this->get('/accounting/clients/'.$this->customer->id.'?tab=projects')
            ->assertOk()
            ->assertSee('Send the Northwind retainer proposal')
            ->assertSee('Client Work')
            ->assertSee('To Do');
    }

    public function test_a_customer_with_no_cards_gets_a_way_to_make_one_rather_than_a_blank(): void
    {
        $this->get('/accounting/clients/'.$this->customer->id.'?tab=projects')
            ->assertOk()
            ->assertSee('No cards point at this client yet')
            ->assertSee('Open the boards');
    }

    /* The seeders --------------------------------------------------------------- */

    public function test_seeding_twice_leaves_the_database_as_the_first_run_did(): void
    {
        $this->seed(DatabaseSeeder::class);

        $first = [
            'companies' => Company::query()->count(),
            'customers' => Customer::query()->count(),
            'cards' => Card::query()->count(),
        ];

        $this->seed(DatabaseSeeder::class);

        $second = [
            'companies' => Company::query()->count(),
            'customers' => Customer::query()->count(),
            'cards' => Card::query()->count(),
        ];

        $this->assertSame($first, $second, 'The seeders are not idempotent, so every deploy duplicates the client list.');
        $this->assertGreaterThan(0, $first['companies']);
        $this->assertGreaterThan(0, $first['cards']);
    }
}
