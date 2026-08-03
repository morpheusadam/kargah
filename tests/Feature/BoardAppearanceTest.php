<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Data\Models\Attachment;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Label;
use Modules\Project\Support\Palette;
use Tests\TestCase;

/**
 * The board's Trello-style chrome: label colours, board backgrounds, the
 * colour-blind pattern toggle, and list header colour.
 *
 * `⚡boards.blade.php` belongs to another agent and draws none of this yet —
 * see the final report for the exact markup it needs. Everything here proves
 * the data, `Palette`, the model helpers, and the settings page that writes
 * them, which is as far as this file's owner can verify without touching a
 * file it does not own.
 */
class BoardAppearanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create(['name' => 'Nima Fazlipour']);
        $this->actingAs($this->user);

        $this->board = Board::factory()->create([
            'name' => 'Client Work',
            'slug' => 'client-work',
            'position' => 1,
        ]);
    }

    private function settings(): Testable
    {
        return Livewire::test('project::board-settings', ['board' => 'client-work']);
    }

    /* The label palette --------------------------------------------------------- */

    public function test_the_palette_carries_trellos_ten_label_colours_in_trellos_order(): void
    {
        $this->assertSame(
            ['green', 'yellow', 'orange', 'red', 'purple', 'blue', 'sky', 'lime', 'pink', 'black'],
            Palette::labelColours(),
        );

        foreach (Palette::labelColours() as $key) {
            $this->assertTrue(Palette::has($key), $key.' must resolve through Palette::has()');
            $this->assertNotSame('', Palette::chip($key));
            $this->assertNotSame('', Palette::dot($key));
            $this->assertNotSame('', Palette::tone($key));
        }

        // Boards, lists and due-date badges keep their semantic vocabulary —
        // this is "alongside", not "instead of".
        foreach (['primary', 'success', 'info', 'warning', 'destructive', 'neutral'] as $semantic) {
            $this->assertTrue(Palette::has($semantic));
        }
    }

    /**
     * Every class string a label colour, a gradient or a pattern resolves to
     * must appear **verbatim** in `Palette.php`'s own source — the property
     * that distinguishes a literal from `"bg-{$colour}"`. If any of these were
     * built by concatenation, the exact runtime string would not exist as one
     * contiguous substring in the file that produced it.
     */
    public function test_no_palette_class_string_is_built_by_concatenation(): void
    {
        $source = file_get_contents(base_path('Modules/Project/app/Support/Palette.php'));
        $this->assertNotFalse($source);

        foreach (Palette::labelColours() as $key) {
            $this->assertStringContainsString(Palette::chip($key), $source, "chip($key) is not a literal in Palette.php");
            $this->assertStringContainsString(Palette::dot($key), $source, "dot($key) is not a literal in Palette.php");
            $this->assertStringContainsString(Palette::tone($key), $source, "tone($key) is not a literal in Palette.php");

            $pattern = Palette::pattern($key);
            $this->assertNotSame('', $pattern, "$key must have a colour-blind pattern");
            $this->assertStringContainsString($pattern, $source, "pattern($key) is not a literal in Palette.php");
        }

        foreach (array_keys(Palette::gradients()) as $key) {
            $this->assertStringContainsString(Palette::gradientClass($key), $source, "gradientClass($key) is not a literal in Palette.php");
        }
    }

    /**
     * 🔴 The check that would have caught the pink badge shipping unstyled: a
     * literal PHP string is not the same guarantee as a compiled utility.
     * Every individual Tailwind token these additions introduce must be
     * present in the rebuilt `public/assets/css/kargah.css` — proof the
     * `npx @tailwindcss/cli` rebuild actually ran and actually picked them up.
     */
    public function test_every_class_the_palette_additions_introduce_is_in_the_compiled_stylesheet(): void
    {
        $css = file_get_contents(public_path('assets/css/kargah.css'));
        $this->assertNotFalse($css, 'public/assets/css/kargah.css must exist — run the rebuild command.');

        foreach (Palette::labelColours() as $key) {
            $this->assertClassCompiled($css, Palette::chip($key));
            $this->assertClassCompiled($css, Palette::dot($key));
            $this->assertClassCompiled($css, Palette::pattern($key));
        }

        foreach (Palette::gradients() as $gradient) {
            $this->assertClassCompiled($css, $gradient['class']);
        }

        $this->assertClassCompiled($css, Palette::textTone('light'));
        $this->assertClassCompiled($css, Palette::textTone('dark'));

        // Not a Palette entry, but the other whole-class-string surface this
        // task added — the board canvas's translucent list-column treatment.
        $this->assertClassCompiled($css, $this->board->canvasSurfaceClass());
        $this->board->forceFill(['background_type' => 'colour', 'background_key' => 'green', 'background_text_tone' => 'light'])->save();
        $this->assertClassCompiled($css, $this->board->fresh()->canvasSurfaceClass());
        $this->board->forceFill(['background_text_tone' => 'dark'])->save();
        $this->assertClassCompiled($css, $this->board->fresh()->canvasSurfaceClass());
    }

    /**
     * Every individual utility token in `$wholeClassString` must be present in
     * the compiled sheet. Tailwind's minifier escapes punctuation in the
     * selector (`.bg-green-500\/15`), so this matches each character with an
     * optional leading backslash rather than the raw substring.
     */
    private function assertClassCompiled(string $css, string $wholeClassString): void
    {
        foreach (preg_split('/\s+/', trim($wholeClassString)) as $token) {
            if ($token === '') {
                continue;
            }

            $pattern = '/'.implode('', array_map(
                fn (string $char): string => '\\\\?'.preg_quote($char, '/'),
                mb_str_split($token),
            )).'/';

            $this->assertMatchesRegularExpression(
                $pattern,
                $css,
                'Expected "'.$token.'" (from "'.$wholeClassString.'") in the compiled kargah.css. Rebuild it.',
            );
        }
    }

    /* Migrating existing labels -------------------------------------------------- */

    /**
     * `2026_08_05_000001_add_board_backgrounds_and_label_colours.php` remaps
     * every existing label from a semantic key to the nearest Trello colour,
     * and reverses it without losing which colour a label was — exercised
     * against the real migration file, not a re-implementation of its map.
     */
    public function test_the_migration_remaps_label_colours_and_reverses_without_losing_them(): void
    {
        $bug = Label::factory()->for($this->board)->create(['name' => 'Bug', 'colour' => 'destructive']);
        $research = Label::factory()->for($this->board)->create(['name' => 'Research', 'colour' => 'success']);
        $urgent = Label::factory()->for($this->board)->create(['name' => 'Urgent', 'colour' => 'pink']);

        $migration = $this->migrationInstance();

        // Roll the schema back to "before this migration ran" and put the
        // three labels back on the semantic keys it would have found them on.
        $migration->down();

        DB::table('labels')->where('id', $bug->id)->update(['colour' => 'destructive']);
        DB::table('labels')->where('id', $research->id)->update(['colour' => 'success']);
        DB::table('labels')->where('id', $urgent->id)->update(['colour' => 'pink']);

        $this->assertFalse(Schema::hasColumn('boards', 'background_type'));
        $this->assertFalse(Schema::hasColumn('board_lists', 'colour'));

        $migration->up();

        $this->assertTrue(Schema::hasColumn('boards', 'background_type'));
        $this->assertTrue(Schema::hasColumn('board_lists', 'colour'));
        $this->assertSame('red', DB::table('labels')->where('id', $bug->id)->value('colour'));
        $this->assertSame('green', DB::table('labels')->where('id', $research->id)->value('colour'));
        $this->assertSame('pink', DB::table('labels')->where('id', $urgent->id)->value('colour'));

        $migration->down();

        $this->assertSame('destructive', DB::table('labels')->where('id', $bug->id)->value('colour'));
        $this->assertSame('success', DB::table('labels')->where('id', $research->id)->value('colour'));
        $this->assertSame('pink', DB::table('labels')->where('id', $urgent->id)->value('colour'));

        $migration->up();
    }

    private function migrationInstance(): object
    {
        return require base_path('Modules/Project/database/migrations/2026_08_05_000001_add_board_backgrounds_and_label_colours.php');
    }

    /* Board backgrounds ----------------------------------------------------------- */

    public function test_a_fresh_board_has_no_background_chosen_yet(): void
    {
        $this->assertSame('colour', $this->board->background_type);
        $this->assertNull($this->board->background_key);
        $this->assertSame('', $this->board->backgroundClass());
        $this->assertNull($this->board->backgroundStyle());
        $this->assertSame('bg-muted/40', $this->board->canvasSurfaceClass(), 'Nothing chosen yet keeps the canvas default surface.');
    }

    /**
     * The list-column surface the board canvas needs — see the report's
     * hand-off for how `⚡boards.blade.php` is expected to use this.
     */
    public function test_the_canvas_surface_turns_translucent_once_a_background_is_chosen(): void
    {
        $this->board->forceFill(['background_type' => 'colour', 'background_key' => 'red', 'background_text_tone' => 'light'])->save();
        $this->assertSame('bg-black/30 backdrop-blur-sm', $this->board->fresh()->canvasSurfaceClass());

        $this->board->forceFill(['background_text_tone' => 'dark'])->save();
        $this->assertSame('bg-white/80 backdrop-blur-sm', $this->board->fresh()->canvasSurfaceClass());
    }

    public function test_a_solid_colour_background_persists_and_renders(): void
    {
        $this->settings()
            ->call('selectBackgroundColour', 'ocean' /* not a label colour */)
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'error');

        $this->settings()
            ->call('selectBackgroundColour', 'green')
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'success')
            ->assertSet('backgroundType', 'colour')
            ->assertSet('backgroundKey', 'green');

        $board = $this->board->fresh();

        $this->assertSame('colour', $board->background_type);
        $this->assertSame('green', $board->background_key);
        $this->assertSame(Palette::dot('green'), $board->backgroundClass());
        $this->assertNull($board->backgroundStyle());

        $this->get('/projects/client-work/settings')->assertOk()->assertSee(Palette::dot('green'), false);
    }

    public function test_a_gradient_background_persists_and_renders(): void
    {
        $this->settings()
            ->call('selectBackgroundGradient', 'sunset')
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'success')
            ->assertSet('backgroundType', 'gradient')
            ->assertSet('backgroundKey', 'sunset');

        $board = $this->board->fresh();

        $this->assertSame('gradient', $board->background_type);
        $this->assertSame(Palette::gradientClass('sunset'), $board->backgroundClass());
        $this->assertSame('light', $board->background_text_tone, 'Sunset recommends light text.');
    }

    public function test_a_photo_background_is_stored_through_the_attachment_contract(): void
    {
        $file = UploadedFile::fake()->image('harbour-view.jpg', 800, 400);

        $this->settings()
            ->set('backgroundUpload', $file)
            ->call('uploadBackgroundPhoto')
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'success')
            ->assertSet('backgroundType', 'photo');

        $board = $this->board->fresh();

        $this->assertSame('photo', $board->background_type);
        $this->assertNull($board->background_key);
        $this->assertNotNull($board->background_attachment_id);

        // Stored as an `attachments` row against the board, through the
        // contract — never a file `Modules\Project` wrote itself.
        $this->assertDatabaseHas('attachments', [
            'id' => $board->background_attachment_id,
            'attachable_type' => 'board',
            'attachable_id' => $board->id,
            'original_name' => 'harbour-view.jpg',
        ]);

        $stored = Attachment::query()->findOrFail($board->background_attachment_id);
        Storage::disk($stored->disk)->assertExists($stored->path);

        $photo = $board->backgroundPhoto();
        $this->assertNotNull($photo);
        $this->assertSame('harbour-view.jpg', $photo['name']);

        // `inline_url`, never `download_url`: the latter sends
        // `Content-Disposition: attachment`, which would make every card on
        // the board trigger a file download instead of showing a picture.
        $style = (string) $board->backgroundStyle();
        $this->assertStringContainsString('background-image:url', $style);
        $this->assertStringContainsString(route('data.file-inline', ['attachment' => $board->background_attachment_id]), $style);
        $this->assertStringNotContainsString(route('data.file-download', ['attachment' => $board->background_attachment_id]), $style);
    }

    /** The one place a Project file could quietly start opening its own file handle. */
    public function test_the_settings_component_never_touches_storage_directly(): void
    {
        $source = file_get_contents(base_path('Modules/Project/resources/views/components/⚡board-settings.blade.php'));
        $this->assertNotFalse($source);
        $this->assertStringNotContainsString('Storage::', $source);
        $this->assertStringContainsString('AttachmentService::class', $source);
    }

    public function test_deleting_the_background_photo_leaves_the_board_renderable(): void
    {
        $file = UploadedFile::fake()->image('old-office.jpg');

        $this->settings()->set('backgroundUpload', $file)->call('uploadBackgroundPhoto');

        $board = $this->board->fresh();
        $attachmentId = $board->background_attachment_id;
        $this->assertNotNull($attachmentId);

        $this->settings()
            ->call('removeBackgroundPhoto')
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'success');

        $board = $board->fresh();

        $this->assertSame('colour', $board->background_type);
        $this->assertNull($board->background_key);
        $this->assertNull($board->background_attachment_id);
        $this->assertNull($board->backgroundPhoto());
        $this->assertNull($board->backgroundStyle());
        $this->assertSame('', $board->backgroundClass());

        $this->assertSoftDeleted('attachments', ['id' => $attachmentId]);

        // The board is not merely internally consistent — the page still renders.
        $this->get('/projects/client-work/settings')->assertOk()->assertSee('Client Work');
    }

    public function test_the_text_tone_toggle_changes_what_the_settings_page_reports(): void
    {
        $this->settings()->call('selectBackgroundGradient', 'citrus'); // defaults to dark

        $this->assertSame('dark', $this->board->fresh()->background_text_tone);

        $this->settings()
            ->assertSet('backgroundTextTone', 'dark')
            ->call('setBackgroundTextTone', 'light')
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'success')
            ->assertSet('backgroundTextTone', 'light');

        $this->assertSame('light', $this->board->fresh()->background_text_tone);
        $this->assertSame(Palette::textTone('light'), $this->board->fresh()->textToneClass());
    }

    /* Colour-blind mode ------------------------------------------------------------ */

    public function test_colour_blind_mode_changes_the_rendered_class_and_survives_a_reload(): void
    {
        Label::factory()->for($this->board)->create(['name' => 'Bug', 'colour' => 'red']);

        $this->settings()->assertDontSee(Palette::pattern('red'), false);

        $this->settings()
            ->call('toggleColourBlindMode')
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'success')
            ->assertSet('colourBlindMode', true)
            ->assertSee(Palette::pattern('red'), false);

        $this->assertTrue((bool) $this->user->fresh()->colour_blind_mode);

        // A fresh component instance — the same thing a page reload does —
        // reads the preference back from the database rather than losing it.
        Livewire::test('project::board-settings', ['board' => 'client-work'])
            ->assertSet('colourBlindMode', true)
            ->assertSee(Palette::pattern('red'), false);
    }

    /* List colour -------------------------------------------------------------- */

    public function test_a_list_carries_no_colour_by_default(): void
    {
        $list = BoardList::factory()->for($this->board)->create();

        $this->assertNull($list->colour);
        $this->assertNull($list->headerColourClass());
    }

    public function test_a_list_colour_persists(): void
    {
        $list = BoardList::factory()->for($this->board)->create(['name' => 'Backlog']);

        $this->settings()
            ->call('selectListColour', $list->id, 'success')
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'success');

        $fresh = $list->fresh();
        $this->assertSame('success', $fresh->colour);
        $this->assertSame(Palette::tone('success'), $fresh->headerColourClass());

        $this->settings()->call('selectListColour', $list->id, 'none');

        $this->assertNull($list->fresh()->colour);
    }

    public function test_an_invalid_list_colour_is_refused(): void
    {
        $list = BoardList::factory()->for($this->board)->create();

        $this->settings()->call('selectListColour', $list->id, 'invisible-ink');

        $this->assertNull($list->fresh()->colour);
    }
}
