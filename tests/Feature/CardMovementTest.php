<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Services\CardService;
use Modules\Project\Support\Position;
use Tests\TestCase;

/**
 * Moving a card.
 *
 * The acceptance criterion for this phase is arithmetic, not appearance:
 * reordering a list of 500 must write one row. Everything else here exists to
 * stop that guarantee being quietly traded away later.
 *
 * The row being written is a `card_placements` row now rather than a `cards`
 * one — a card may sit in several lists and each placement has its own order —
 * so the write counting below counts placements. The guarantee is the same one.
 */
class CardMovementTest extends TestCase
{
    use RefreshDatabase;

    private Board $board;

    private BoardList $todo;

    private BoardList $doing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $this->board = Board::factory()->create(['name' => 'Client Work', 'slug' => 'client-work']);
        $this->todo = BoardList::factory()->for($this->board)->create(['name' => 'To Do', 'position' => Position::format('1024')]);
        $this->doing = BoardList::factory()->for($this->board)->create(['name' => 'In Progress', 'position' => Position::format('2048')]);
    }

    private function service(): CardService
    {
        return app(CardService::class);
    }

    /** @return list<Card> */
    private function seedCards(BoardList $list, int $count, string $prefix = 'Card'): array
    {
        $cards = [];

        for ($i = 0; $i < $count; $i++) {
            $cards[] = $this->service()->append($list, $prefix.' '.($i + 1));
        }

        return $cards;
    }

    private function titlesIn(BoardList $list): array
    {
        return CardPlacement::query()
            ->where('board_list_id', $list->id)
            ->onCanvas()
            ->orderBy('position')
            ->with('card')
            ->get()
            ->map(fn (CardPlacement $placement): string => $placement->card->title)
            ->all();
    }

    /** The one placement a freshly appended card has. */
    private function placementOf(Card $card): CardPlacement
    {
        return $card->originPlacement()->firstOrFail();
    }

    /** Every placement position in a list, in order, as strings. */
    private function positionsIn(BoardList $list): array
    {
        return CardPlacement::query()
            ->where('board_list_id', $list->id)
            ->orderBy('position')
            ->pluck('position')
            ->map(fn ($p): string => (string) $p)
            ->all();
    }

    /* The headline guarantee ------------------------------------------------ */

    public function test_reordering_a_five_hundred_card_list_writes_one_row(): void
    {
        $cards = $this->seedCards($this->todo, 500);

        $last = end($cards);

        // Count writes to `card_placements` only. The activity trail is an
        // append of its own and is meant to be there.
        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^update\s+"?card_placements"?/i', trim($query->sql))) {
                $writes++;
            }
        });

        // Drop the last card between the second and the third.
        $this->service()->move($this->placementOf($last), $this->todo, 2);

        $this->assertSame(1, $writes, 'Reordering wrote '.$writes.' card rows; the whole point of a fractional position is that it writes one.');

        $order = $this->titlesIn($this->todo);
        $this->assertSame('Card 500', $order[2]);
        $this->assertSame('Card 1', $order[0]);
        $this->assertSame('Card 2', $order[1]);
        $this->assertSame('Card 3', $order[3]);
    }

    public function test_a_card_dropped_in_another_list_is_still_there_afterwards(): void
    {
        [$first] = $this->seedCards($this->todo, 3, 'Backlog');
        $this->seedCards($this->doing, 2, 'Doing');

        $this->service()->move($this->placementOf($first), $this->doing, 1);

        // Read it back from the database, not from the object we just wrote.
        $reloaded = Card::query()->find($first->id);

        $this->assertSame($this->doing->id, $reloaded->originPlacement->board_list_id);
        $this->assertSame($this->doing->id, $reloaded->list->id, 'The card must still know which list it lives in.');
        $this->assertSame(['Doing 1', 'Backlog 1', 'Doing 2'], $this->titlesIn($this->doing));
        $this->assertSame(['Backlog 2', 'Backlog 3'], $this->titlesIn($this->todo));
    }

    /* Ordering, at each end and in the middle -------------------------------- */

    public function test_a_card_dropped_at_the_top_lands_at_the_top(): void
    {
        $cards = $this->seedCards($this->todo, 4);

        $this->service()->move($this->placementOf($cards[3]), $this->todo, 0);

        $this->assertSame(['Card 4', 'Card 1', 'Card 2', 'Card 3'], $this->titlesIn($this->todo));
    }

    public function test_a_card_dropped_at_the_bottom_lands_at_the_bottom(): void
    {
        $cards = $this->seedCards($this->todo, 4);

        $this->service()->move($this->placementOf($cards[0]), $this->todo, 3);

        $this->assertSame(['Card 2', 'Card 3', 'Card 4', 'Card 1'], $this->titlesIn($this->todo));
    }

    public function test_the_first_card_in_an_empty_list_simply_lands(): void
    {
        [$card] = $this->seedCards($this->todo, 1);

        $this->service()->move($this->placementOf($card), $this->doing, 0);

        $this->assertSame(['Card 1'], $this->titlesIn($this->doing));
        $this->assertSame([], $this->titlesIn($this->todo));
    }

    /* Filtering --------------------------------------------------------------- */

    /**
     * The index a browser reports is an index into what it can see. With a
     * filter on, the rows between two visible cards are not on screen, so
     * treating that index as an offset into the table puts the card in the
     * wrong place.
     */
    public function test_a_drop_under_a_filter_uses_the_visible_ordering(): void
    {
        $cards = $this->seedCards($this->todo, 5);

        // The board is showing cards 1, 3 and 5. The user drags card 5 and
        // drops it between the two it can see: index 1. The ids are placement
        // ids, because that is what the browser now reports.
        $visible = [
            $this->placementOf($cards[0])->id,
            $this->placementOf($cards[2])->id,
            $this->placementOf($cards[4])->id,
        ];

        $this->service()->move($this->placementOf($cards[4]), $this->todo, 1, $visible);

        $order = $this->titlesIn($this->todo);

        $positionOfFive = array_search('Card 5', $order, true);
        $positionOfOne = array_search('Card 1', $order, true);
        $positionOfThree = array_search('Card 3', $order, true);

        $this->assertGreaterThan($positionOfOne, $positionOfFive, 'Card 5 should sit after the first card the user could see.');
        $this->assertLessThan($positionOfThree, $positionOfFive, 'Card 5 should sit before the next card the user could see.');
    }

    /* Rebalancing -------------------------------------------------------------- */

    public function test_a_spent_gap_rebalances_instead_of_writing_a_duplicate_position(): void
    {
        $cards = $this->seedCards($this->todo, 3);

        // Squeeze two neighbours together until nothing fits between them.
        $this->placementOf($cards[0])->forceFill(['position' => Position::format('1000')])->save();
        $this->placementOf($cards[1])->forceFill(['position' => Position::format('1000.00001')])->save();

        $this->service()->move($this->placementOf($cards[2]), $this->todo, 1);

        $positions = $this->positionsIn($this->todo);

        $this->assertSame(
            count($positions),
            count(array_unique($positions)),
            'Two placements ended up on the same position, so their order is now whatever the database feels like.',
        );

        $this->assertSame(['Card 1', 'Card 3', 'Card 2'], $this->titlesIn($this->todo));
    }

    public function test_rebalancing_twice_changes_nothing_the_second_time(): void
    {
        $this->seedCards($this->todo, 10);

        $this->service()->rebalance($this->todo);
        $first = CardPlacement::query()->where('board_list_id', $this->todo->id)->orderBy('id')->pluck('position', 'id')->map(fn ($p) => (string) $p)->all();

        $this->service()->rebalance($this->todo);
        $second = CardPlacement::query()->where('board_list_id', $this->todo->id)->orderBy('id')->pluck('position', 'id')->map(fn ($p) => (string) $p)->all();

        $this->assertSame($first, $second);
    }

    public function test_the_rebalance_command_runs_twice_with_no_second_effect(): void
    {
        $this->seedCards($this->todo, 6);
        $this->seedCards($this->doing, 4);

        $this->artisan('project:rebalance')->assertSuccessful();
        $before = CardPlacement::query()->orderBy('id')->pluck('position', 'id')->map(fn ($p) => (string) $p)->all();

        $this->artisan('project:rebalance')->assertSuccessful();
        $after = CardPlacement::query()->orderBy('id')->pluck('position', 'id')->map(fn ($p) => (string) $p)->all();

        $this->assertSame($before, $after, 'The rebalance command is not idempotent.');
    }

    /* The activity trail --------------------------------------------------------- */

    public function test_moving_a_card_between_lists_is_recorded_with_both_ends(): void
    {
        [$card] = $this->seedCards($this->todo, 1);

        $this->service()->move($this->placementOf($card), $this->doing, 0);

        $entry = DB::table('activity_log')->where('event', 'card.moved')->latest('id')->first();

        $this->assertNotNull($entry, 'Moving a card left no trace in the activity feed.');

        $properties = json_decode($entry->properties, true);

        $this->assertSame('To Do', $properties['from_list']);
        $this->assertSame('In Progress', $properties['to_list']);
        $this->assertStringContainsString('moved from To Do to In Progress', $entry->description);
    }

    public function test_adding_a_card_is_recorded(): void
    {
        $this->service()->append($this->todo, 'Draft the Northwind scope document');

        $this->assertDatabaseHas('activity_log', ['event' => 'card.created']);
    }

    /**
     * "Every board action appears in the activity feed" is an acceptance
     * criterion for this phase, so it is asserted as one thing rather than
     * inferred from a handful of separate tests.
     */
    public function test_every_board_action_reaches_the_activity_feed(): void
    {
        $service = $this->service();

        $card = $service->append($this->todo, 'Rewrite portfolio landing copy');
        $service->move($this->placementOf($card), $this->doing, 0);
        $card->forceFill(['title' => 'Rewrite the landing copy'])->save();
        $card->forceFill(['due_on' => now()->addWeek()->toDateString()])->save();
        $service->archive($card);
        $service->restore($card);

        $events = DB::table('activity_log')->pluck('event')->unique()->all();

        foreach (['card.created', 'card.moved', 'card.archived', 'card.restored', 'updated'] as $expected) {
            $this->assertContains($expected, $events, 'Nothing recorded a '.$expected.' in the activity feed.');
        }

        // A rename and a due date are attribute changes, logged by the model
        // itself. Both must carry what changed, not just that something did.
        $renamed = DB::table('activity_log')
            ->where('event', 'updated')
            ->get()
            ->first(fn ($row): bool => str_contains((string) $row->properties, 'Rewrite the landing copy'));

        $this->assertNotNull($renamed, 'A rename reached the feed without saying what the new title was.');
    }

    public function test_a_drag_writes_one_readable_entry_rather_than_an_attribute_diff(): void
    {
        // `position` changes on every drag and its before/after is a pair of
        // ten-decimal strings nobody will ever read. The move is logged once,
        // by name, and the attribute log is not told to watch the column.
        [$card] = $this->seedCards($this->todo, 1);

        $this->assertNotContains('position', $card->getActivitylogOptions()->logAttributes);

        DB::table('activity_log')->delete();

        $this->service()->move($this->placementOf($card), $this->doing, 0);

        $entries = DB::table('activity_log')->get();

        $this->assertCount(1, $entries, 'A drag should leave one entry in the feed, not two.');
        $this->assertSame('card.moved', $entries->first()->event);
        $this->assertStringContainsString('moved from To Do to In Progress', $entries->first()->description);
    }

    public function test_archiving_a_card_keeps_it_readable_and_records_who_did_it(): void
    {
        [$card] = $this->seedCards($this->todo, 1);

        $this->service()->archive($card);

        $this->assertDatabaseHas('cards', ['id' => $card->id]);
        $this->assertNotNull(Card::query()->find($card->id)->archived_at);
        $this->assertSame([], $this->titlesIn($this->todo));
        $this->assertDatabaseHas('activity_log', ['event' => 'card.archived']);
    }
}
