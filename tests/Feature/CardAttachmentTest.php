<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Data\Contracts\AttachmentService;
use Modules\Data\Models\Attachment;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Services\CardService;
use Modules\Project\Support\Position;
use Tests\TestCase;

/**
 * Card attachments, wired to `Modules\Data\Contracts\AttachmentService` —
 * the "Not connected yet" toast this replaces was the last stub in the
 * application.
 *
 * `removeAttachment(string $name)` is gone; the drawer now calls
 * `uploadAttachment()` and `deleteAttachment(int $id)` against real rows, and
 * everything here reads them back from the `attachments` table Data owns —
 * never `Modules\Data\Models\Attachment` from inside the drawer itself.
 */
class CardAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Card $card;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create(['name' => 'Nima Fazlipour']);
        $this->actingAs($this->user);

        $board = Board::factory()->create(['name' => 'Client Work', 'slug' => 'client-work', 'position' => 1]);
        $list = BoardList::factory()->for($board)->create(['name' => 'Backlog', 'position' => Position::format('1024')]);

        $this->card = app(CardService::class)->append($list, 'Rewrite portfolio landing copy');
    }

    private function drawer(): Testable
    {
        return Livewire::test('project::card-detail')->call('openCard', $this->card->id);
    }

    /* Upload ----------------------------------------------------------------- */

    public function test_a_file_attaches_to_a_card_through_the_contract_and_appears_in_the_drawer(): void
    {
        $file = UploadedFile::fake()->image('brief-cover.jpg', 200, 200);

        $this->drawer()
            ->set('uploads', [$file])
            ->call('uploadAttachment')
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'success')
            ->assertSet('uploads', []);

        $this->assertDatabaseHas('attachments', [
            'attachable_type' => 'card',
            'attachable_id' => $this->card->id,
            'original_name' => 'brief-cover.jpg',
        ]);

        Livewire::test('project::card-detail')
            ->call('openCard', $this->card->id)
            ->assertSee('brief-cover.jpg');
    }

    public function test_uploading_with_nothing_chosen_is_refused_rather_than_a_silent_no_op(): void
    {
        $this->drawer()
            ->call('uploadAttachment')
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'warning');

        $this->assertSame(0, Attachment::query()->count());
    }

    /**
     * A genuinely 30 MB file — real bytes on disk, not a reported size —
     * because `->size()` only changes what `getSize()` claims and Livewire's
     * upload test helper re-derives the size from the actual temp file.
     *
     * It never reaches this component's own `max:25600` rule: Livewire's
     * global `temporary_file_upload.rules` default (`max:12288`, 12 MB — see
     * `config/livewire.php`, which this task does not own) validates a file
     * the moment it is queued, before `uploadAttachment()` runs at all. That
     * makes this component's stated 25 MB ceiling dead for anything between
     * 12 and 25 MB — worth flagging, and true of `⚡files.blade.php`'s
     * identical `max:25600` too, not something this task introduced. What
     * *is* true, and what this test actually holds to, is the outward
     * behaviour: an oversized file is refused with a real sentence, whichever
     * layer catches it.
     */
    public function test_an_oversized_file_is_refused_with_a_sentence(): void
    {
        $file = UploadedFile::fake()->create('big-mockup.jpg', 30000);

        $component = $this->drawer()
            ->set('uploads', [$file])
            ->call('uploadAttachment')
            ->assertHasErrors('uploads.0');

        $message = $component->instance()->getErrorBag()->first('uploads.0');
        $this->assertNotSame('', $message, 'The error is empty, not a sentence.');
        $this->assertStringContainsString('kilobytes', $message);

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_a_disallowed_file_type_is_refused_with_a_sentence(): void
    {
        $file = UploadedFile::fake()->create('installer.exe', 10);

        $this->drawer()
            ->set('uploads', [$file])
            ->call('uploadAttachment')
            ->assertHasErrors(['uploads.0' => 'mimes']);

        $this->assertSame(0, Attachment::query()->count());
    }

    /* Download ----------------------------------------------------------------- */

    public function test_an_attached_file_downloads_the_bytes_it_was_stored_with(): void
    {
        $stored = app(AttachmentService::class)->attachContents(
            $this->card,
            'The current page reads like a CV.',
            'scope-notes.md',
            'text/markdown',
        );

        $response = $this->get($stored['download_url']);

        $response->assertOk();
        $this->assertStringContainsString('scope-notes.md', $response->headers->get('content-disposition'));
        $this->assertSame('The current page reads like a CV.', $response->streamedContent());
    }

    /**
     * A browser will happily send a filename carrying a quote or a raw
     * newline. Symfony's header builder is what is trusted to make that safe
     * — this proves it actually does, on the exact path a card attachment's
     * name travels, rather than assuming the guarantee `AttachmentService`'s
     * own docblock describes.
     */
    public function test_a_hostile_filename_cannot_break_the_download_header(): void
    {
        $stored = app(AttachmentService::class)->attachContents(
            $this->card,
            'bytes',
            "notes\r\nX-Injected: yes\".md",
            'text/markdown',
        );

        $response = $this->get($stored['download_url']);
        $response->assertOk();

        $disposition = (string) $response->headers->get('content-disposition');

        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertNull($response->headers->get('X-Injected'), 'A crafted filename must not be able to add its own header.');
    }

    /* Delete ----------------------------------------------------------------- */

    public function test_deleting_an_attachment_soft_deletes_it_and_keeps_the_bytes(): void
    {
        $stored = app(AttachmentService::class)->attachContents($this->card, 'x', 'old-brief.pdf', 'application/pdf');

        $this->drawer()
            ->call('deleteAttachment', $stored['id'])
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'success');

        $this->assertSoftDeleted('attachments', ['id' => $stored['id']]);
        Storage::disk($stored['disk'])->assertExists($stored['path']);

        Livewire::test('project::card-detail')
            ->call('openCard', $this->card->id)
            ->assertDontSee('old-brief.pdf');
    }

    public function test_deleting_an_attachment_from_another_card_is_refused(): void
    {
        $other = app(CardService::class)->append(
            BoardList::factory()->for(Board::factory()->create())->create(),
            'A different card',
        );
        $stored = app(AttachmentService::class)->attachContents($other, 'x', 'not-yours.pdf', 'application/pdf');

        $this->drawer()
            ->call('deleteAttachment', $stored['id'])
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'error');

        $this->assertDatabaseHas('attachments', ['id' => $stored['id'], 'deleted_at' => null]);
    }

    public function test_deleting_an_attachment_already_gone_is_reported_rather_than_ignored(): void
    {
        $this->drawer()
            ->call('deleteAttachment', 999999)
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'error');
    }
}
