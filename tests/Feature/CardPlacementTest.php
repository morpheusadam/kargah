<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Services\CardService;
use Modules\Project\Services\PlacementConflict;
use Modules\Project\Support\Position;
use Tests\TestCase;

/**
 * Mirror cards: one card, shown and edited from more than one list.
 *
 * The whole feature rests on two invariants, and most of what follows is one or
 * other of them stated as an assertion:
 *
 * 1. **A card has exactly one origin placement, always.** That is where it
 *    lives. It can gain and lose mirrors freely; it can never lose its origin
 *    while it exists, and it can never be left placed nowhere.
 * 2. **A card sits in a list once or not at all.** The unique index says so;
 *    `CardService` refuses by name so a person gets a sentence rather than a
 *    constraint violation.
 */
class CardPlacementTest extends TestCase
{
    use RefreshDatabase;

    private Board $board;

    private Board $other;

    private BoardList $backlog;

    private BoardList $review;

    private BoardList $leads;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['name' => 'Nima Fazlipour']));

        $this->board = Board::factory()->create(['name' => 'Client Work', 'slug' => 'client-work', 'position' => 1]);
        $this->other = Board::factory()->create(['name' => 'Outreach', 'slug' => 'outreach', 'position' => 2]);

        $this->backlog = BoardList::factory()->for($this->board)->create(['name' => 'Backlog', 'position' => Position::format('1024')]);
        $this->review = BoardList::factory()->for($this->board)->create(['name' => 'Review', 'position' => Position::format('2048')]);
        $this->leads = BoardList::factory()->for($this->other)->create(['name' => 'Leads', 'position' => Position::format('1024')]);
    }

    private function service(): CardService
    {
        return app(CardService::class);
    }

    private function originOf(Card $card): CardPlacement
    {
        return $card->originPlacement()->firstOrFail();
    }

    /* One card lives in exactly one place ---------------------------------- */

    public function test_a_card_created_through_the_service_has_one_placement_and_it_is_the_origin(): void
    {
        $card = $this->service()->append($this->backlog, 'Rewrite portfolio landing copy');

        $placements = CardPlacement::query()->where('card_id', $card->id)->get();

        $this->assertCount(1, $placements);
        $this->assertTrue($placements->first()->isOrigin());
        $this->assertSame($this->backlog->id, $placements->first()->board_list_id);
        $this->assertSame($this->backlog->id, $card->fresh()->list->id);
    }

    public function test_the_factory_also_leaves_every_card_with_exactly_one_origin(): void
    {
        // Other modules create cards straight from the factory, and a card with
        // no placement is on no board and in no archive.
        $bare = Card::factory()->create();
        $placed = Card::factory()->inList($this->review)->create();

        $this->assertSame(1, $bare->placements()->count());
        $this->assertTrue($this->originOf($bare)->isOrigin());

        $this->assertSame(1, $placed->placements()->count());
        $this->assertSame($this->review->id, $this->originOf($placed)->board_list_id);
    }

    /* Mirroring -------------------------------------------------------------- */

    public function test_mirroring_adds_a_second_placement_and_leaves_the_origin_alone(): void
    {
        $card = $this->service()->append($this->backlog, 'Build the Acme Studio mail module');
        $origin = $this->originOf($card);

        $mirror = $this->service()->mirror($card, $this->leads);

        $this->assertNotSame($origin->id, $mirror->id);
        $this->assertFalse($mirror->isOrigin());
        $this->assertSame($this->leads->id, $mirror->board_list_id);

        // The card is now on two lists.
        $this->assertEqualsCanonicalizing(
            [$this->backlog->id, $this->leads->id],
            $card->fresh()->placements->pluck('board_list_id')->all(),
        );

        // The origin did not move, and the card still lives where it lived.
        $this->assertSame($this->backlog->id, $this->originOf($card->fresh())->board_list_id);
        $this->assertSame($this->backlog->id, $card->fresh()->list->id);
        $this->assertSame(1, $card->fresh()->mirrorPlacements()->count());
    }

    public function test_mirroring_twice_into_the_same_list_writes_one_row_and_returns_the_same_placement(): void
    {
        $card = $this->service()->append($this->backlog, 'Send the Northwind retainer proposal');

        $first = $this->service()->mirror($card, $this->leads);

        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^insert\s+into\s+"?card_placements"?/i', trim($query->sql))) {
                $writes++;
            }
        });

        $second = $this->service()->mirror($card, $this->leads);

        $this->assertSame(0, $writes, 'Mirroring a card into a list it is already in must write nothing.');
        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $card->fresh()->placements()->count());
    }

    /* Moving a mirror and moving the origin ---------------------------------- */

    public function test_moving_a_mirror_moves_only_that_mirror(): void
    {
        $second = BoardList::factory()->for($this->other)->create(['name' => 'Won', 'position' => Position::format('2048')]);

        $card = $this->service()->append($this->backlog, 'Chase the Harbour & Finch deposit');
        $mirror = $this->service()->mirror($card, $this->leads);
        $originBefore = $this->originOf($card);

        $this->service()->move($mirror, $second, 0);

        $this->assertSame($second->id, $mirror->fresh()->board_list_id);

        $origin = $this->originOf($card->fresh());
        $this->assertSame($this->backlog->id, $origin->board_list_id);
        $this->assertSame((string) $originBefore->position, (string) $origin->position);
        $this->assertSame($this->backlog->id, $card->fresh()->list->id);
    }

    public function test_moving_the_origin_leaves_the_mirror_where_it_was(): void
    {
        $card = $this->service()->append($this->backlog, 'Fix invoice PDF margins');
        $mirror = $this->service()->mirror($card, $this->leads);
        $mirrorBefore = (string) $mirror->position;

        $this->service()->move($this->originOf($card), $this->review, 0);

        $this->assertSame($this->review->id, $this->originOf($card->fresh())->board_list_id);
        $this->assertSame($this->leads->id, $mirror->fresh()->board_list_id);
        $this->assertSame($mirrorBefore, (string) $mirror->fresh()->position);
    }

    public function test_moving_a_placement_into_a_list_the_card_is_already_in_is_refused_by_name(): void
    {
        $card = $this->service()->append($this->backlog, 'Q3 expense reconciliation');
        $this->service()->mirror($card, $this->review);

        $origin = $this->originOf($card);

        // A database constraint violation is not an error message. The service
        // refuses first, and nothing is written on the way out.
        $this->expectException(PlacementConflict::class);

        try {
            $this->service()->move($origin, $this->review, 0);
        } finally {
            $this->assertSame($this->backlog->id, $origin->fresh()->board_list_id);
            $this->assertSame(2, $card->fresh()->placements()->count());
        }
    }

    public function test_the_board_reports_a_refused_move_rather_than_throwing(): void
    {
        $card = $this->service()->append($this->backlog, 'Renew the wildcard certificate');
        $this->service()->mirror($card, $this->review);

        Livewire::test('project::boards')
            ->call('moveCard', $this->originOf($card)->id, (string) $this->review->id, 0)
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'error');

        $this->assertSame($this->backlog->id, $this->originOf($card->fresh())->board_list_id);
    }

    /* Unmirroring ------------------------------------------------------------ */

    public function test_unmirroring_removes_the_mirror_and_nothing_else(): void
    {
        $card = $this->service()->append($this->backlog, 'Migrate Acme Studio off shared hosting');
        $mirror = $this->service()->mirror($card, $this->leads);

        $this->assertTrue($this->service()->unmirror($mirror));

        $this->assertDatabaseMissing('card_placements', ['id' => $mirror->id]);
        $this->assertNotNull(Card::query()->find($card->id));
        $this->assertSame(1, $card->fresh()->placements()->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'card.unmirrored']);
    }

    public function test_unmirroring_the_origin_is_refused_and_deletes_nothing(): void
    {
        $card = $this->service()->append($this->backlog, 'Draft the Q3 expense summary');
        $origin = $this->originOf($card);

        $this->assertFalse($this->service()->unmirror($origin));

        $this->assertDatabaseHas('card_placements', ['id' => $origin->id]);
        $this->assertSame(1, $card->fresh()->placements()->count());
    }

    public function test_the_drawer_refuses_to_remove_the_placement_the_card_lives_on(): void
    {
        $card = $this->service()->append($this->backlog, 'Reconcile the July card statement');

        Livewire::test('project::card-detail')
            ->call('openCard', $card->id)
            ->call('removeMirror', $this->originOf($card)->id)
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'error');

        $this->assertSame(1, $card->fresh()->placements()->count());
    }

    public function test_the_drawer_mirrors_a_card_onto_another_board_and_removes_it_again(): void
    {
        $card = $this->service()->append($this->backlog, 'Scope the Bluepeak booking widget');

        $drawer = Livewire::test('project::card-detail')
            ->call('openCard', $card->id)
            ->call('toggleMirrorPopover')
            ->set('mirrorBoard', (string) $this->other->id)
            ->set('mirrorList', (string) $this->leads->id)
            ->call('mirrorCard')
            ->assertSet('mirrorPopoverOpen', false)
            ->assertDispatched('card-changed');

        $mirror = $card->fresh()->mirrorPlacements()->firstOrFail();

        $this->assertSame($this->leads->id, $mirror->board_list_id);

        $drawer->call('removeMirror', $mirror->id)->assertDispatched('card-changed');

        $this->assertSame(1, $card->fresh()->placements()->count());
    }

    /** Opening the picker changes nothing, so it says nothing. */
    public function test_opening_the_mirror_picker_is_silent(): void
    {
        $card = $this->service()->append($this->backlog, 'Write the hand-over notes for Orbit Studio');

        Livewire::test('project::card-detail')
            ->call('openCard', $card->id)
            ->call('toggleMirrorPopover')
            ->assertSet('mirrorPopoverOpen', true)
            ->assertNotDispatched('toast');
    }

    /* The archived mirror ------------------------------------------------------ */

    public function test_an_archived_card_leaves_its_own_list_but_stays_on_its_mirrors(): void
    {
        $card = $this->service()->append($this->backlog, 'Collect testimonials from past clients');
        $this->service()->mirror($card, $this->review);

        $this->service()->archive($card);

        $component = Livewire::test('project::boards');

        $component->assertViewHas('lists', function ($lists): bool {
            $byName = $lists->keyBy(fn (array $entry): string => $entry['model']->name);

            return $byName['Backlog']['placements']->isEmpty()
                && $byName['Review']['placements']->count() === 1;
        });

        // Rendered, marked, and not draggable — the selector Sortable is given
        // excludes anything carrying data-archived.
        $html = $this->get('/projects')->assertOk()->getContent();

        $this->assertStringContainsString('data-archived="1"', $html);
        $this->assertStringContainsString('Archived', $html);
    }

    public function test_restoring_the_card_brings_it_back_everywhere(): void
    {
        $card = $this->service()->append($this->backlog, 'Register the kargah.dev domain');
        $this->service()->mirror($card, $this->review);
        $this->service()->archive($card);

        $this->service()->restore($card);

        Livewire::test('project::boards')->assertViewHas('lists', function ($lists): bool {
            $byName = $lists->keyBy(fn (array $entry): string => $entry['model']->name);

            return $byName['Backlog']['placements']->count() === 1
                && $byName['Review']['placements']->count() === 1;
        });

        $this->assertStringNotContainsString('data-archived="1"', $this->get('/projects')->getContent());
    }

    /* Cascades ------------------------------------------------------------------ */

    public function test_deleting_a_card_takes_its_placements_with_it(): void
    {
        $card = $this->service()->append($this->backlog, 'Rewrite portfolio landing copy');
        $this->service()->mirror($card, $this->leads);

        $card->forceDelete();

        $this->assertDatabaseMissing('card_placements', ['card_id' => $card->id]);
    }

    /**
     * The case that had to be decided.
     *
     * A card whose only placement is in a deleted list would be on no board and
     * absent from the archive, which reads a card through the list it lives in
     * — invisible and unreachable, which is worse than either outcome. So the
     * card is soft-deleted with the list, exactly as the archive's own delete
     * button does it. A card merely mirrored into the list loses the mirror and
     * keeps living where it lives.
     */
    public function test_deleting_a_list_takes_the_cards_that_live_in_it_and_no_others(): void
    {
        $livesHere = $this->service()->append($this->backlog, 'Fix invoice PDF margins');
        $livesElsewhere = $this->service()->append($this->review, 'Send the Northwind retainer proposal');
        $mirror = $this->service()->mirror($livesElsewhere, $this->backlog);

        $this->backlog->delete();

        $this->assertSoftDeleted('cards', ['id' => $livesHere->id]);
        $this->assertDatabaseMissing('card_placements', ['board_list_id' => $this->backlog->id]);

        // The mirrored card is untouched, and still lives where it lived.
        $this->assertNotNull(Card::query()->find($livesElsewhere->id));
        $this->assertDatabaseMissing('card_placements', ['id' => $mirror->id]);
        $this->assertSame(1, $livesElsewhere->fresh()->placements()->count());
        $this->assertSame($this->review->id, $livesElsewhere->fresh()->list->id);
    }

    public function test_no_card_can_be_left_placed_nowhere(): void
    {
        $card = $this->service()->append($this->backlog, 'Draft the Q3 expense summary');

        $this->backlog->forceDelete();

        $this->assertSame(0, Card::query()->whereDoesntHave('placements')->count());
        $this->assertSoftDeleted('cards', ['id' => $card->id]);
    }

    /* Ordering ------------------------------------------------------------------- */

    public function test_rebalancing_twice_leaves_identical_positions(): void
    {
        $card = $this->service()->append($this->backlog, 'Rewrite portfolio landing copy');
        $this->service()->mirror($card, $this->review);

        foreach (range(1, 8) as $i) {
            $this->service()->append($this->review, 'Card '.$i);
        }

        $this->service()->rebalance($this->review);
        $first = CardPlacement::query()->where('board_list_id', $this->review->id)
            ->orderBy('id')->pluck('position', 'id')->map(fn ($p): string => (string) $p)->all();

        $this->service()->rebalance($this->review);
        $second = CardPlacement::query()->where('board_list_id', $this->review->id)
            ->orderBy('id')->pluck('position', 'id')->map(fn ($p): string => (string) $p)->all();

        $this->assertSame($first, $second, 'Rebalancing is not idempotent.');
    }

    public function test_reordering_a_five_hundred_placement_list_writes_one_row(): void
    {
        // The guarantee the fractional column exists for, restated for
        // placements and with a mirror in the list so the count is not
        // accidentally passing on a list of origins only.
        for ($i = 1; $i <= 499; $i++) {
            $this->service()->append($this->backlog, 'Card '.$i);
        }

        $mirrored = $this->service()->append($this->review, 'The mirrored one');
        $mirror = $this->service()->mirror($mirrored, $this->backlog);

        $this->assertSame(500, CardPlacement::query()->where('board_list_id', $this->backlog->id)->count());

        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^update\s+"?card_placements"?/i', trim($query->sql))) {
                $writes++;
            }
        });

        $this->service()->move($mirror, $this->backlog, 2);

        $this->assertSame(1, $writes, 'Reordering wrote '.$writes.' rows; the point of a fractional position is that it writes one.');
    }

    /* The relation other modules read through ------------------------------------ */

    public function test_the_list_relation_eager_loads_rather_than_asking_once_per_card(): void
    {
        foreach (range(1, 20) as $i) {
            $this->service()->append($this->backlog, 'Card '.$i);
        }

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $cards = Card::query()->with('list.board')->get();

        $this->assertCount(20, $cards);
        $this->assertSame('Backlog', $cards->first()->list->name);
        $this->assertSame('Client Work', $cards->first()->list->board->name);

        // Cards, their lists, the lists' boards. Not one query per card.
        $this->assertLessThanOrEqual(
            4,
            $queries,
            'Card::list() stopped eager-loading: '.$queries.' queries for twenty cards.',
        );
    }

    /* The canvas ------------------------------------------------------------------- */

    public function test_the_canvas_is_still_one_island_and_is_named_after_a_mirror_changes_it(): void
    {
        $source = file_get_contents(base_path('Modules/Project/resources/views/components/⚡boards.blade.php'));

        $this->assertSame(1, substr_count($source, '@island('), 'A second island in this file would share a token with the first.');

        $card = $this->service()->append($this->backlog, 'Rewrite portfolio landing copy');

        // The drawer mirrors, the board redraws. An island nobody names keeps
        // whatever the DOM already had, so the new mirror would be computed,
        // sent and thrown away.
        $board = Livewire::test('project::boards')->call('cardChanged');

        $this->assertNotEmpty(
            $board->effects['islandFragments'] ?? [],
            'A mirror changed the board but the canvas was never named.',
        );

        Livewire::test('project::card-detail')
            ->call('openCard', $card->id)
            ->set('mirrorBoard', (string) $this->board->id)
            ->set('mirrorList', (string) $this->review->id)
            ->call('mirrorCard')
            ->assertDispatched('card-changed');
    }

    /* The migration ------------------------------------------------------------------ */

    /**
     * Down and back up, with the card where it started.
     *
     * The migration is run directly rather than through `migrate:rollback`,
     * which rolls back by batch and would take every other module's schema with
     * it.
     */
    public function test_the_migration_rolls_back_and_forward_leaving_the_card_where_it_was(): void
    {
        $card = $this->service()->append($this->review, 'Build the Acme Studio mail module');
        $position = (string) $this->originOf($card)->position;

        $migration = require base_path('Modules/Project/database/migrations/2026_08_02_000001_create_card_placements_table.php');

        $migration->down();

        $row = DB::table('cards')->where('id', $card->id)->first();

        $this->assertSame($this->review->id, (int) $row->board_list_id);
        $this->assertSame($position, Position::format((string) $row->position));
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('card_placements'));

        $migration->up();

        $placements = DB::table('card_placements')->where('card_id', $card->id)->get();

        $this->assertCount(1, $placements);
        $this->assertSame($this->review->id, (int) $placements->first()->board_list_id);
        $this->assertSame($position, Position::format((string) $placements->first()->position));
        $this->assertTrue((bool) $placements->first()->is_origin);
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('cards', 'board_list_id'));
    }
}
