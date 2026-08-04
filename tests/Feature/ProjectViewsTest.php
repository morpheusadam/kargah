<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Label;
use Modules\Project\Services\CardService;
use Tests\TestCase;

/**
 * The three read-mostly views over the boards Kargah already has: Table,
 * Calendar and Dashboard.
 *
 * Every test here decides, and then asserts, how a card mirrored onto two
 * lists is counted — the one question each of the three views had to answer
 * for itself. Table counts placements (a mirror is two rows, one per list);
 * Calendar and Dashboard's per-due-date/member/label figures count the card
 * once, through `Board::cards()`'s own deduplication, because a due date or a
 * label is a fact about the card and not about which list is showing it.
 * Dashboard's per-list bar is the one place a mirror is deliberately counted
 * twice, because "cards in this list" genuinely includes it.
 */
class ProjectViewsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Board $board;

    private BoardList $backlog;

    private BoardList $doing;

    private Label $bug;

    private CardService $cards;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Nima Fazlipour']);
        $this->actingAs($this->user);

        $this->board = Board::factory()->create(['name' => 'Client Work', 'slug' => 'client-work']);
        $this->backlog = BoardList::factory()->for($this->board)->create(['name' => 'Backlog', 'position' => 1]);
        $this->doing = BoardList::factory()->for($this->board)->create(['name' => 'Doing', 'position' => 2]);

        $this->bug = Label::factory()->for($this->board)->create(['name' => 'Bug', 'colour' => 'destructive']);

        $this->cards = app(CardService::class);
    }

    /* Rendering -------------------------------------------------------------- */

    public function test_the_table_view_renders_for_a_seeded_board(): void
    {
        $this->cards->append($this->backlog, 'Rewrite the portfolio landing copy');

        $this->get('/projects/table?board=client-work')
            ->assertOk()
            ->assertSee('Client Work')
            ->assertSee('Rewrite the portfolio landing copy');
    }

    public function test_the_table_view_renders_for_a_board_with_nothing_on_it(): void
    {
        $empty = Board::factory()->create(['name' => 'Empty Board', 'slug' => 'empty-board']);

        $this->get('/projects/table?board=empty-board')
            ->assertOk()
            ->assertSee('Nothing on this board yet');
    }

    public function test_the_calendar_view_renders_for_a_seeded_board(): void
    {
        $card = $this->cards->append($this->backlog, 'Send the retainer proposal');
        $card->forceFill(['due_on' => now()->addDays(3)->toDateString()])->save();

        $this->get('/projects/calendar?board=client-work')
            ->assertOk()
            ->assertSee('Client Work')
            ->assertSee('Send the retainer proposal');
    }

    public function test_the_calendar_view_renders_for_a_board_with_nothing_dated(): void
    {
        $this->cards->append($this->backlog, 'No date on this one');

        $this->get('/projects/calendar?board=client-work')
            ->assertOk()
            ->assertSee('Nothing dated on this board');
    }

    public function test_the_calendar_view_renders_for_an_empty_board(): void
    {
        $empty = Board::factory()->create(['name' => 'Empty Board', 'slug' => 'empty-board']);

        $this->get('/projects/calendar?board=empty-board')->assertOk();
    }

    public function test_the_dashboard_view_renders_for_a_seeded_board(): void
    {
        $this->cards->append($this->backlog, 'Rewrite the portfolio landing copy');

        $this->get('/projects/dashboard?board=client-work')
            ->assertOk()
            ->assertSee('Client Work');
    }

    public function test_the_dashboard_view_renders_for_an_empty_board(): void
    {
        $empty = Board::factory()->create(['name' => 'Empty Board', 'slug' => 'empty-board']);

        // "No lists to count yet" until the UI audit split one nothing into two:
        // a board with lists but no cards was being told there were no lists
        // while four named ones sat on the canvas next door. This board has no
        // lists at all, so it gets the first of the two. See the comment at the
        // `@else` in ⚡board-dashboard.blade.php.
        $this->get('/projects/dashboard?board=empty-board')
            ->assertOk()
            ->assertSee('No lists on this board yet');
    }

    /* Table: mirror counting --------------------------------------------------- */

    public function test_the_table_view_shows_a_mirrored_card_once_per_placement(): void
    {
        $card = $this->cards->append($this->backlog, 'Shared onboarding checklist');
        $this->cards->mirror($card, $this->doing);

        $rows = Livewire::test('project::table')
            ->set('activeBoard', 'client-work')
            ->viewData('rows');

        $forThisCard = $rows->filter(fn ($placement) => $placement->card_id === $card->id);

        // One row per placement: the origin in Backlog and the mirror in
        // Doing, both carrying the same card.
        $this->assertCount(2, $forThisCard);
        $this->assertSame([$this->backlog->id, $this->doing->id], $forThisCard->pluck('board_list_id')->sort()->values()->all());

        $this->get('/projects/table?board=client-work')->assertOk()->assertSee('Shared onboarding checklist');
    }

    /* Table: cursor pagination --------------------------------------------------- */

    public function test_the_table_view_paginates_by_cursor_without_repeating_or_skipping_a_row(): void
    {
        $titles = [];

        for ($i = 1; $i <= 30; $i++) {
            $title = 'Task number '.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $titles[] = $title;
            $this->cards->append($this->backlog, $title);
        }

        $first = Livewire::test('project::table')->set('activeBoard', 'client-work');
        $firstIds = $first->viewData('rows')->pluck('id')->all();

        $this->assertCount(25, $firstIds);

        $second = $first->call('goToCursor', $first->viewData('rows')->nextCursor()->encode());
        $secondIds = $second->viewData('rows')->pluck('id')->all();

        $this->assertCount(5, $secondIds);
        $this->assertEmpty(array_intersect($firstIds, $secondIds), 'the second page repeated a row from the first');
        $this->assertCount(30, array_unique([...$firstIds, ...$secondIds]), 'a row was skipped between the two pages');
    }

    /* Table: inline editing --------------------------------------------------- */

    public function test_inline_editing_a_card_name_persists(): void
    {
        $card = $this->cards->append($this->backlog, 'Old title');
        $placement = $card->originPlacement()->firstOrFail();

        Livewire::test('project::table')->set('activeBoard', 'client-work')
            ->call('startEdit', $placement->id, 'title', 'Old title')
            ->set('editingValue', 'New title, agreed on the call')
            ->call('saveEdit');

        $this->assertSame('New title, agreed on the call', $card->fresh()->title);
    }

    public function test_inline_editing_the_due_date_persists(): void
    {
        $card = $this->cards->append($this->backlog, 'Ship the draft');
        $placement = $card->originPlacement()->firstOrFail();
        $due = now()->addDays(5)->toDateString();

        Livewire::test('project::table')->set('activeBoard', 'client-work')
            ->call('startEdit', $placement->id, 'due', '')
            ->set('editingValue', $due)
            ->call('saveEdit');

        $this->assertSame($due, $card->fresh()->due_on->toDateString());
    }

    public function test_an_empty_title_is_refused_rather_than_saved(): void
    {
        $card = $this->cards->append($this->backlog, 'Keep this title');
        $placement = $card->originPlacement()->firstOrFail();

        Livewire::test('project::table')->set('activeBoard', 'client-work')
            ->call('startEdit', $placement->id, 'title', 'Keep this title')
            ->set('editingValue', '   ')
            ->call('saveEdit');

        $this->assertSame('Keep this title', $card->fresh()->title);
    }

    public function test_changing_the_list_moves_the_placement_through_card_service(): void
    {
        $card = $this->cards->append($this->backlog, 'Move me');
        $placement = $card->originPlacement()->firstOrFail();

        Livewire::test('project::table')->set('activeBoard', 'client-work')
            ->call('changeList', $placement->id, $this->doing->id);

        $this->assertSame($this->doing->id, $placement->fresh()->board_list_id);
    }

    /* Calendar: reschedule ----------------------------------------------------- */

    public function test_dragging_a_card_on_the_calendar_reschedules_it(): void
    {
        $card = $this->cards->append($this->backlog, 'Reschedule me');
        $card->forceFill([
            'start_on' => now()->addDays(2)->toDateString(),
            'due_on' => now()->addDays(4)->toDateString(),
        ])->save();

        Livewire::test('project::calendar')->set('activeBoard', 'client-work')
            ->call('reschedule', $card->id, 3);

        $fresh = $card->fresh();
        $this->assertSame(now()->addDays(5)->toDateString(), $fresh->start_on->toDateString());
        $this->assertSame(now()->addDays(7)->toDateString(), $fresh->due_on->toDateString());
    }

    public function test_a_reschedule_of_a_card_no_longer_on_the_board_toasts_an_error(): void
    {
        $card = $this->cards->append($this->backlog, 'Vanishing card');
        $card->forceFill(['due_on' => now()->addDay()->toDateString()])->save();
        $card->delete();

        Livewire::test('project::calendar')->set('activeBoard', 'client-work')
            ->call('reschedule', $card->id, 1)
            ->assertDispatched('toast');
    }

    public function test_the_calendar_shows_one_event_per_card_not_per_placement(): void
    {
        $card = $this->cards->append($this->backlog, 'Mirrored and dated');
        $card->forceFill(['due_on' => now()->addDays(2)->toDateString()])->save();
        $this->cards->mirror($card, $this->doing);

        Livewire::test('project::calendar')->set('activeBoard', 'client-work')
            ->assertViewHas('events', fn (array $events): bool => count($events) === 1);
    }

    /* Dashboard: the hand-computed fixture, including the mirror case --------- */

    public function test_dashboard_counts_match_a_hand_computed_fixture_including_the_mirror_case(): void
    {
        $member = User::factory()->create(['name' => 'Sara Rahimi']);

        // Backlog: two cards of its own, one of them mirrored into Doing.
        $shared = $this->cards->append($this->backlog, 'Shared spec review');
        $shared->forceFill(['due_on' => now()->subDay()->toDateString()])->save(); // overdue
        $shared->labels()->attach($this->bug);
        $shared->members()->attach($member);
        $this->cards->mirror($shared, $this->doing);

        $soloBacklog = $this->cards->append($this->backlog, 'Solo backlog card');
        $soloBacklog->forceFill(['due_on' => null])->save(); // no due date

        // Doing: one card of its own, plus the mirror above.
        // `dueState()` reads `completed_at` only once `due_on` is set — a
        // date-less card is 'none' regardless of `completed_at` — so this
        // needs both to land in the 'done' bucket rather than 'none'.
        $soloDoing = $this->cards->append($this->doing, 'Solo doing card');
        $soloDoing->forceFill(['due_on' => now()->addDays(3)->toDateString(), 'completed_at' => now()])->save();
        $soloDoing->labels()->attach($this->bug);

        // Hand-computed expectations:
        // - Backlog placements: 2 (shared origin + solo backlog).
        // - Doing placements: 2 (solo doing + the mirror of `shared`).
        // - Distinct active cards: 3 (shared, soloBacklog, soloDoing) — the
        //   mirror does not add a fourth.
        // - Due-date buckets, by distinct card: 1 overdue (shared), 1 none
        //   (soloBacklog, no due date at all), 1 done (soloDoing, dated and completed).
        // - Members: Sara carries exactly 1 card (shared), not 2 — the mirror
        //   does not double her count.
        // - Label "Bug": on 2 distinct cards (shared, soloDoing), not 3.
        Livewire::test('project::board-dashboard')->set('activeBoard', 'client-work')
            ->assertViewHas('totalCards', 3)
            ->assertViewHas('totalPlacements', 4)
            ->assertViewHas('listChart', function (array $chart): bool {
                $byLabel = array_combine($chart['categories'], $chart['series']);

                return $byLabel['Backlog'] === 2 && $byLabel['Doing'] === 2;
            })
            ->assertViewHas('dueChart', function (array $chart): bool {
                $byLabel = array_combine($chart['labels'], $chart['series']);

                return $byLabel['Overdue'] === 1 && $byLabel['No due date'] === 1 && $byLabel['Complete'] === 1;
            })
            ->assertViewHas('perMember', function ($rows) use ($member): bool {
                $row = $rows->firstWhere('id', $member->id);

                return $row !== null && (int) $row->total === 1;
            })
            ->assertViewHas('perLabel', function ($rows): bool {
                $row = $rows->firstWhere('id', $this->bug->id);

                return $row !== null && (int) $row->total === 2;
            });
    }

    /* The plain link between the views ------------------------------------------ */

    public function test_every_view_links_to_the_other_three(): void
    {
        $this->cards->append($this->backlog, 'Anything');

        $routes = [
            '/projects/table' => 'projects.table',
            '/projects/calendar' => 'projects.calendar',
            '/projects/dashboard' => 'projects.dashboard',
        ];

        foreach ($routes as $path => $ownRoute) {
            $html = $this->get($path.'?board=client-work')->assertOk()->getContent();

            $this->assertStringContainsString(route('projects.boards', ['board' => 'client-work']), $html);

            foreach ($routes as $otherRoute) {
                if ($otherRoute === $ownRoute) {
                    continue;
                }

                $this->assertStringContainsString(route($otherRoute, ['board' => 'client-work']), $html);
            }
        }
    }
}
