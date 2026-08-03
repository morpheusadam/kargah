<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardComment;
use Modules\Project\Models\Checklist;
use Modules\Project\Models\ChecklistItem;
use Modules\Project\Models\Label;
use Modules\Project\Services\CardService;
use Modules\Project\Support\Palette;
use Modules\Project\Support\Position;
use Tests\TestCase;

/**
 * The card drawer and the board template picker, against the database.
 *
 * The drawer holds a card *id*, not a copy of the card, so every assertion
 * below is allowed to be blunt: do the row, the pivot and the child tables say
 * what the interaction claimed, and does a fresh component still see it.
 */
class CardDetailTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $colleague;

    private Board $board;

    private BoardList $backlog;

    private BoardList $todo;

    private Label $bug;

    private Label $outreach;

    private Card $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Nima Fazlipour']);
        $this->colleague = User::factory()->create(['name' => 'Sara Rahimi']);
        $this->actingAs($this->user);

        $this->board = Board::factory()->create(['name' => 'Client Work', 'slug' => 'client-work', 'position' => 1]);

        $this->backlog = BoardList::factory()->for($this->board)->create(['name' => 'Backlog', 'position' => Position::format('1024')]);
        $this->todo = BoardList::factory()->for($this->board)->create(['name' => 'To Do', 'position' => Position::format('2048')]);

        $this->bug = Label::factory()->for($this->board)->create(['name' => 'Bug', 'colour' => 'destructive']);
        $this->outreach = Label::factory()->for($this->board)->create(['name' => 'Outreach', 'colour' => 'success']);

        $this->card = app(CardService::class)->append($this->backlog, 'Rewrite portfolio landing copy');
        $this->card->update(['description' => 'The current page reads like a CV.']);
        $this->card->labels()->attach($this->bug);
        $this->card->members()->attach($this->colleague);

        $checklist = Checklist::query()->create([
            'card_id' => $this->card->id,
            'name' => 'Checklist',
            'position' => Position::after(null),
        ]);

        foreach (['Draft the hero paragraph', 'Proofread and publish'] as $index => $text) {
            ChecklistItem::query()->create([
                'checklist_id' => $checklist->id,
                'text' => $text,
                'is_done' => $index === 0,
                'position' => Position::spread(2)[$index],
                'created_by' => $this->user->id,
            ]);
        }

        CardComment::query()->create([
            'card_id' => $this->card->id,
            'created_by' => $this->colleague->id,
            'body' => 'The old headline still mentions WordPress.',
        ]);
    }

    /** A drawer already open on the card under test. */
    private function drawer(): Testable
    {
        return Livewire::test('project::card-detail')->call('openCard', $this->card->id);
    }

    /** A second, independent drawer — the test's stand-in for a page reload. */
    private function reopened(): Testable
    {
        return Livewire::test('project::card-detail')->call('openCard', $this->card->id);
    }

    private function itemNamed(string $text): ChecklistItem
    {
        return ChecklistItem::query()->where('text', $text)->firstOrFail();
    }

    /* Opening -------------------------------------------------------------- */

    public function test_opening_a_card_loads_the_row_and_not_a_fixture(): void
    {
        $this->drawer()
            ->assertSet('open', true)
            ->assertSet('cardId', $this->card->id)
            ->assertSet('title', 'Rewrite portfolio landing copy')
            ->assertSet('description', 'The current page reads like a CV.')
            ->assertViewHas('cardMemberIds', [$this->colleague->id])
            ->assertSee('Rewrite portfolio landing copy')
            ->assertSee('Backlog')
            ->assertSee('Client Work')
            ->assertSee('Bug')
            ->assertSee('Draft the hero paragraph')
            ->assertSee('The old headline still mentions WordPress.')
            ->assertSee('Sara Rahimi')
            ->assertViewHas('checklistTotal', 2)
            ->assertViewHas('checklistDone', 1);
    }

    public function test_renaming_the_card_in_the_database_changes_what_the_drawer_shows(): void
    {
        $this->card->update(['title' => 'Renamed In The Database']);

        $this->drawer()->assertSee('Renamed In The Database');
    }

    public function test_the_label_picker_offers_the_cards_own_board_and_nothing_else(): void
    {
        $other = Board::factory()->create(['name' => 'Outreach', 'slug' => 'outreach']);
        Label::factory()->for($other)->create(['name' => 'Belongs To Another Board', 'colour' => 'info']);

        $this->drawer()
            ->assertSee('Bug')
            ->assertSee('Outreach')
            ->assertDontSee('Belongs To Another Board');
    }

    public function test_a_card_that_was_deleted_underneath_the_drawer_is_reported(): void
    {
        $id = $this->card->id;
        $this->card->delete();

        Livewire::test('project::card-detail')
            ->call('openCard', $id)
            ->assertSet('open', false)
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'error');
    }

    /* Title and description ------------------------------------------------- */

    public function test_a_new_title_is_persisted_and_survives_a_reload(): void
    {
        $this->drawer()
            ->set('title', 'Rewrite the landing page copy')
            ->call('saveTitle')
            ->assertSet('editingTitle', false)
            ->assertDispatched('card-changed');

        $this->assertSame('Rewrite the landing page copy', Card::query()->find($this->card->id)->title);

        $this->reopened()->assertSet('title', 'Rewrite the landing page copy');
    }

    public function test_a_title_that_fails_validation_is_not_written(): void
    {
        $this->drawer()
            ->set('title', 'no')
            ->call('saveTitle')
            ->assertHasErrors(['title' => 'min']);

        $this->assertSame('Rewrite portfolio landing copy', Card::query()->find($this->card->id)->title);
    }

    public function test_a_new_description_is_persisted_and_survives_a_reload(): void
    {
        $this->drawer()
            ->set('description', "Rewrite it around the three services that sell.\n\n- retainers\n- audits")
            ->call('saveDescription')
            ->assertSet('editingDescription', false);

        $this->assertStringContainsString(
            '- audits',
            (string) Card::query()->find($this->card->id)->description,
        );

        $this->reopened()->assertSee('Rewrite it around the three services that sell.');
    }

    /**
     * The card face carries no description, so redrawing every card on the
     * board for one would be the cost islands exist to avoid.
     */
    public function test_saving_a_description_does_not_redraw_the_board(): void
    {
        $this->drawer()
            ->set('description', 'Something only the drawer shows.')
            ->call('saveDescription')
            ->assertNotDispatched('card-changed');
    }

    /**
     * The description is markdown rendered through `{!! !!}` now — the one
     * unescaped echo on this page. Planting an actual attack and reading the
     * actual response is what proves the sanitiser sits in front of it, rather
     * than trusting that it does.
     */
    public function test_a_script_tag_planted_in_the_description_never_reaches_the_page(): void
    {
        $this->drawer()
            ->set('description', "Ship it.\n\n<script>alert(1)</script>")
            ->call('saveDescription');

        $this->reopened()
            ->assertSee('Ship it.')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('alert(1)', false);
    }

    public function test_a_javascript_link_planted_in_the_description_never_reaches_the_page(): void
    {
        $this->drawer()
            ->set('description', 'Click [here](javascript:alert(1)) to continue.')
            ->call('saveDescription');

        $this->reopened()->assertDontSee('javascript:alert(1)', false);
    }

    public function test_a_script_tag_planted_in_a_comment_never_reaches_the_page(): void
    {
        $this->drawer()
            ->set('newComment', "Reviewed.\n\n<script>alert(1)</script>")
            ->call('addComment');

        $this->reopened()
            ->assertSee('Reviewed.')
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('alert(1)', false);
    }

    /* Labels ---------------------------------------------------------------- */

    public function test_a_label_is_attached_and_detached_on_the_pivot(): void
    {
        $this->drawer()
            ->call('toggleLabel', $this->outreach->id)
            ->assertDispatched('card-changed');

        $this->assertDatabaseHas('card_label', ['card_id' => $this->card->id, 'label_id' => $this->outreach->id]);
        $this->reopened()->assertSee('Outreach');

        $this->drawer()->call('toggleLabel', $this->outreach->id);

        $this->assertDatabaseMissing('card_label', ['card_id' => $this->card->id, 'label_id' => $this->outreach->id]);
    }

    public function test_a_label_from_another_board_is_refused(): void
    {
        $other = Board::factory()->create(['slug' => 'outreach']);
        $foreign = Label::factory()->for($other)->create(['name' => 'Foreign', 'colour' => 'info']);

        $this->drawer()
            ->call('toggleLabel', $foreign->id)
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'error');

        $this->assertDatabaseMissing('card_label', ['card_id' => $this->card->id, 'label_id' => $foreign->id]);
    }

    /* Due date --------------------------------------------------------------- */

    public function test_a_due_date_is_stored_as_a_date_and_survives_a_reload(): void
    {
        $this->drawer()
            ->set('dueDate', '2026-08-12')
            ->call('saveDueDate')
            ->assertSet('duePopoverOpen', false)
            ->assertDispatched('card-changed');

        $stored = Card::query()->find($this->card->id)->due_on;

        $this->assertSame('2026-08-12', $stored->toDateString());
        // A date, not an instant: nothing about the day is carried by a clock.
        $this->assertSame('00:00:00', $stored->format('H:i:s'));

        $this->reopened()->assertSet('dueDate', '2026-08-12');
    }

    public function test_clearing_the_due_date_empties_the_column(): void
    {
        $this->card->update(['due_on' => '2026-08-12']);

        $this->drawer()
            ->call('clearDueDate')
            ->assertSet('dueDate', '');

        $this->assertNull(Card::query()->find($this->card->id)->due_on);
        $this->reopened()->assertSet('dueDate', '');
    }

    public function test_saving_with_no_date_picked_is_refused_rather_than_stored(): void
    {
        $this->drawer()
            ->set('dueDate', '')
            ->call('saveDueDate')
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'error');

        $this->assertNull(Card::query()->find($this->card->id)->due_on);
    }

    /* Members ------------------------------------------------------------------ */

    public function test_toggling_a_member_on_adds_them_without_removing_anybody_else(): void
    {
        $this->drawer()
            ->call('toggleMember', $this->user->id)
            ->assertDispatched('card-changed');

        $members = Card::query()->find($this->card->id)->members->pluck('id')->sort()->values()->all();
        $expected = collect([$this->user->id, $this->colleague->id])->sort()->values()->all();

        $this->assertSame($expected, $members);
        $this->reopened()->assertViewHas('cardMemberIds', fn (array $ids): bool => in_array($this->user->id, $ids, true)
            && in_array($this->colleague->id, $ids, true));
    }

    public function test_toggling_a_member_off_removes_only_that_person(): void
    {
        $this->drawer()
            ->call('toggleMember', $this->colleague->id)
            ->assertDispatched('card-changed');

        $this->assertSame(0, Card::query()->find($this->card->id)->members()->count());
    }

    public function test_toggling_an_unknown_person_is_reported_rather_than_silently_ignored(): void
    {
        $this->drawer()
            ->call('toggleMember', 999999)
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'error');

        $this->assertSame([$this->colleague->id], Card::query()->find($this->card->id)->members->pluck('id')->all());
    }

    /* Checklist -------------------------------------------------------------- */

    public function test_ticking_an_item_twice_leaves_it_where_it_started(): void
    {
        $item = $this->itemNamed('Proofread and publish');

        $this->assertFalse($item->is_done);

        $this->drawer()->call('toggleChecklistItem', $item->id);

        $ticked = $this->itemNamed('Proofread and publish');
        $this->assertTrue($ticked->is_done);
        $this->assertNotNull($ticked->completed_at);

        $this->drawer()->call('toggleChecklistItem', $item->id);

        $back = $this->itemNamed('Proofread and publish');
        $this->assertFalse($back->is_done);
        $this->assertNull($back->completed_at);
    }

    public function test_a_new_item_is_appended_with_a_real_position(): void
    {
        $this->drawer()
            ->set('newChecklistItem', 'Publish and tell Sara')
            ->call('addChecklistItem')
            ->assertSet('newChecklistItem', '')
            ->assertDispatched('card-changed');

        $items = Card::query()->find($this->card->id)->checklists->flatMap->items;

        $this->assertSame(
            ['Draft the hero paragraph', 'Proofread and publish', 'Publish and tell Sara'],
            $items->pluck('text')->all(),
        );

        // Fractional ordering, not an integer sequence and not a float.
        $last = $items->last();
        $this->assertSame(Position::format((string) $last->position), (string) $last->position);

        $this->reopened()->assertSee('Publish and tell Sara');
    }

    public function test_a_card_with_no_checklist_gets_one_on_the_first_item(): void
    {
        $bare = app(CardService::class)->append($this->todo, 'Fix invoice PDF margins');

        $this->assertSame(0, $bare->checklists()->count());

        Livewire::test('project::card-detail')
            ->call('openCard', $bare->id)
            ->set('newChecklistItem', 'Reproduce it on A4')
            ->call('addChecklistItem');

        $this->assertSame(1, $bare->checklists()->count());
        $this->assertDatabaseHas('checklist_items', ['text' => 'Reproduce it on A4', 'is_done' => false]);
    }

    public function test_an_empty_checklist_item_is_refused(): void
    {
        $before = ChecklistItem::query()->count();

        $this->drawer()
            ->set('newChecklistItem', '   ')
            ->call('addChecklistItem')
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'error');

        $this->assertSame($before, ChecklistItem::query()->count());
    }

    public function test_deleting_an_item_removes_the_row(): void
    {
        $item = $this->itemNamed('Draft the hero paragraph');

        $this->drawer()->call('deleteChecklistItem', $item->id);

        $this->assertDatabaseMissing('checklist_items', ['id' => $item->id]);
        $this->reopened()->assertDontSee('Draft the hero paragraph');
    }

    public function test_an_item_on_another_card_cannot_be_touched_from_this_drawer(): void
    {
        $other = app(CardService::class)->append($this->todo, 'Fix invoice PDF margins');
        $checklist = Checklist::query()->create([
            'card_id' => $other->id,
            'name' => 'Checklist',
            'position' => Position::after(null),
        ]);
        $foreign = ChecklistItem::query()->create([
            'checklist_id' => $checklist->id,
            'text' => 'Belongs to another card',
            'position' => Position::after(null),
        ]);

        $this->drawer()->call('deleteChecklistItem', $foreign->id);

        $this->assertDatabaseHas('checklist_items', ['id' => $foreign->id]);
    }

    /* Comments ---------------------------------------------------------------- */

    public function test_a_comment_is_written_against_the_signed_in_user(): void
    {
        $this->drawer()
            ->set('newComment', 'Dropping the WordPress line in the rewrite.')
            ->call('addComment')
            ->assertSet('newComment', '')
            ->assertDispatched('card-changed');

        $this->assertDatabaseHas('card_comments', [
            'card_id' => $this->card->id,
            'created_by' => $this->user->id,
            'body' => 'Dropping the WordPress line in the rewrite.',
        ]);

        $this->reopened()
            ->assertSee('Dropping the WordPress line in the rewrite.')
            ->assertSee('Nima Fazlipour');
    }

    public function test_an_empty_comment_is_refused(): void
    {
        $before = CardComment::query()->count();

        $this->drawer()
            ->set('newComment', "  \n ")
            ->call('addComment')
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'error');

        $this->assertSame($before, CardComment::query()->count());
    }

    /* Right rail --------------------------------------------------------------- */

    public function test_the_drawer_moves_a_card_to_another_list_on_the_same_board(): void
    {
        $this->drawer()
            ->call('toggleMovePopover')
            ->set('moveToList', (string) $this->todo->id)
            ->call('moveCard')
            ->assertSet('movePopoverOpen', false)
            ->assertDispatched('card-changed');

        $this->assertSame($this->todo->id, Card::query()->find($this->card->id)->originPlacement->board_list_id);
        $this->get('/projects')->assertOk()->assertSee('Rewrite portfolio landing copy');
    }

    public function test_the_move_picker_offers_only_the_cards_own_board(): void
    {
        $other = Board::factory()->create(['slug' => 'outreach']);
        BoardList::factory()->for($other)->create(['name' => 'Belongs Elsewhere', 'position' => Position::format('1024')]);

        $this->drawer()
            ->call('toggleMovePopover')
            ->assertSee('To Do')
            ->assertDontSee('Belongs Elsewhere');
    }

    public function test_a_move_to_a_list_on_another_board_is_refused(): void
    {
        $other = Board::factory()->create(['slug' => 'outreach']);
        $foreign = BoardList::factory()->for($other)->create(['name' => 'Leads', 'position' => Position::format('1024')]);

        $this->drawer()
            ->set('moveToList', (string) $foreign->id)
            ->call('moveCard')
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'error');

        $this->assertSame($this->backlog->id, Card::query()->find($this->card->id)->originPlacement->board_list_id);
    }

    public function test_copying_a_card_duplicates_its_labels_and_its_checklist(): void
    {
        $component = $this->drawer()->call('copyCard');

        $copy = Card::query()->where('title', 'Rewrite portfolio landing copy (copy)')->firstOrFail();

        $this->assertSame($this->backlog->id, $copy->originPlacement->board_list_id);
        $this->assertTrue($copy->originPlacement->isOrigin(), 'A copy is a new card and lives where it was made.');
        $this->assertSame('The current page reads like a CV.', $copy->description);
        $this->assertSame(['Bug'], $copy->labels->pluck('name')->all());
        $this->assertSame(
            ['Draft the hero paragraph', 'Proofread and publish'],
            $copy->checklists->flatMap->items->pluck('text')->all(),
        );
        // The original keeps everything it had.
        $this->assertSame(2, ChecklistItem::query()->count() / 2);

        // The drawer follows the copy, so the next edit lands on it.
        $component->assertSet('cardId', $copy->id);
    }

    public function test_archiving_a_card_takes_it_off_the_board_without_deleting_it(): void
    {
        $this->drawer()
            ->call('archiveCard')
            ->assertSet('open', false)
            ->assertDispatched('card-changed');

        $this->assertNotNull(Card::query()->find($this->card->id)->archived_at);
        $this->assertDatabaseHas('cards', ['id' => $this->card->id, 'deleted_at' => null]);
        $this->get('/projects')->assertOk()->assertDontSee('Rewrite portfolio landing copy');
    }

    public function test_deleting_a_card_soft_deletes_it(): void
    {
        $this->drawer()
            ->call('deleteCard')
            ->assertSet('open', false)
            ->assertSet('cardId', null)
            ->assertDispatched('card-changed');

        $this->assertSoftDeleted('cards', ['id' => $this->card->id]);
        $this->get('/projects')->assertOk()->assertDontSee('Rewrite portfolio landing copy');
    }

    /* Board templates ------------------------------------------------------------ */

    public function test_creating_a_board_writes_the_lists_and_cards_the_template_promised(): void
    {
        Livewire::test('project::board-templates')
            ->call('openPicker')
            ->call('selectTemplate', 'client-project')
            ->set('name', 'Bluepeak booking widget')
            ->call('createBoard')
            ->assertRedirect(route('projects.boards', ['board' => 'bluepeak-booking-widget']));

        $board = Board::query()->where('slug', 'bluepeak-booking-widget')->firstOrFail();

        $this->assertSame('Bluepeak booking widget', $board->name);
        $this->assertSame($this->user->id, $board->created_by);

        $this->assertSame(
            ['Brief', 'Scope & quote', 'In progress', 'Client review', 'Invoiced', 'Done'],
            $board->lists()->pluck('name')->all(),
        );

        $brief = $board->lists()->where('name', 'Brief')->firstOrFail();

        $this->assertSame(
            ['Kick-off call notes', 'Success criteria'],
            $brief->cards()->pluck('title')->all(),
        );

        // Six lists, five cards across them — exactly what the preview promised.
        $this->assertSame(5, $board->cards()->count());
    }

    public function test_a_new_board_gets_a_default_label_set_from_the_palette(): void
    {
        Livewire::test('project::board-templates')
            ->set('name', 'Studio operations')
            ->call('createBoard');

        $board = Board::query()->where('slug', 'studio-operations')->firstOrFail();

        $this->assertSame(Palette::keys(), $board->labels()->pluck('colour')->all());
        $this->assertSame(count(Palette::keys()), $board->labels()->count());
    }

    public function test_the_blank_template_creates_a_board_with_no_lists(): void
    {
        Livewire::test('project::board-templates')
            ->call('selectTemplate', 'blank')
            ->set('name', 'Somewhere to think')
            ->call('createBoard');

        $board = Board::query()->where('slug', 'somewhere-to-think')->firstOrFail();

        $this->assertSame(0, $board->lists()->count());
        $this->assertSame(0, $board->cards()->count());
    }

    public function test_creating_the_same_board_twice_makes_two_boards_rather_than_colliding(): void
    {
        foreach ([1, 2] as $ignored) {
            Livewire::test('project::board-templates')
                ->call('selectTemplate', 'job-hunt')
                ->set('name', 'Job hunt')
                ->call('createBoard');
        }

        $boards = Board::query()->where('name', 'Job hunt')->orderBy('id')->get();

        $this->assertCount(2, $boards);
        $this->assertSame(['job-hunt', 'job-hunt-2'], $boards->pluck('slug')->all());
        $this->assertNotSame($boards[0]->id, $boards[1]->id);

        // Each got its own lists, cards and labels — not one shared set.
        foreach ($boards as $board) {
            $this->assertSame(5, $board->lists()->count());
            $this->assertSame(3, $board->cards()->count());
            $this->assertSame(count(Palette::keys()), $board->labels()->count());
        }
    }

    public function test_a_soft_deleted_board_still_holds_its_slug(): void
    {
        Livewire::test('project::board-templates')->set('name', 'Retainers')->call('createBoard');

        Board::query()->where('slug', 'retainers')->firstOrFail()->delete();

        Livewire::test('project::board-templates')->set('name', 'Retainers')->call('createBoard');

        $this->assertNotNull(Board::query()->where('slug', 'retainers-2')->first());
    }

    public function test_a_board_with_no_name_is_refused_rather_than_created(): void
    {
        $before = Board::query()->count();

        Livewire::test('project::board-templates')
            ->set('name', '')
            ->call('createBoard')
            ->assertHasErrors(['name' => 'required']);

        $this->assertSame($before, Board::query()->count());
    }

    /**
     * The toast has to be flashed rather than dispatched: `createBoard()`
     * redirects, and a browser event dies with the page that would show it.
     */
    public function test_the_creation_toast_survives_the_redirect(): void
    {
        Livewire::test('project::board-templates')
            ->set('name', 'Content pipeline')
            ->call('createBoard')
            ->assertNotDispatched('toast');

        $this->assertSame('success', session('toast')['type']);
        $this->assertSame('Content pipeline created', session('toast')['message']);
    }

    /* No fixtures left behind ------------------------------------------------------ */

    /**
     * A success toast on a method that writes nothing is worse than no method
     * at all. Attachments are the one honest exception — there is no table for
     * them until the Data module lands — and it reports as info, not success.
     */
    public function test_only_the_attachments_action_still_says_it_is_not_connected(): void
    {
        $drawer = file_get_contents(base_path('Modules/Project/resources/views/components/⚡card-detail.blade.php'));
        $templates = file_get_contents(base_path('Modules/Project/resources/views/components/⚡board-templates.blade.php'));

        $this->assertSame(1, substr_count($drawer, 'Not connected yet'), 'Only removeAttachment() may still say so.');
        $this->assertStringNotContainsString('Not connected yet', $templates);

        $this->assertStringContainsString(
            "toastInfo('Not connected yet', 'File attachments arrive with the Data module.')",
            $drawer,
        );
    }

    public function test_the_attachments_section_shows_its_empty_state_rather_than_invented_files(): void
    {
        $this->drawer()
            ->assertSee('Nothing can be attached yet.')
            ->assertSee('File attachments arrive with the Data module.')
            ->assertDontSee('landing-copy-v2.md');
    }

    /** Positions are decimal strings from `Position`, never a float literal. */
    public function test_neither_file_writes_a_raw_position(): void
    {
        foreach (['⚡card-detail', '⚡board-templates'] as $name) {
            $source = file_get_contents(base_path('Modules/Project/resources/views/components/'.$name.'.blade.php'));

            $this->assertDoesNotMatchRegularExpression(
                "/'position'\s*=>\s*[0-9]+\.[0-9]/",
                $source,
                $name.' writes a float into a position column.',
            );
        }
    }
}
