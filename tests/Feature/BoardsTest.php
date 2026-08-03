<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The board's interaction model, exercised server-side.
 *
 * Every panel on this page is Livewire state, so every panel is testable here:
 * whether it opens, whether opening one shuts the others, and whether the drop
 * a browser reports actually reaches a component method.
 */
class BoardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
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

    /* Drag and drop ------------------------------------------------------- */

    public function test_a_dropped_card_reaches_the_component(): void
    {
        // Sortable's onEnd used to broadcast a `card-moved` browser event that
        // nothing listened for, so every drop was silently discarded. It now
        // calls the method directly on its own component.
        $html = $this->boardHtml();

        $this->assertStringContainsString('$wire.moveCard(', $html);
        $this->assertStringNotContainsString('card-moved', $html);

        Livewire::test('project::boards')
            ->call('moveCard', 1, 'todo', 0)
            ->assertDispatched('toast');
    }

    public function test_a_drop_that_changes_nothing_is_not_sent_to_the_server(): void
    {
        $html = $this->boardHtml();

        $this->assertStringContainsString('evt.oldIndex === evt.newIndex', $html);
    }

    public function test_only_cards_are_draggable(): void
    {
        // Without an explicit `draggable`, the "Nothing in this list yet"
        // paragraph is picked up by Sortable and dropped into another list.
        $html = $this->boardHtml();

        $this->assertStringContainsString("draggable: '[data-card-id]'", $html);
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

    /* One panel at a time -------------------------------------------------- */

    public function test_opening_a_list_menu_closes_the_filter_panel(): void
    {
        Livewire::test('project::boards')
            ->call('toggleFilterPanel')
            ->assertSet('filterOpen', true)
            ->call('toggleListMenu', 'todo')
            ->assertSet('listMenuOpen', 'todo')
            ->assertSet('filterOpen', false);
    }

    public function test_opening_a_list_menu_closes_the_board_picker(): void
    {
        Livewire::test('project::boards')
            ->call('toggleBoardPicker')
            ->assertSet('boardPickerOpen', true)
            ->call('toggleListMenu', 'todo')
            ->assertSet('boardPickerOpen', false);
    }

    public function test_opening_the_filter_panel_closes_a_list_menu(): void
    {
        Livewire::test('project::boards')
            ->call('toggleListMenu', 'todo')
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

    /* Switching board ------------------------------------------------------ */

    public function test_switching_board_reports_once(): void
    {
        // Closing the card form and the list form on the way out must not each
        // announce itself: one action, one toast.
        $component = Livewire::test('project::boards')
            ->call('startAddCard', 'backlog')
            ->call('selectBoard', 'outreach');

        $toasts = collect($component->effects['dispatches'] ?? [])
            ->where('name', 'toast');

        $this->assertCount(1, $toasts, 'Switching board fired more than one toast.');
    }

    public function test_switching_board_drops_filters_that_cannot_apply(): void
    {
        // 'client-work' has no cards assigned to Mina; carrying the filter across
        // shows an empty board with no visible reason.
        Livewire::test('project::boards')
            ->call('toggleAssigneeFilter', 'mina')
            ->call('selectBoard', 'outreach')
            ->assertSet('filterAssignees', [])
            ->assertSet('filterLabels', [])
            ->assertSet('filterDue', '')
            ->assertSet('search', '');
    }

    public function test_an_unknown_board_in_the_url_falls_back_to_a_real_one(): void
    {
        $component = Livewire::withQueryParams(['board' => 'does-not-exist'])
            ->test('project::boards');

        $this->assertSame('client-work', $component->get('activeBoard'));
        $component->assertSee('Client Work');
    }

    /* Filtering ------------------------------------------------------------ */

    public function test_a_whitespace_only_search_is_not_counted_as_a_filter(): void
    {
        Livewire::test('project::boards')
            ->set('search', '   ')
            ->assertViewHas('activeFilters', 0)
            ->assertViewHas('visibleCards', 8);
    }

    public function test_clearing_filters_restores_every_card(): void
    {
        Livewire::test('project::boards')
            ->set('search', 'invoice')
            ->call('toggleLabelFilter', 'bug')
            ->call('setDueFilter', 'overdue')
            ->assertViewHas('activeFilters', 3)
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('filterLabels', [])
            ->assertSet('filterDue', '')
            ->assertViewHas('activeFilters', 0)
            ->assertViewHas('visibleCards', 8);
    }

    public function test_a_search_matches_case_insensitively_within_the_title(): void
    {
        Livewire::test('project::boards')
            ->set('search', 'NORTHWIND')
            ->assertViewHas('visibleCards', 1);
    }

    /* Inline forms --------------------------------------------------------- */

    public function test_opening_a_card_form_closes_the_one_already_open(): void
    {
        Livewire::test('project::boards')
            ->call('startAddCard', 'backlog')
            ->assertSet('addingCardIn', 'backlog')
            ->call('startAddCard', 'todo')
            ->assertSet('addingCardIn', 'todo');
    }

    public function test_a_card_form_keeps_nothing_typed_into_the_last_one(): void
    {
        Livewire::test('project::boards')
            ->call('startAddCard', 'backlog')
            ->set('newCardTitle', 'Half-written thought')
            ->call('startAddCard', 'todo')
            ->assertSet('newCardTitle', '');
    }
}
