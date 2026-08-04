<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Models\Label;
use Modules\Project\Services\BoardCalendar;
use Modules\Project\Services\CardService;
use Modules\Project\Services\Watching;
use Modules\Project\Support\Position;
use Tests\TestCase;

/**
 * The board's interaction model, exercised server-side.
 *
 * Every panel on this page is Livewire state, so every panel is testable here:
 * whether it opens, whether opening one shuts the others, and whether the drop
 * a browser reports actually reaches a component method and survives a reload.
 */
class BoardsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Board $board;

    private Board $other;

    private BoardList $backlog;

    private BoardList $todo;

    private Label $bug;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Nima Fazlipour']);
        $this->actingAs($this->user);

        $this->board = Board::factory()->create(['name' => 'Client Work', 'slug' => 'client-work', 'position' => 1]);
        $this->other = Board::factory()->create(['name' => 'Outreach', 'slug' => 'outreach', 'position' => 2]);

        $this->backlog = BoardList::factory()->for($this->board)->create(['name' => 'Backlog', 'position' => Position::format('1024')]);
        $this->todo = BoardList::factory()->for($this->board)->create(['name' => 'To Do', 'position' => Position::format('2048')]);

        $this->bug = Label::factory()->for($this->board)->create(['name' => 'Bug', 'colour' => 'destructive']);

        $service = app(CardService::class);

        $service->append($this->backlog, 'Rewrite portfolio landing copy');
        $service->append($this->backlog, 'Collect testimonials from past clients');
        $bugCard = $service->append($this->todo, 'Fix invoice PDF margins');
        $bugCard->labels()->attach($this->bug);

        $overdue = $service->append($this->todo, 'Q3 expense reconciliation');
        $overdue->forceFill(['due_on' => now()->subDays(3)->toDateString()])->save();

        BoardList::factory()->for($this->other)->create(['name' => 'Leads', 'position' => Position::format('1024')]);
    }

    /**
     * The board page, with entities decoded.
     *
     * Livewire escapes the body of an `@script` block into the markup, so a
     * quote in the JavaScript arrives as `&#039;`. Decoding lets the
     * assertions below read like the source they are checking.
     */
    private function boardHtml(): string
    {
        return html_entity_decode(
            $this->get('/projects')->assertOk()->getContent(),
            ENT_QUOTES | ENT_HTML5,
        );
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

    /** The placement a card lives on. */
    private function placementOf(Card $card): CardPlacement
    {
        return $card->originPlacement()->firstOrFail();
    }

    /* Drag and drop ------------------------------------------------------- */

    public function test_a_dropped_card_reaches_the_component(): void
    {
        // Sortable's onEnd used to broadcast a `card-moved` browser event that
        // nothing listened for, so every drop was silently discarded. It now
        // calls the method directly on its own component.
        $html = $this->boardHtml();

        $this->assertStringContainsString('$wire.moveCard(', $html);
        $this->assertStringNotContainsString('card-moved', $html);
    }

    public function test_a_card_dragged_between_lists_is_still_there_after_a_refresh(): void
    {
        $card = Card::query()->where('title', 'Rewrite portfolio landing copy')->firstOrFail();

        // The browser reports the placement it dragged, not the card: one card
        // may be on two lists and only one of them moved.
        Livewire::test('project::boards')
            ->call('moveCard', $this->placementOf($card)->id, (string) $this->todo->id, 0)
            ->assertDispatched('toast');

        // Not the object we just wrote — a fresh page load.
        $this->get('/projects')->assertOk()->assertSee('Rewrite portfolio landing copy');

        $this->assertSame($this->todo->id, Card::query()->find($card->id)->originPlacement->board_list_id);
        $this->assertSame('Rewrite portfolio landing copy', $this->titlesIn($this->todo)[0]);
    }

    public function test_a_drop_onto_a_list_from_another_board_is_refused(): void
    {
        $card = Card::query()->where('title', 'Fix invoice PDF margins')->firstOrFail();
        $foreign = BoardList::query()->where('board_id', $this->other->id)->firstOrFail();

        Livewire::test('project::boards')
            ->call('moveCard', $this->placementOf($card)->id, (string) $foreign->id, 0);

        $this->assertSame($this->todo->id, Card::query()->find($card->id)->originPlacement->board_list_id);
    }

    public function test_a_drop_that_changes_nothing_is_not_sent_to_the_server(): void
    {
        $this->assertStringContainsString('evt.oldIndex === evt.newIndex', $this->boardHtml());
    }

    public function test_only_cards_are_draggable(): void
    {
        // Without an explicit `draggable`, the "Nothing in this list yet"
        // paragraph is picked up by Sortable and dropped into another list. An
        // archived card left on a mirror is shown but not dragged either — it
        // is a note about where the card was, not a card on the board.
        $this->assertStringContainsString(
            "draggable: '[data-placement-id]:not([data-archived])'",
            $this->boardHtml(),
        );
    }

    public function test_the_board_page_ships_the_drag_and_drop_script(): void
    {
        // `@push('scripts')` is silently discarded inside a Livewire component:
        // neither the stack nor `@assets` survives the trip to the layout. The
        // board's drag and drop was dead for days because of it, so the page is
        // asserted to actually carry both the library and its initialiser.
        $html = $this->boardHtml();

        $this->assertStringContainsString('/vendor/sortablejs/Sortable.min.js', $html);
        $this->assertStringContainsString('new Sortable(', $html);
        $this->assertStringContainsString('kargah-list', $html);
    }

    public function test_the_drag_initialiser_asks_sortable_whether_it_already_owns_the_list(): void
    {
        // A `data-*` flag cannot survive the morph: Livewire's patcher removes
        // any attribute missing from the incoming HTML, so the guard clears
        // itself on every re-render and a second Sortable binds to the same
        // element. Ask the library instead.
        $html = $this->boardHtml();

        $this->assertStringContainsString('Sortable.get(', $html);
        $this->assertStringNotContainsString('sortableMounted', $html);
    }

    /**
     * An island inside a `@foreach` shares one compile-time token with every
     * other iteration, and the client finds the fragment to morph by token
     * alone — so asking for the seventh column morphs the first. The board
     * canvas is therefore one island, and this is the guard on that.
     */
    public function test_the_board_declares_exactly_one_island(): void
    {
        $source = file_get_contents(base_path('Modules/Project/resources/views/components/⚡boards.blade.php'));

        $this->assertSame(1, substr_count($source, '@island('), 'A second island in this file would share a token with the first.');
        $this->assertStringContainsString("renderIsland('board')", $source);
    }

    /**
     * An island nobody names keeps whatever the DOM already had — the fragment
     * comes back with `mode=skip` and the morph engine walks straight past it.
     * So every action that changes a card has to produce an island fragment,
     * and this is what proves the ones that matter do.
     */
    public function test_an_action_that_changes_the_board_sends_the_canvas_back(): void
    {
        $changesTheBoard = [
            ['toggleLabelFilter', [$this->bug->id]],
            ['setDueFilter', ['overdue']],
            ['clearFilters', []],
            ['addList', []],
            ['archiveCardsInList', [$this->todo->id]],
            ['selectBoard', ['outreach']],
        ];

        foreach ($changesTheBoard as [$method, $args]) {
            $component = Livewire::test('project::boards')
                ->set('newListName', 'Waiting on client')
                ->call($method, ...$args);

            $this->assertNotEmpty(
                $component->effects['islandFragments'] ?? [],
                $method.'() changed the board but never named the island, so the browser keeps the old cards.',
            );
        }
    }

    public function test_an_action_that_only_opens_a_panel_leaves_the_canvas_alone(): void
    {
        $component = Livewire::test('project::boards')->call('toggleFilterPanel');

        $this->assertEmpty(
            $component->effects['islandFragments'] ?? [],
            'Opening a panel re-sent every card on the board, which is the cost islands exist to avoid.',
        );
    }

    /* One panel at a time -------------------------------------------------- */

    public function test_opening_a_list_menu_closes_the_filter_panel(): void
    {
        Livewire::test('project::boards')
            ->call('toggleFilterPanel')
            ->assertSet('filterOpen', true)
            ->call('toggleListMenu', $this->todo->id)
            ->assertSet('listMenuOpen', $this->todo->id)
            ->assertSet('filterOpen', false);
    }

    public function test_opening_a_list_menu_closes_the_board_picker(): void
    {
        Livewire::test('project::boards')
            ->call('toggleBoardPicker')
            ->assertSet('boardPickerOpen', true)
            ->call('toggleListMenu', $this->todo->id)
            ->assertSet('boardPickerOpen', false);
    }

    public function test_opening_the_filter_panel_closes_a_list_menu(): void
    {
        Livewire::test('project::boards')
            ->call('toggleListMenu', $this->todo->id)
            ->call('toggleFilterPanel')
            ->assertSet('listMenuOpen', null);
    }

    public function test_opening_the_board_picker_closes_an_inline_form(): void
    {
        Livewire::test('project::boards')
            ->call('startAddList')
            ->assertSet('addingList', true)
            ->call('toggleBoardPicker')
            ->assertSet('addingList', false);
    }

    public function test_an_open_panel_can_be_dismissed_by_clicking_away(): void
    {
        Livewire::test('project::boards')
            ->call('toggleFilterPanel')
            ->assertSeeHtml('wire:click="dismissPanels"')
            ->call('dismissPanels')
            ->assertSet('filterOpen', false)
            ->assertDontSeeHtml('wire:click="dismissPanels"');
    }

    /* Switching board ------------------------------------------------------ */

    public function test_switching_board_is_silent_and_shuts_what_was_open(): void
    {
        // The heading changes to the new board and the canvas redraws, so the
        // switch is its own notification. Nor may the card form announce itself
        // on the way out.
        Livewire::test('project::boards')
            ->call('startAddCard', $this->backlog->id)
            ->assertSet('addingCardIn', $this->backlog->id)
            ->call('selectBoard', 'outreach')
            ->assertSet('activeBoard', 'outreach')
            ->assertSet('addingCardIn', null)
            ->assertNotDispatched('toast');
    }

    public function test_switching_board_drops_filters_that_cannot_apply(): void
    {
        // The Bug label belongs to Client Work; carrying it to Outreach shows
        // an empty board with no visible reason.
        Livewire::test('project::boards')
            ->call('toggleLabelFilter', $this->bug->id)
            ->call('selectBoard', 'outreach')
            ->assertSet('filterLabels', [])
            ->assertSet('filterAssignees', [])
            ->assertSet('filterDue', '')
            ->assertSet('search', '');
    }

    public function test_an_unknown_board_in_the_url_falls_back_to_a_real_one(): void
    {
        $component = Livewire::withQueryParams(['board' => 'does-not-exist'])->test('project::boards');

        $this->assertSame('client-work', $component->get('activeBoard'));
        $component->assertSee('Client Work');
    }

    public function test_an_archived_board_in_the_url_falls_back_to_a_real_one(): void
    {
        $this->board->forceFill(['archived_at' => now()])->save();

        $component = Livewire::withQueryParams(['board' => 'client-work'])->test('project::boards');

        $this->assertSame('outreach', $component->get('activeBoard'));
    }

    /* Deep-linking to a card ------------------------------------------------ */

    /**
     * `?card=` is an `#[Url]` property, so it is whatever anybody types.
     *
     * The positive case is one line; the four below it are the ones that
     * matter. Before this existed the router accepted `?card=1234`, the
     * component ignored it, and the person landed on the board with the card
     * shut — a link that looks like it works and silently does not. The risk in
     * fixing it is the opposite one: a card id is an integer somebody can
     * increment, and `⚡card-detail.blade.php::openCard()` is a bare `find()`
     * with no check of its own, so anything this lets through is opened.
     */
    private function deepLink(int|string $card, string $board = 'client-work'): Testable
    {
        return Livewire::withQueryParams(['board' => $board, 'card' => $card])->test('project::boards');
    }

    /** The refusal is one message for every reason, so it cannot be used to probe for ids. */
    private function assertRefused(Testable $component): void
    {
        $component
            ->assertNotDispatched('open-card')
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'error');
    }

    public function test_a_card_id_in_the_url_opens_that_card(): void
    {
        $card = Card::query()->where('title', 'Fix invoice PDF margins')->firstOrFail();

        $this->deepLink($card->id)
            ->assertDispatched('open-card', cardId: $card->id)
            // The drawer reports that it opened; the board has nothing to add.
            ->assertNotDispatched('toast');
    }

    public function test_a_card_on_another_board_does_not_open(): void
    {
        $leads = BoardList::query()->where('board_id', $this->other->id)->firstOrFail();
        $foreign = app(CardService::class)->append($leads, 'Chase the Bluepeak referral');

        $this->assertRefused($this->deepLink($foreign->id));
    }

    /**
     * An archived card is refused even though `cardOnThisBoard()` alone would
     * return it — that helper deliberately admits one so `quickArchive()` can
     * refuse to archive it twice.
     */
    public function test_an_archived_card_does_not_open(): void
    {
        $card = Card::query()->where('title', 'Fix invoice PDF margins')->firstOrFail();
        $card->forceFill(['archived_at' => now()])->save();

        $this->assertRefused($this->deepLink($card->id));
    }

    public function test_a_deleted_card_does_not_open(): void
    {
        $card = Card::query()->where('title', 'Fix invoice PDF margins')->firstOrFail();
        $id = $card->id;
        $card->delete();

        $this->assertRefused($this->deepLink($id));
    }

    public function test_a_card_id_that_never_existed_does_not_open(): void
    {
        $this->assertRefused($this->deepLink(999999));
    }

    /**
     * A cast would turn this into `0` and send a query for it. The board still
     * has to render — a mistyped link is not a 500.
     */
    public function test_a_card_id_that_is_not_a_number_is_refused_without_breaking_the_board(): void
    {
        $this->assertRefused($this->deepLink('abc')->assertSee('Client Work'));
    }

    /**
     * The deep link fires once, on the request that carried the URL.
     *
     * `?card=` stays in the address bar after the drawer opens — that is the
     * point of a shareable link — so anything that read it outside `mount()`
     * would drag the drawer back open on every filter keystroke.
     */
    public function test_the_card_in_the_url_is_opened_once_and_not_on_every_render(): void
    {
        $card = Card::query()->where('title', 'Fix invoice PDF margins')->firstOrFail();

        $this->deepLink($card->id)
            ->assertDispatched('open-card')
            ->call('toggleFilterPanel')
            ->assertNotDispatched('open-card')
            ->set('search', 'invoice')
            ->assertNotDispatched('open-card');
    }

    /**
     * Switching board drops the card the URL named, for the same reason it
     * drops the filters: it belongs to the board being left.
     */
    public function test_switching_board_forgets_the_card_the_url_named(): void
    {
        $card = Card::query()->where('title', 'Fix invoice PDF margins')->firstOrFail();

        $this->deepLink($card->id)
            ->call('selectBoard', 'outreach')
            ->assertSet('deepLinkedCard', '');
    }

    /* The links that lead here ---------------------------------------------- */

    public function test_a_card_notification_links_to_the_card_and_not_only_its_board(): void
    {
        $card = Card::query()->where('title', 'Fix invoice PDF margins')->firstOrFail();

        $url = (string) app(Watching::class)->cardUrl($card);

        $this->assertStringContainsString('board=client-work', $url);
        $this->assertStringContainsString('card='.$card->id, $url);
    }

    public function test_every_calendar_entry_links_to_its_own_card(): void
    {
        $card = Card::query()->where('title', 'Q3 expense reconciliation')->firstOrFail();

        $urls = collect(app(BoardCalendar::class)->events($this->board))
            ->pluck('url')
            ->all();

        $this->assertSame(
            [route('projects.boards', ['board' => 'client-work', 'card' => $card->id])],
            $urls,
        );
        $this->assertStringContainsString('card='.$card->id, $urls[0]);
    }

    /* Filtering ------------------------------------------------------------ */

    public function test_a_whitespace_only_search_is_not_counted_as_a_filter(): void
    {
        Livewire::test('project::boards')
            ->set('search', '   ')
            ->assertViewHas('activeFilters', 0)
            ->assertViewHas('visibleCards', 4);
    }

    public function test_clearing_filters_restores_every_card(): void
    {
        Livewire::test('project::boards')
            ->set('search', 'invoice')
            ->call('toggleLabelFilter', $this->bug->id)
            ->call('setDueFilter', 'overdue')
            ->assertViewHas('activeFilters', 3)
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('filterLabels', [])
            ->assertSet('filterDue', '')
            ->assertViewHas('activeFilters', 0)
            ->assertViewHas('visibleCards', 4);
    }

    public function test_a_search_matches_case_insensitively_within_the_title(): void
    {
        Livewire::test('project::boards')
            ->set('search', 'INVOICE')
            ->assertViewHas('visibleCards', 1);
    }

    /**
     * Asserted on view data, not on the response body: the canvas is an island,
     * so after an action its markup travels in `effects.islandFragments` rather
     * than in the component's HTML. `assertSee` would be checking the toolbar.
     */
    public function test_a_label_filter_keeps_only_the_cards_wearing_it(): void
    {
        Livewire::test('project::boards')
            ->call('toggleLabelFilter', $this->bug->id)
            ->assertViewHas('visibleCards', 1)
            ->assertViewHas('lists', function ($lists): bool {
                return $lists->flatMap(fn (array $entry) => $entry['placements']->pluck('card.title'))->all()
                    === ['Fix invoice PDF margins'];
            });
    }

    public function test_the_overdue_filter_finds_a_genuinely_overdue_card(): void
    {
        Livewire::test('project::boards')
            ->call('setDueFilter', 'overdue')
            ->assertViewHas('visibleCards', 1)
            ->assertViewHas('lists', function ($lists): bool {
                return $lists->flatMap(fn (array $entry) => $entry['placements']->pluck('card.title'))->all()
                    === ['Q3 expense reconciliation'];
            });
    }

    /** The first paint carries the cards; only later actions skip the island. */
    public function test_the_first_load_paints_every_card(): void
    {
        $this->get('/projects')
            ->assertOk()
            ->assertSee('Rewrite portfolio landing copy')
            ->assertSee('Fix invoice PDF margins')
            ->assertSee('Backlog')
            ->assertSee('To Do');
    }

    /* Inline forms --------------------------------------------------------- */

    public function test_opening_a_card_form_closes_the_one_already_open(): void
    {
        Livewire::test('project::boards')
            ->call('startAddCard', $this->backlog->id)
            ->assertSet('addingCardIn', $this->backlog->id)
            ->call('startAddCard', $this->todo->id)
            ->assertSet('addingCardIn', $this->todo->id);
    }

    public function test_a_card_form_keeps_nothing_typed_into_the_last_one(): void
    {
        Livewire::test('project::boards')
            ->call('startAddCard', $this->backlog->id)
            ->set('newCardTitle', 'Half-written thought')
            ->call('startAddCard', $this->todo->id)
            ->assertSet('newCardTitle', '');
    }

    /* Creating things ------------------------------------------------------- */

    public function test_a_card_is_added_at_the_bottom_of_its_list(): void
    {
        Livewire::test('project::boards')
            ->call('startAddCard', $this->backlog->id)
            ->set('newCardTitle', 'Draft the Northwind scope document')
            ->call('addCard', $this->backlog->id)
            ->assertSet('newCardTitle', '')
            // Adding one card usually means adding three, so the form stays open.
            ->assertSet('addingCardIn', $this->backlog->id);

        $this->assertSame(
            ['Rewrite portfolio landing copy', 'Collect testimonials from past clients', 'Draft the Northwind scope document'],
            $this->titlesIn($this->backlog),
        );
    }

    public function test_a_card_with_no_title_is_refused_rather_than_created(): void
    {
        $before = Card::query()->count();

        Livewire::test('project::boards')
            ->call('startAddCard', $this->backlog->id)
            ->set('newCardTitle', '   ')
            ->call('addCard', $this->backlog->id)
            ->assertDispatched('toast', function (string $event, array $params): bool {
                return $params[0]['type'] === 'error';
            });

        $this->assertSame($before, Card::query()->count());
    }

    public function test_a_list_is_added_at_the_end_of_the_board(): void
    {
        Livewire::test('project::boards')
            ->call('startAddList')
            ->set('newListName', 'Waiting on client')
            ->call('addList')
            ->assertSet('newListName', '');

        $names = BoardList::query()->where('board_id', $this->board->id)->active()->orderBy('position')->pluck('name')->all();

        $this->assertSame(['Backlog', 'To Do', 'Waiting on client'], $names);
    }

    /* Archiving ------------------------------------------------------------- */

    public function test_archiving_a_list_takes_its_cards_with_it_and_keeps_them_readable(): void
    {
        Livewire::test('project::boards')
            ->call('archiveList', $this->todo->id)
            ->assertDispatched('toast');

        $this->assertNotNull(BoardList::query()->find($this->todo->id)->archived_at);
        $this->assertSame(2, Card::query()
            ->whereIn('id', CardPlacement::query()->origin()->where('board_list_id', $this->todo->id)->select('card_id'))
            ->archived()
            ->count());
        // Nothing is deleted: the cards are still there, still placed here.
        $this->assertDatabaseHas('card_placements', ['board_list_id' => $this->todo->id, 'is_origin' => true]);
    }

    public function test_archiving_the_cards_leaves_the_list_on_the_board(): void
    {
        Livewire::test('project::boards')
            ->call('archiveCardsInList', $this->todo->id)
            ->assertDispatched('toast');

        $this->assertNull(BoardList::query()->find($this->todo->id)->archived_at);
        $this->assertSame([], $this->titlesIn($this->todo));
    }

    public function test_archiving_an_empty_list_says_so_rather_than_claiming_a_number(): void
    {
        $empty = BoardList::factory()->for($this->board)->create(['name' => 'Review', 'position' => Position::format('4096')]);

        Livewire::test('project::boards')
            ->call('archiveCardsInList', $empty->id)
            ->assertDispatched('toast', function (string $event, array $params): bool {
                return $params[0]['message'] === 'Nothing to archive';
            });
    }

    /* No fixtures left behind ------------------------------------------------ */

    public function test_the_board_reads_from_the_database_rather_than_a_literal(): void
    {
        Board::query()->update(['name' => 'Renamed In The Database']);

        $this->get('/projects')->assertOk()->assertSee('Renamed In The Database');
    }
}
