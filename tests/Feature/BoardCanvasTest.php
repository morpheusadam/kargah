<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Modules\Data\Contracts\AttachmentService;
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
 * The visual work three other agents built — board backgrounds, the
 * colour-blind label pattern, list header colours, and card covers — wired
 * into `⚡boards.blade.php`. Kept separate from `BoardsTest`, which owns the
 * board's interaction model, because this is about what the canvas draws
 * rather than what a click does.
 */
class BoardCanvasTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create(['name' => 'Nima Fazlipour']);
        $this->actingAs($this->user);
    }

    /**
     * The component's own markup, not the full page. `$this->get('/projects')`
     * pulls in the whole layout — sidebar, topbar, dark-mode toggle — and
     * those carry their own instances of ordinary substrings like
     * `bg-muted/40` or `1/2`, which is not what any assertion here is about.
     */
    private function boardHtml(string $slug): string
    {
        return html_entity_decode(
            Livewire::withQueryParams(['board' => $slug])->test('project::boards')->html(),
            ENT_QUOTES | ENT_HTML5,
        );
    }

    /* Board backgrounds ------------------------------------------------------- */

    public function test_a_colour_background_renders_its_class_and_dark_translucent_list_surface(): void
    {
        // 'green' defaults to a light text tone, so the list surface goes dark
        // translucent to sit against it.
        $board = Board::factory()->withColourBackground('green')->create(['slug' => 'green-board']);
        BoardList::factory()->for($board)->create(['name' => 'Backlog', 'position' => Position::format('1024')]);

        $html = $this->boardHtml('green-board');

        $this->assertStringContainsString(Palette::dot('green'), $html);
        // The exact composed class, not a loose "bg-muted/40 is absent
        // anywhere on the page" check — the nested board-templates modal
        // renders unrelated `bg-muted/40` chrome of its own.
        $this->assertStringContainsString('w-[290px] shrink-0 bg-black/30 backdrop-blur-sm', $html);
        // `truncate` sits between the two because a long list name would
        // otherwise push the card-count badge out of the column header.
        $this->assertStringContainsString('text-sm font-semibold truncate text-white', $html);
    }

    public function test_a_gradient_background_renders_its_whole_class_string_and_light_translucent_surface(): void
    {
        // 'citrus' is the one gradient with a dark text tone, so this also
        // covers the bg-white/80 branch of the list surface.
        $board = Board::factory()->withGradientBackground('citrus')->create(['slug' => 'citrus-board']);
        BoardList::factory()->for($board)->create(['name' => 'Backlog', 'position' => Position::format('1024')]);

        $html = $this->boardHtml('citrus-board');

        $this->assertStringContainsString(Palette::gradientClass('citrus'), $html);
        $this->assertStringContainsString('w-[290px] shrink-0 bg-white/80 backdrop-blur-sm', $html);
        $this->assertStringContainsString('text-sm font-semibold text-mono', $html);
    }

    public function test_a_photo_background_renders_an_inline_background_image_style(): void
    {
        $board = Board::factory()->create(['slug' => 'photo-board', 'background_text_tone' => 'light']);
        $stored = app(AttachmentService::class)->attachContents($board, 'x', 'canvas.jpg', 'image/jpeg');
        $board->forceFill(['background_type' => Board::BACKGROUND_PHOTO, 'background_attachment_id' => $stored['id']])->save();
        BoardList::factory()->for($board)->create(['name' => 'Backlog', 'position' => Position::format('1024')]);

        $html = $this->boardHtml('photo-board');

        $this->assertStringContainsString("background-image:url('".$stored['inline_url']."');background-size:cover;background-position:center;", $html);
        $this->assertStringContainsString('bg-black/30 backdrop-blur-sm', $html);
    }

    public function test_a_board_with_no_background_keeps_the_muted_surface_and_dark_text(): void
    {
        $board = Board::factory()->create(['slug' => 'plain-board']);
        BoardList::factory()->for($board)->create(['name' => 'Backlog', 'position' => Position::format('1024')]);

        $html = $this->boardHtml('plain-board');

        $this->assertStringContainsString('w-[290px] shrink-0 bg-muted/40', $html);
        $this->assertStringContainsString('text-sm font-semibold text-mono', $html);
        $this->assertStringNotContainsString('bg-black/30 backdrop-blur-sm', $html);
        $this->assertStringNotContainsString('bg-white/80 backdrop-blur-sm', $html);
    }

    /* List header colour -------------------------------------------------------- */

    public function test_a_list_with_a_header_colour_carries_the_whole_tone_class(): void
    {
        $board = Board::factory()->create(['slug' => 'coloured-list-board']);
        BoardList::factory()->for($board)->create(['name' => 'Backlog', 'position' => Position::format('1024'), 'colour' => 'success']);

        $html = $this->boardHtml('coloured-list-board');

        $this->assertStringContainsString(Palette::tone('success'), $html);
    }

    /* Colour-blind label pattern ------------------------------------------------- */

    public function test_a_label_chip_carries_its_pattern_with_colour_blind_mode_on(): void
    {
        $this->user->forceFill(['colour_blind_mode' => true])->save();

        $board = Board::factory()->create(['slug' => 'colour-blind-board']);
        $list = BoardList::factory()->for($board)->create(['name' => 'Backlog', 'position' => Position::format('1024')]);
        $label = Label::factory()->for($board)->colour('green')->create(['name' => 'Copy']);
        $card = app(CardService::class)->append($list, 'Draft the brief');
        $card->labels()->attach($label);

        $html = $this->boardHtml('colour-blind-board');

        $this->assertStringContainsString(Palette::pattern('green'), $html);
    }

    public function test_a_label_chip_carries_no_pattern_with_colour_blind_mode_off(): void
    {
        $board = Board::factory()->create(['slug' => 'no-colour-blind-board']);
        $list = BoardList::factory()->for($board)->create(['name' => 'Backlog', 'position' => Position::format('1024')]);
        $label = Label::factory()->for($board)->colour('green')->create(['name' => 'Copy']);
        $card = app(CardService::class)->append($list, 'Draft the brief');
        $card->labels()->attach($label);

        $html = $this->boardHtml('no-colour-blind-board');

        $this->assertStringNotContainsString(Palette::pattern('green'), $html);
    }

    /* Card covers ----------------------------------------------------------------- */

    public function test_a_colour_cover_renders_as_a_band(): void
    {
        $board = Board::factory()->create(['slug' => 'colour-cover-board']);
        $list = BoardList::factory()->for($board)->create(['name' => 'Backlog', 'position' => Position::format('1024')]);
        $card = app(CardService::class)->append($list, 'Send the proposal');
        $card->forceFill(['cover_type' => 'colour', 'cover_colour' => 'blue', 'cover_size' => 'half'])->save();

        $html = $this->boardHtml('colour-cover-board');

        $this->assertStringContainsString(Palette::tone('blue').' h-8', $html);
    }

    public function test_an_image_cover_renders_the_inline_url_and_not_the_download_url(): void
    {
        $board = Board::factory()->create(['slug' => 'image-cover-board']);
        $list = BoardList::factory()->for($board)->create(['name' => 'Backlog', 'position' => Position::format('1024')]);
        $card = app(CardService::class)->append($list, 'Send the proposal');
        $stored = app(AttachmentService::class)->attachContents($card, 'x', 'hero.jpg', 'image/jpeg');
        $card->forceFill(['cover_type' => 'image', 'cover_attachment_id' => $stored['id'], 'cover_size' => 'half'])->save();

        $html = $this->boardHtml('image-cover-board');

        $this->assertStringContainsString('<img src="'.$stored['inline_url'].'"', $html);
        $this->assertStringNotContainsString($stored['download_url'], $html);
    }

    private function cardWithBadges(BoardList $list, string $title): Card
    {
        $card = app(CardService::class)->append($list, $title);
        $card->forceFill(['due_on' => now()->addMonths(2)->toDateString()])->save();

        $checklist = Checklist::factory()->for($card)->create();
        ChecklistItem::factory()->for($checklist)->create(['is_done' => true]);
        ChecklistItem::factory()->for($checklist)->create(['is_done' => false]);

        CardComment::factory()->for($card)->create(['created_by' => $this->user->id]);

        return $card->fresh();
    }

    public function test_a_full_cover_suppresses_the_due_checklist_and_comment_badges(): void
    {
        $board = Board::factory()->create(['slug' => 'full-cover-board']);
        $list = BoardList::factory()->for($board)->create(['name' => 'Backlog', 'position' => Position::format('1024')]);
        $card = $this->cardWithBadges($list, 'Send the proposal');
        $card->forceFill(['cover_type' => 'colour', 'cover_colour' => 'blue', 'cover_size' => 'full'])->save();

        $html = $this->boardHtml('full-cover-board');

        $this->assertStringNotContainsString($card->due_on->format('M d'), $html);
        $this->assertStringNotContainsString('1/2', $html);
        $this->assertStringNotContainsString('ki-message-text-2', $html);
        // The cover itself still shows.
        $this->assertStringContainsString(Palette::tone('blue').' h-20', $html);
    }

    public function test_a_half_cover_leaves_the_badges_alone(): void
    {
        $board = Board::factory()->create(['slug' => 'half-cover-board']);
        $list = BoardList::factory()->for($board)->create(['name' => 'Backlog', 'position' => Position::format('1024')]);
        $card = $this->cardWithBadges($list, 'Send the proposal');
        $card->forceFill(['cover_type' => 'colour', 'cover_colour' => 'blue', 'cover_size' => 'half'])->save();

        $html = $this->boardHtml('half-cover-board');

        $this->assertStringContainsString($card->due_on->format('M d'), $html);
        $this->assertStringContainsString('1/2', $html);
        $this->assertStringContainsString('ki-message-text-2', $html);
    }

    /**
     * The case `coverHidesBadges()` exists for: a full cover whose attachment
     * has since been deleted must not leave the card with neither a picture
     * nor its badges.
     */
    public function test_a_full_cover_with_a_deleted_attachment_shows_the_badges_again(): void
    {
        $board = Board::factory()->create(['slug' => 'deleted-cover-board']);
        $list = BoardList::factory()->for($board)->create(['name' => 'Backlog', 'position' => Position::format('1024')]);
        $card = $this->cardWithBadges($list, 'Send the proposal');
        $stored = app(AttachmentService::class)->attachContents($card, 'x', 'hero.jpg', 'image/jpeg');
        $card->forceFill(['cover_type' => 'image', 'cover_attachment_id' => $stored['id'], 'cover_size' => 'full'])->save();

        app(AttachmentService::class)->delete($stored['id']);

        $this->assertFalse($card->fresh()->coverHidesBadges());

        $html = $this->boardHtml('deleted-cover-board');

        $this->assertStringContainsString($card->due_on->format('M d'), $html);
        $this->assertStringContainsString('1/2', $html);
        $this->assertStringContainsString('ki-message-text-2', $html);
    }

    /* View switcher ----------------------------------------------------------------- */

    public function test_the_switcher_links_to_the_other_three_views_carrying_the_board_slug(): void
    {
        $board = Board::factory()->create(['slug' => 'switcher-board']);
        BoardList::factory()->for($board)->create(['name' => 'Backlog', 'position' => Position::format('1024')]);

        $html = $this->boardHtml('switcher-board');

        $this->assertStringContainsString(route('projects.table', ['board' => 'switcher-board']), $html);
        $this->assertStringContainsString(route('projects.calendar', ['board' => 'switcher-board']), $html);
        $this->assertStringContainsString(route('projects.dashboard', ['board' => 'switcher-board']), $html);
    }
}
