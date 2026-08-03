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
use Modules\Project\Models\Label;
use Modules\Project\Services\CardService;
use Modules\Project\Support\Position;
use Tests\TestCase;

/**
 * The search operators, wired to `⚡boards.blade.php`.
 *
 * `SearchCompilerTest` exercises the compiler in isolation; this proves the
 * board actually uses it — the toolbar's search box, the filter panel that
 * has to keep working alongside it, and the mirrored-card and query-count
 * properties that only the real page can show.
 */
class BoardSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Board $board;

    private BoardList $todo;

    private BoardList $doing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Nima Fazlipour', 'timezone' => 'UTC']);
        $this->actingAs($this->user);

        $this->board = Board::factory()->create(['name' => 'Client Work', 'slug' => 'client-work']);
        $this->todo = BoardList::factory()->for($this->board)->create(['name' => 'To Do', 'position' => Position::format('1024')]);
        $this->doing = BoardList::factory()->for($this->board)->create(['name' => 'Doing', 'position' => Position::format('2048')]);
    }

    private function visibleTitles($component): array
    {
        return collect($component->viewData('lists'))
            ->flatMap(fn (array $entry) => $entry['placements']->pluck('card.title'))
            ->all();
    }

    /* Operators through the search box --------------------------------------- */

    public function test_the_search_box_understands_a_member_operator(): void
    {
        $service = app(CardService::class);

        $withNima = $service->append($this->todo, 'Draft the Northwind proposal');
        $withNima->members()->attach($this->user);

        $service->append($this->todo, 'Unrelated card');

        $component = Livewire::test('project::boards')->set('search', 'member:nima');

        $this->assertSame(['Draft the Northwind proposal'], $this->visibleTitles($component));
    }

    public function test_the_search_box_matches_description_not_only_title(): void
    {
        // The bug 06-trello-parity.md names: the board used to match the title
        // only. `member:` above already proves operators work; this proves the
        // free-text path was actually fixed, not merely routed differently.
        $service = app(CardService::class);
        $service->append($this->todo, 'Weekly status note', ['description' => 'Mentions the Northwind retainer']);
        $service->append($this->todo, 'Something else entirely');

        $component = Livewire::test('project::boards')->set('search', 'northwind');

        $this->assertSame(['Weekly status note'], $this->visibleTitles($component));
    }

    public function test_sort_directive_reorders_the_column(): void
    {
        $service = app(CardService::class);
        $service->append($this->todo, 'Due last')->forceFill(['due_on' => '2026-09-01'])->save();
        $service->append($this->todo, 'Due first')->forceFill(['due_on' => '2026-08-01'])->save();
        $service->append($this->todo, 'Due middle')->forceFill(['due_on' => '2026-08-15'])->save();

        $component = Livewire::test('project::boards')->set('search', 'sort:due');

        $this->assertSame(['Due first', 'Due middle', 'Due last'], $this->visibleTitles($component));
    }

    /* Panel filter + typed operator ------------------------------------------- */

    /**
     * Decision: the filter panel and the typed search AND together, the same
     * way every other pair of narrowing controls in this application does.
     * Ticking a label while typing `member:` shows only what satisfies both.
     */
    public function test_a_panel_filter_and_a_typed_operator_combine_with_and(): void
    {
        $bug = Label::factory()->for($this->board)->create(['name' => 'Bug']);
        $service = app(CardService::class);

        $bugAndNima = $service->append($this->todo, 'Bug assigned to Nima');
        $bugAndNima->labels()->attach($bug);
        $bugAndNima->members()->attach($this->user);

        $bugOnly = $service->append($this->todo, 'Bug, nobody assigned');
        $bugOnly->labels()->attach($bug);

        $component = Livewire::test('project::boards')
            ->call('toggleLabelFilter', $bug->id)
            ->set('search', 'member:nima');

        $this->assertSame(['Bug assigned to Nima'], $this->visibleTitles($component));
    }

    /* Unsupported operators ---------------------------------------------------- */

    public function test_an_unsupported_operator_shows_no_cards_and_says_so(): void
    {
        app(CardService::class)->append($this->todo, 'Would otherwise be visible');

        // `has:stickers` is the last operator with nothing behind it.
        // `has:cover`, `has:attachments` and `is:starred` used to be here too
        // and are now real — see `SearchCompiler`'s class docblock.
        $component = Livewire::test('project::boards')->set('search', 'has:stickers');

        $this->assertSame([], $this->visibleTitles($component));
        $component->assertViewHas('searchWarning', fn (?string $warning) => $warning !== null && str_contains($warning, 'has:stickers'));
    }

    public function test_a_supported_search_carries_no_warning(): void
    {
        app(CardService::class)->append($this->todo, 'Ordinary card');

        $component = Livewire::test('project::boards')->set('search', 'ordinary');

        $component->assertViewHas('searchWarning', null);
    }

    /* Mirrors -------------------------------------------------------------------- */

    public function test_a_mirrored_card_is_drawn_once_per_placement_on_one_board(): void
    {
        $service = app(CardService::class);
        $card = $service->append($this->todo, 'Shown twice');
        $service->mirror($card, $this->doing);

        $component = Livewire::test('project::boards');

        $this->assertSame(['Shown twice', 'Shown twice'], $this->visibleTitles($component));
        $this->assertSame(2, $component->viewData('totalCards'));
    }

    /* The 'soon' panel fix ---------------------------------------------------------- */

    /**
     * `Card::dueState()` grew a fifth state, `'due'`, for a card due exactly
     * today — previously folded into `'soon'`. The panel's "next week" bucket
     * has to include it explicitly or a card due today, the one a person
     * filtering for "coming up" most wants to see, silently drops out.
     */
    public function test_a_card_due_today_matches_the_soon_panel_filter(): void
    {
        $service = app(CardService::class);
        $dueToday = $service->append($this->todo, 'Due today');
        $dueToday->forceFill(['due_on' => now()->toDateString()])->save();

        $service->append($this->todo, 'Due next month')->forceFill(['due_on' => now()->addMonth()->toDateString()])->save();

        $component = Livewire::test('project::boards')->call('setDueFilter', 'soon');

        $this->assertSame(['Due today'], $this->visibleTitles($component));
    }

    /* Scale ------------------------------------------------------------------------ */

    /** The page issues the same number of queries for fifty cards as it always did. */
    public function test_rendering_the_board_issues_a_bounded_number_of_queries_with_fifty_cards(): void
    {
        $service = app(CardService::class);

        for ($i = 0; $i < 50; $i++) {
            $service->append($this->todo, "Card {$i}");
        }

        DB::enableQueryLog();
        $this->get('/projects')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(20, $count, 'the page should not issue one query per card');
    }
}
