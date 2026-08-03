<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Project\Database\Seeders\ProjectDatabaseSeeder;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\Label;
use Modules\Project\Services\CardService;
use Modules\Project\Support\Position;
use Tests\TestCase;

/**
 * Board settings and the archive, exercised server-side.
 *
 * Both pages are administrative: nothing on them is worth much unless it is
 * still true after a reload, so every assertion below reads the database again
 * rather than trusting the component it just called.
 */
class BoardAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Board $board;

    private BoardList $backlog;

    private BoardList $todo;

    private Label $bug;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Nima Fazlipour']);
        $this->actingAs($this->user);

        $this->board = Board::factory()->create([
            'name' => 'Client Work',
            'slug' => 'client-work',
            'colour' => 'primary',
            'position' => 1,
        ]);

        $this->backlog = BoardList::factory()->for($this->board)->create([
            'name' => 'Backlog',
            'position' => Position::format('1024'),
        ]);

        $this->todo = BoardList::factory()->for($this->board)->create([
            'name' => 'To Do',
            'position' => Position::format('2048'),
        ]);

        $this->bug = Label::factory()->for($this->board)->create(['name' => 'Bug', 'colour' => 'destructive']);

        $service = app(CardService::class);

        $service->append($this->backlog, 'Rewrite the portfolio landing copy');
        $service->append($this->todo, 'Fix invoice PDF margins')->labels()->attach($this->bug);
    }

    private function settings(): Testable
    {
        return Livewire::test('project::board-settings', ['board' => 'client-work']);
    }

    /* The page itself --------------------------------------------------------- */

    public function test_the_settings_page_loads_the_real_board_by_slug(): void
    {
        $this->get('/projects/client-work/settings')
            ->assertOk()
            ->assertSee('Client Work')
            ->assertSee('Backlog')
            ->assertSee('To Do')
            ->assertSee('Bug');
    }

    public function test_an_unknown_slug_renders_an_empty_state_rather_than_failing(): void
    {
        // The smoke test walks this route against an empty database, so an
        // abort(404) here would take the whole suite with it.
        $this->get('/projects/no-such-board/settings')
            ->assertOk()
            ->assertSee('No board answers to', false)
            ->assertDontSee('Danger zone');
    }

    public function test_the_archive_page_loads_for_an_empty_database(): void
    {
        Card::query()->forceDelete();
        BoardList::query()->forceDelete();
        Label::query()->delete();
        Board::query()->forceDelete();

        $this->get('/projects/archive')
            ->assertOk()
            ->assertSee('Nothing archived yet.');
    }

    public function test_both_routes_render_against_the_real_seeder(): void
    {
        // The smoke test walks these routes against an empty database. The
        // seeded shape is the other half, and it is the one a person sees.
        $this->seed(ProjectDatabaseSeeder::class);

        $this->get('/projects/archive')->assertOk();
        $this->get('/projects/client-work/settings')->assertOk()->assertSee('Client Work');
    }

    /* Name, description and colour --------------------------------------------- */

    public function test_renaming_the_board_persists_and_leaves_the_slug_alone(): void
    {
        $this->settings()
            ->set('name', 'Client Retainers')
            ->set('description', 'Everything a paying client is owed.')
            ->call('renameBoard')
            ->assertDispatched('toast');

        $board = Board::query()->findOrFail($this->board->id);

        $this->assertSame('Client Retainers', $board->name);
        $this->assertSame('Everything a paying client is owed.', $board->description);
        $this->assertSame('client-work', $board->slug, 'A rename must not move the board URL.');

        $this->get('/projects/client-work/settings')->assertOk()->assertSee('Client Retainers');
    }

    public function test_a_board_name_shorter_than_two_characters_is_refused(): void
    {
        $this->settings()
            ->set('name', 'C')
            ->call('renameBoard')
            ->assertHasErrors(['name']);

        $this->assertSame('Client Work', Board::query()->findOrFail($this->board->id)->name);
    }

    public function test_recolouring_the_board_persists_a_palette_key(): void
    {
        $this->settings()
            ->call('selectColour', 'success')
            ->assertDispatched('toast');

        $this->assertSame('success', Board::query()->findOrFail($this->board->id)->colour);
    }

    public function test_a_colour_outside_the_palette_is_refused(): void
    {
        $this->settings()->call('selectColour', 'hotpink');

        $this->assertSame('primary', Board::query()->findOrFail($this->board->id)->colour);
    }

    /* Labels -------------------------------------------------------------------- */

    public function test_adding_a_label_persists_it_against_the_board(): void
    {
        $this->settings()
            ->set('newLabelName', 'Hosting')
            ->set('newLabelColour', 'info')
            ->call('createLabel')
            ->assertSet('newLabelName', '')
            ->assertDispatched('toast');

        $label = Label::query()->where('board_id', $this->board->id)->where('name', 'Hosting')->first();

        $this->assertNotNull($label);
        $this->assertSame('info', $label->colour);
    }

    public function test_deleting_a_label_removes_it_and_detaches_it_from_its_cards(): void
    {
        $card = Card::query()->where('title', 'Fix invoice PDF margins')->firstOrFail();

        $this->assertSame(1, $card->labels()->count());

        $this->settings()
            ->call('deleteLabel', $this->bug->id)
            ->assertDispatched('toast');

        $this->assertNull(Label::query()->find($this->bug->id));
        $this->assertSame(0, $card->fresh()->labels()->count());
        // The card itself is not collateral damage.
        $this->assertNotNull(Card::query()->find($card->id));
    }

    public function test_a_label_can_be_renamed_and_recoloured(): void
    {
        $this->settings()
            ->call('startEditLabel', $this->bug->id)
            ->assertSet('labelDraft', 'Bug')
            ->set('labelDraft', 'Defect')
            ->set('labelColourDraft', 'warning')
            ->call('saveLabel', $this->bug->id)
            ->assertSet('editingLabel', null);

        $label = Label::query()->findOrFail($this->bug->id);

        $this->assertSame('Defect', $label->name);
        $this->assertSame('warning', $label->colour);
    }

    /* Lists ---------------------------------------------------------------------- */

    public function test_a_list_can_be_renamed_and_moved(): void
    {
        $this->settings()
            ->call('startEditList', $this->backlog->id)
            ->set('listDraft', 'Ideas')
            ->call('saveList', $this->backlog->id);

        $this->assertSame('Ideas', BoardList::query()->findOrFail($this->backlog->id)->name);

        $this->settings()->call('moveListDown', $this->backlog->id);

        $order = BoardList::query()
            ->where('board_id', $this->board->id)
            ->active()
            ->orderBy('position')
            ->pluck('name')
            ->all();

        $this->assertSame(['To Do', 'Ideas'], $order);
    }

    public function test_archiving_a_list_takes_its_cards_with_it(): void
    {
        $this->settings()
            ->call('archiveList', $this->todo->id)
            ->assertDispatched('toast');

        $this->assertNotNull(BoardList::query()->findOrFail($this->todo->id)->archived_at);
        $this->assertNotNull(Card::query()->where('title', 'Fix invoice PDF margins')->firstOrFail()->archived_at);
    }

    /* Archiving the board --------------------------------------------------------- */

    public function test_archiving_a_board_takes_it_out_of_the_picker_but_keeps_its_rows(): void
    {
        $this->settings()
            ->call('archiveBoard')
            ->assertDispatched('toast');

        $board = Board::query()->findOrFail($this->board->id);

        $this->assertNotNull($board->archived_at);
        $this->assertFalse(Board::query()->active()->pluck('slug')->contains('client-work'));

        // Nothing under it was touched.
        $this->assertSame(2, BoardList::query()->where('board_id', $board->id)->count());
        $this->assertSame(2, Card::query()->whereIn(
            'board_list_id',
            BoardList::query()->where('board_id', $board->id)->select('id'),
        )->count());

        // And the board picker on the boards page no longer offers it.
        $this->get('/projects')->assertOk()->assertDontSee('client-work');
    }

    public function test_archiving_a_board_twice_is_harmless(): void
    {
        $this->settings()->call('archiveBoard');
        $first = Board::query()->findOrFail($this->board->id)->archived_at;

        $this->settings()->call('archiveBoard');

        $this->assertEquals($first, Board::query()->findOrFail($this->board->id)->archived_at);
    }

    /* The archive ------------------------------------------------------------------ */

    public function test_the_archive_lists_a_genuinely_archived_card_and_restores_it(): void
    {
        $card = Card::query()->where('title', 'Fix invoice PDF margins')->firstOrFail();
        app(CardService::class)->archive($card);

        $this->get('/projects/archive')
            ->assertOk()
            ->assertSee('Fix invoice PDF margins')
            ->assertSee('Client Work');

        Livewire::test('project::archive')
            ->call('restoreCard', $card->id)
            ->assertDispatched('toast');

        $restored = Card::query()->findOrFail($card->id);

        $this->assertNull($restored->archived_at);
        $this->assertSame($this->todo->id, $restored->board_list_id);

        // Back on the board, and gone from the archive.
        $this->get('/projects')->assertOk()->assertSee('Fix invoice PDF margins');
        $this->get('/projects/archive')->assertOk()->assertDontSee('Fix invoice PDF margins');
    }

    public function test_restoring_a_card_twice_is_harmless(): void
    {
        $card = Card::query()->where('title', 'Fix invoice PDF margins')->firstOrFail();
        app(CardService::class)->archive($card);

        Livewire::test('project::archive')
            ->call('restoreCard', $card->id)
            ->call('restoreCard', $card->id)
            ->assertDispatched('toast');

        $this->assertNull(Card::query()->findOrFail($card->id)->archived_at);
        $this->assertSame($this->todo->id, Card::query()->findOrFail($card->id)->board_list_id);
    }

    /**
     * The case the archive had to make a decision about.
     *
     * A card restored onto an archived list would land on a column the board
     * does not draw, and would have left the archive too — the one screen that
     * could undo it. The list therefore comes back with the card.
     */
    public function test_restoring_a_card_whose_list_is_archived_brings_the_list_back_too(): void
    {
        $this->settings()->call('archiveList', $this->todo->id);

        $card = Card::query()->where('title', 'Fix invoice PDF margins')->firstOrFail();

        $this->assertNotNull($card->archived_at);
        $this->assertNotNull(BoardList::query()->findOrFail($this->todo->id)->archived_at);

        Livewire::test('project::archive')->call('restoreCard', $card->id);

        $this->assertNull(Card::query()->findOrFail($card->id)->archived_at);
        $this->assertNull(BoardList::query()->findOrFail($this->todo->id)->archived_at, 'The list must come back with the card.');

        $this->get('/projects')->assertOk()->assertSee('Fix invoice PDF margins');
    }

    public function test_restoring_a_card_on_an_archived_board_brings_the_board_back_as_well(): void
    {
        $card = Card::query()->where('title', 'Fix invoice PDF margins')->firstOrFail();
        app(CardService::class)->archive($card);

        $this->settings()->call('archiveBoard');

        Livewire::test('project::archive')->call('restoreCard', $card->id);

        $this->assertNull(Board::query()->findOrFail($this->board->id)->archived_at);
        $this->assertNull(Card::query()->findOrFail($card->id)->archived_at);
    }

    public function test_an_archived_board_can_be_restored_from_the_archive(): void
    {
        $this->settings()->call('archiveBoard');

        $this->get('/projects/archive')->assertOk()->assertSee('Client Work');

        Livewire::test('project::archive')
            ->call('restoreBoard', $this->board->id)
            ->assertDispatched('toast');

        $this->assertNull(Board::query()->findOrFail($this->board->id)->archived_at);
        $this->assertTrue(Board::query()->active()->pluck('slug')->contains('client-work'));
    }

    public function test_the_archive_filters_by_kind_and_by_search(): void
    {
        app(CardService::class)->archive(
            Card::query()->where('title', 'Fix invoice PDF margins')->firstOrFail(),
        );
        $this->settings()->call('archiveList', $this->backlog->id);

        Livewire::test('project::archive')
            ->assertSee('Fix invoice PDF margins')
            ->assertSee('Backlog')
            ->call('setKind', 'lists')
            ->assertSee('Backlog')
            ->assertDontSee('Fix invoice PDF margins')
            ->call('setKind', 'all')
            ->set('search', 'Backlog')
            ->assertSee('Backlog')
            ->assertDontSee('Fix invoice PDF margins');
    }

    public function test_deleting_from_the_archive_is_a_soft_delete(): void
    {
        $card = Card::query()->where('title', 'Fix invoice PDF margins')->firstOrFail();
        app(CardService::class)->archive($card);

        Livewire::test('project::archive')
            ->call('deleteCard', $card->id)
            ->assertDispatched('toast');

        $this->assertNull(Card::query()->find($card->id));
        $this->assertNotNull(Card::withTrashed()->find($card->id), 'The row must still be there, marked deleted.');
        $this->assertDatabaseHas('cards', ['id' => $card->id]);
    }

    /* The rule this whole pass exists to satisfy ---------------------------------- */

    public function test_neither_page_still_says_it_is_not_connected(): void
    {
        $components = [
            base_path('Modules/Project/resources/views/components/⚡archive.blade.php'),
            base_path('Modules/Project/resources/views/components/⚡board-settings.blade.php'),
        ];

        foreach ($components as $path) {
            $this->assertFileExists($path);
            $this->assertStringNotContainsString('Not connected yet', file_get_contents($path), $path);
        }
    }
}
