<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Modules\Data\Contracts\AttachmentService;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Services\CardService;
use Modules\Project\Support\Palette;
use Modules\Project\Support\Position;
use Tests\TestCase;

/**
 * Card covers — a colour band or a picture from one of the card's own
 * attachments, half or full height. A full cover is the one behavioural
 * rule worth being blunt about: it hides the card front's badges, which is
 * asserted against `Card::coverHidesBadges()` directly, since drawing the
 * card front itself belongs to `⚡boards.blade.php`, not this file.
 */
class CardCoverTest extends TestCase
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

    /* Colour covers ------------------------------------------------------------ */

    public function test_a_colour_cover_renders(): void
    {
        $colour = Palette::keys()[0];

        $this->drawer()
            ->call('setCoverColour', $colour)
            ->assertDispatched('card-changed')
            // Picking a colour changes the drawer in front of the person
            // doing it — it does not also toast.
            ->assertNotDispatched('toast');

        $fresh = Card::query()->find($this->card->id);

        $this->assertSame('colour', $fresh->cover_type);
        $this->assertSame($colour, $fresh->cover_colour);
        $this->assertNull($fresh->cover_attachment_id);
        $this->assertSame(
            ['type' => 'colour', 'size' => 'half', 'colour' => $colour, 'url' => null],
            $fresh->coverPresentation(),
        );
    }

    public function test_an_unknown_colour_is_refused(): void
    {
        $this->drawer()
            ->call('setCoverColour', 'not-a-real-colour')
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'error');

        $this->assertNull(Card::query()->find($this->card->id)->cover_type);
    }

    public function test_setting_the_same_colour_twice_writes_nothing(): void
    {
        $colour = Palette::keys()[0];

        $this->drawer()->call('setCoverColour', $colour);
        $before = Card::query()->find($this->card->id)->updated_at;

        $this->travelTo(now()->addMinutes(5));

        $this->drawer()->call('setCoverColour', $colour);
        $after = Card::query()->find($this->card->id)->updated_at;

        $this->assertTrue($before->eq($after), 'Setting the same cover twice moved updated_at, so it wrote something.');
    }

    /* Image covers --------------------------------------------------------------- */

    public function test_an_image_cover_renders_from_an_attachment(): void
    {
        $stored = app(AttachmentService::class)->attachContents($this->card, 'x', 'hero-shot.jpg', 'image/jpeg');

        $this->drawer()
            ->call('setCoverImage', $stored['id'])
            ->assertDispatched('card-changed')
            ->assertNotDispatched('toast');

        $fresh = Card::query()->find($this->card->id);

        $this->assertSame('image', $fresh->cover_type);
        $this->assertSame($stored['id'], $fresh->cover_attachment_id);
        // `inline_url`, deliberately. `download_url` carries
        // `Content-Disposition: attachment`, which asks the browser to save a
        // picture the card only wants to show.
        $this->assertSame(
            ['type' => 'image', 'size' => 'half', 'colour' => null, 'url' => $stored['inline_url']],
            $fresh->coverPresentation(),
        );

        $this->assertStringEndsWith('/inline', $stored['inline_url']);
    }

    public function test_a_cover_cannot_be_set_from_another_cards_attachment(): void
    {
        $other = app(CardService::class)->append(
            BoardList::factory()->for(Board::factory()->create())->create(),
            'A different card',
        );
        $stored = app(AttachmentService::class)->attachContents($other, 'x', 'not-yours.jpg', 'image/jpeg');

        $this->drawer()
            ->call('setCoverImage', $stored['id'])
            ->assertDispatched('toast', fn (string $event, array $params): bool => $params[0]['type'] === 'error');

        $this->assertNull(Card::query()->find($this->card->id)->cover_type);
    }

    /**
     * The case this task had to decide: a cover pointing at an attachment
     * that no longer exists must not make the card unrenderable.
     *
     * The decision is "fall back to no cover" — `coverPresentation()`
     * resolves the attachment id through the service on every read and
     * reports null when the row is gone, rather than the drawer or the
     * board card front trying to draw a picture that is not there. The
     * stored `cover_type`/`cover_attachment_id` are deliberately left as
     * they were: nothing here rewrites them, so a restored attachment (from
     * the archive) would make the cover reappear on its own — see
     * `CardAttachmentTest` for the other half of this decision, where
     * deleting the attachment *through this drawer* does clear the columns,
     * because that path already holds the card and knows it was the cover.
     */
    public function test_deleting_the_attachment_behind_a_cover_leaves_the_card_renderable(): void
    {
        $stored = app(AttachmentService::class)->attachContents($this->card, 'x', 'hero-shot.jpg', 'image/jpeg');

        $this->drawer()->call('setCoverImage', $stored['id'])->call('toggleCoverSize');

        // Deleted from outside this drawer entirely — through the service
        // directly, the same as Data's own Files page would.
        app(AttachmentService::class)->delete($stored['id']);

        $fresh = Card::query()->find($this->card->id);

        $this->assertSame('image', $fresh->cover_type, 'The stale pointer is left in place, not silently rewritten.');
        $this->assertNull($fresh->coverPresentation(), 'A missing attachment resolves to no cover, not a broken one.');
        $this->assertFalse($fresh->coverHidesBadges(), 'Badges are not hidden for a cover that cannot actually render.');

        // The card stays renderable: opening the drawer does not blow up.
        Livewire::test('project::card-detail')
            ->call('openCard', $this->card->id)
            ->assertSet('open', true)
            ->assertSee('Rewrite portfolio landing copy');
    }

    /* Half, full, and the badge rule ---------------------------------------------- */

    public function test_half_and_full_covers_differ(): void
    {
        $colour = Palette::keys()[0];

        $component = $this->drawer()->call('setCoverColour', $colour);
        $this->assertSame('half', Card::query()->find($this->card->id)->coverPresentation()['size']);

        $component->call('toggleCoverSize')->assertDispatched('card-changed');
        $this->assertSame('full', Card::query()->find($this->card->id)->coverPresentation()['size']);

        $component->call('toggleCoverSize');
        $this->assertSame('half', Card::query()->find($this->card->id)->coverPresentation()['size']);
    }

    public function test_a_full_cover_suppresses_the_badges_and_removing_it_restores_them(): void
    {
        $colour = Palette::keys()[0];

        $this->drawer()->call('setCoverColour', $colour)->call('toggleCoverSize');

        $fresh = Card::query()->find($this->card->id);
        $this->assertSame('full', $fresh->cover_size);
        $this->assertTrue($fresh->coverHidesBadges());

        $this->drawer()->call('removeCover')->assertDispatched('card-changed');

        $restored = Card::query()->find($this->card->id);
        $this->assertNull($restored->cover_type);
        $this->assertFalse($restored->coverHidesBadges());
        $this->assertNull($restored->coverPresentation());
    }

    public function test_a_half_cover_never_hides_the_badges(): void
    {
        $this->drawer()->call('setCoverColour', Palette::keys()[0]);

        $this->assertFalse(Card::query()->find($this->card->id)->coverHidesBadges());
    }

    public function test_removing_a_cover_that_is_not_set_writes_nothing(): void
    {
        $before = Card::query()->find($this->card->id)->updated_at;

        $this->travelTo(now()->addMinutes(5));
        $this->drawer()->call('removeCover');

        $after = Card::query()->find($this->card->id)->updated_at;
        $this->assertTrue($before->eq($after));
    }
}
