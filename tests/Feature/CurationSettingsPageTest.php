<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Social\Models\CurationFeed;
use Modules\Social\Models\CurationSetting;
use Modules\Social\Models\CurationWindow;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * The settings page, which is the reason the curator's configuration is in tables.
 *
 * The owner's instruction was that all of this be manageable from the settings
 * pages. A feed list only whoever wrote the PHP can change is not a feed list they
 * own, so these tests are about the page actually being the control — an edit made
 * here has to be the thing the next daily run reads.
 *
 * One test here is about correctness rather than convenience:
 * `test_the_story_cannot_be_chosen_after_a_window_has_opened`. Getting that wrong
 * produces a network that silently never receives a post, with nothing anywhere
 * saying so, which is the worst failure this feature can have.
 */
class CurationSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_the_master_switch_is_what_starts_and_stops_the_daily_post(): void
    {
        Livewire::test('social::curation-settings')
            ->set('isEnabled', true)
            ->call('save')
            ->assertHasNoErrors();

        // The command reads this row and nothing else, so the page and the cron
        // cannot disagree.
        $this->assertTrue(CurationSetting::current()->is_enabled);
    }

    public function test_the_story_cannot_be_chosen_after_a_window_has_opened(): void
    {
        CurationWindow::query()->create([
            'network' => Networks::LINKEDIN,
            'starts_at' => '08:00',   // 04:30 UTC
            'ends_at' => '11:30',
            'hashtags_min' => 2,
            'hashtags_max' => 3,
        ]);

        Livewire::test('social::curation-settings')
            ->set('timezone', 'Asia/Tehran')
            ->set('curateAtUtc', '06:00')
            ->call('save')
            // 🔴 Choosing the day's story at 06:00 UTC when LinkedIn's window
            // opened at 04:30 UTC means LinkedIn is never posted to, and nothing
            // in the application would ever say why.
            ->assertHasErrors('curateAtUtc');

        $this->assertSame('01:30', CurationSetting::current()->curate_at_utc);
    }

    public function test_an_earlier_time_than_every_window_is_accepted(): void
    {
        CurationWindow::query()->create([
            'network' => Networks::LINKEDIN,
            'starts_at' => '08:00',
            'ends_at' => '11:30',
            'hashtags_min' => 2,
            'hashtags_max' => 3,
        ]);

        Livewire::test('social::curation-settings')
            ->set('curateAtUtc', '01:30')
            ->call('save')
            ->assertHasNoErrors();
    }

    public function test_switching_an_outlet_off_is_what_stops_it_being_read(): void
    {
        $feed = CurationFeed::query()->create(['label' => 'TechRadar', 'url' => 'https://tr.test/feed']);

        Livewire::test('social::curation-settings')->call('toggleFeed', $feed->id);

        $this->assertFalse($feed->fresh()->is_active);
    }

    public function test_two_outlets_cannot_share_a_name(): void
    {
        CurationFeed::query()->create(['label' => 'Ars Technica', 'url' => 'https://ars.test/feed']);

        Livewire::test('social::curation-settings')
            ->call('newFeed')
            ->set('feedLabel', 'Ars Technica')
            ->set('feedUrl', 'https://ars.test/other')
            ->call('saveFeed')
            // The ranker counts *independent* outlets by this column. Two rows
            // sharing a name let one publisher count as two agreeing, which is the
            // one number the whole feature turns on.
            ->assertHasErrors('feedLabel');
    }

    public function test_an_outlet_added_here_is_read_on_the_next_run(): void
    {
        Livewire::test('social::curation-settings')
            ->call('newFeed')
            ->set('feedLabel', 'Zaman Tech')
            ->set('feedUrl', 'https://zaman.test/feed')
            ->set('feedAuthority', 0.8)
            ->call('saveFeed')
            ->assertHasNoErrors();

        $feed = CurationFeed::query()->where('label', 'Zaman Tech')->firstOrFail();

        $this->assertTrue($feed->is_active);
        $this->assertSame(0.8, $feed->authority);
    }

    public function test_a_posting_window_can_be_moved_from_the_page(): void
    {
        $window = CurationWindow::query()->create([
            'network' => Networks::INSTAGRAM,
            'starts_at' => '19:00',
            'ends_at' => '23:00',
            'hashtags_min' => 18,
            'hashtags_max' => 25,
        ]);

        Livewire::test('social::curation-settings')
            ->call('saveWindow', $window->id, 'starts_at', '20:30');

        $this->assertSame('20:30', $window->fresh()->starts_at);
    }

    public function test_a_time_typed_badly_is_refused_rather_than_stored(): void
    {
        $window = CurationWindow::query()->create([
            'network' => Networks::INSTAGRAM,
            'starts_at' => '19:00',
            'ends_at' => '23:00',
            'hashtags_min' => 18,
            'hashtags_max' => 25,
        ]);

        Livewire::test('social::curation-settings')
            ->call('saveWindow', $window->id, 'starts_at', 'half seven');

        // A stored value that is not a time would throw inside Carbon at one in
        // the morning, on a cron run, with nobody watching.
        $this->assertSame('19:00', $window->fresh()->starts_at);
    }

    public function test_the_hashtag_ceiling_cannot_be_pushed_past_the_platform_limit(): void
    {
        $window = CurationWindow::query()->create([
            'network' => Networks::INSTAGRAM,
            'starts_at' => '19:00',
            'ends_at' => '23:00',
            'hashtags_min' => 18,
            'hashtags_max' => 25,
        ]);

        Livewire::test('social::curation-settings')
            ->call('saveWindow', $window->id, 'hashtags_max', '99');

        // Instagram's own limit is 30, and a post above it is rejected or silently
        // stripped.
        $this->assertSame(30, $window->fresh()->hashtags_max);
    }

    public function test_switching_a_network_off_leaves_its_account_connected(): void
    {
        $window = CurationWindow::query()->create([
            'network' => Networks::INSTAGRAM,
            'starts_at' => '19:00',
            'ends_at' => '23:00',
            'hashtags_min' => 18,
            'hashtags_max' => 25,
        ]);

        Livewire::test('social::curation-settings')->call('toggleWindow', $window->id);

        // Stopping the daily post to one network must not mean disconnecting the
        // account, which would lose the credential.
        $this->assertFalse($window->fresh()->is_active);
    }
}
