<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Customer;
use Modules\Project\Contracts\BoardReader;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Services\CardService;
use Modules\Project\Support\Position;
use Tests\TestCase;

/**
 * `BoardReader` over the real tables.
 *
 * The one fact every test here is ultimately in service of: a card mirrored
 * onto a second list is still one card, and a dashboard figure that counts it
 * twice overstates how much work is outstanding.
 */
class BoardReaderTest extends TestCase
{
    use RefreshDatabase;

    private BoardReader $reader;

    private CardService $cards;

    private Board $board;

    private BoardList $backlog;

    private BoardList $todo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $this->reader = app(BoardReader::class);
        $this->cards = app(CardService::class);

        $this->board = Board::factory()->create(['name' => 'Client Work', 'slug' => 'client-work', 'colour' => 'primary']);
        $this->backlog = BoardList::factory()->for($this->board)->create(['name' => 'Backlog', 'position' => Position::format('1024')]);
        $this->todo = BoardList::factory()->for($this->board)->create(['name' => 'To Do', 'position' => Position::format('2048')]);
    }

    public function test_boards_returns_arrays_never_models(): void
    {
        $boards = $this->reader->boards();

        $this->assertIsArray($boards);
        $this->assertIsArray($boards[0]);
        $this->assertSame($this->board->slug, $boards[0]['slug']);
        $this->assertSame($this->board->name, $boards[0]['name']);
        $this->assertFalse($boards[0]['is_archived']);
    }

    public function test_boards_excludes_archived_by_default(): void
    {
        Board::factory()->create(['slug' => 'gone', 'archived_at' => now()]);

        $slugs = array_column($this->reader->boards(), 'slug');
        $this->assertNotContains('gone', $slugs);

        $slugs = array_column($this->reader->boards(includeArchived: true), 'slug');
        $this->assertContains('gone', $slugs);
    }

    public function test_find_board_returns_null_for_an_unknown_slug(): void
    {
        $this->assertNull($this->reader->findBoard('does-not-exist'));
    }

    public function test_find_board_carries_the_description(): void
    {
        $this->board->forceFill(['description' => 'Everything billable.'])->save();

        $found = $this->reader->findBoard('client-work');

        $this->assertIsArray($found);
        $this->assertSame('Everything billable.', $found['description']);
    }

    public function test_lists_for_board_is_empty_for_an_unknown_slug(): void
    {
        $this->assertSame([], $this->reader->listsForBoard('nowhere'));
    }

    public function test_lists_for_board_is_ordered_by_position(): void
    {
        $lists = $this->reader->listsForBoard('client-work');

        $this->assertCount(2, $lists);
        $this->assertSame('Backlog', $lists[0]['name']);
        $this->assertSame('To Do', $lists[1]['name']);
        $this->assertIsString($lists[0]['position']);
    }

    public function test_cards_for_list_is_in_placement_order(): void
    {
        $this->cards->append($this->backlog, 'First card');
        $this->cards->append($this->backlog, 'Second card');

        $cards = $this->reader->cardsForList($this->backlog->id);

        $this->assertCount(2, $cards);
        $this->assertSame('First card', $cards[0]['title']);
        $this->assertSame('Second card', $cards[1]['title']);
        $this->assertTrue($cards[0]['is_origin']);
    }

    public function test_a_mirrored_card_appears_on_both_lists_it_is_mirrored_into(): void
    {
        $card = $this->cards->append($this->backlog, 'Shared card');
        $this->cards->mirror($card, $this->todo);

        $onOrigin = $this->reader->cardsForList($this->backlog->id);
        $onMirror = $this->reader->cardsForList($this->todo->id);

        $this->assertCount(1, $onOrigin);
        $this->assertCount(1, $onMirror);
        $this->assertTrue($onOrigin[0]['is_origin']);
        $this->assertFalse($onMirror[0]['is_origin']);
    }

    public function test_find_card_returns_null_when_it_does_not_exist(): void
    {
        $this->assertNull($this->reader->findCard(999_999));
    }

    public function test_find_card_names_the_lists_it_is_mirrored_onto(): void
    {
        $card = $this->cards->append($this->backlog, 'Shared card');
        $this->cards->mirror($card, $this->todo);

        $found = $this->reader->findCard($card->id);

        $this->assertIsArray($found);
        $this->assertSame('Backlog', $found['list']);
        $this->assertSame(['To Do'], $found['mirrored_onto']);
    }

    public function test_find_card_carries_its_customer(): void
    {
        $customer = Customer::factory()->create(['name' => 'Northwind']);
        $card = $this->cards->append($this->backlog, 'Billable card', ['customer_id' => $customer->id]);

        $found = $this->reader->findCard($card->id);

        $this->assertSame(['id' => $customer->id, 'name' => 'Northwind'], $found['customer']);
    }

    /**
     * A card mirrored onto a second list must count once, not twice — the
     * exact failure mode the task warns overstates outstanding work.
     */
    public function test_a_mirrored_card_is_counted_once_across_boards(): void
    {
        $card = $this->cards->append($this->backlog, 'Overdue and mirrored');
        $card->forceFill(['due_on' => now()->subDay()->toDateString()])->save();
        $this->cards->mirror($card, $this->todo);

        $this->assertSame(1, $this->reader->countOverdue());

        $overdue = $this->reader->cardsOverdue();
        $this->assertCount(1, $overdue);
        $this->assertSame($card->id, $overdue[0]['id']);
    }

    public function test_cards_due_soon_excludes_overdue_and_complete_and_archived(): void
    {
        $dueToday = $this->cards->append($this->backlog, 'Due today');
        $dueToday->forceFill(['due_on' => now()->toDateString()])->save();

        $dueLater = $this->cards->append($this->backlog, 'Due in ten days');
        $dueLater->forceFill(['due_on' => now()->addDays(10)->toDateString()])->save();

        $tooFar = $this->cards->append($this->backlog, 'Due in ninety days');
        $tooFar->forceFill(['due_on' => now()->addDays(90)->toDateString()])->save();

        $overdue = $this->cards->append($this->backlog, 'Already overdue');
        $overdue->forceFill(['due_on' => now()->subDay()->toDateString()])->save();

        $complete = $this->cards->append($this->backlog, 'Done already');
        $complete->forceFill(['due_on' => now()->addDay()->toDateString(), 'completed_at' => now()])->save();

        $archived = $this->cards->append($this->backlog, 'Archived card');
        $archived->forceFill(['due_on' => now()->addDay()->toDateString(), 'archived_at' => now()])->save();

        $dueSoon = $this->reader->cardsDueSoon(days: 30);
        $ids = array_column($dueSoon, 'id');

        $this->assertContains($dueToday->id, $ids);
        $this->assertContains($dueLater->id, $ids);
        $this->assertNotContains($tooFar->id, $ids);
        $this->assertNotContains($overdue->id, $ids);
        $this->assertNotContains($complete->id, $ids);
        $this->assertNotContains($archived->id, $ids);

        // Soonest first.
        $this->assertSame($dueToday->id, $dueSoon[0]['id']);

        $this->assertSame(2, $this->reader->countDueSoon(days: 30));
    }

    public function test_cards_due_soon_and_overdue_are_bounded_by_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $card = $this->cards->append($this->backlog, "Card {$i}");
            $card->forceFill(['due_on' => now()->addDay()->toDateString()])->save();
        }

        $this->assertCount(2, $this->reader->cardsDueSoon(days: 30, limit: 2));
        $this->assertSame(5, $this->reader->countDueSoon(days: 30));
    }

    public function test_the_dashboard_facing_methods_return_plain_arrays(): void
    {
        $card = $this->cards->append($this->backlog, 'Overdue card');
        $card->forceFill(['due_on' => now()->subDay()->toDateString()])->save();

        $overdue = $this->reader->cardsOverdue();

        $this->assertIsArray($overdue);
        $this->assertIsArray($overdue[0]);
        $this->assertArrayNotHasKey('placements', $overdue[0]);
    }
}
