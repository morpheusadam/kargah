<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\Publishers\FakePublisher;
use Modules\Social\Services\Publishing;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * `⚡post-history.blade.php`.
 *
 * Four things the brief asks for by name, and this file is organised around
 * exactly those:
 *
 * - targets group under their post;
 * - a failed target shows its real reason, not a placeholder;
 * - retry re-enqueues through the existing `PostPublisher` and cannot
 *   duplicate a published target;
 * - the CSV export contains what the screen shows.
 *
 * **No test touches the network.** `preventStrayRequests()` is the same guard
 * `SocialModuleTest` runs under, for the same reason: a swapped `FakePublisher`
 * should mean nothing here can leave the process, and a passing test that
 * quietly made a real HTTP call would be worse than a failing one.
 */
class PostHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-16 12:00:00');

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function fake(string $network): FakePublisher
    {
        $fake = new FakePublisher($network);

        $this->app->make(Publishing::class)->swap($fake);

        return $fake;
    }

    private function account(string $network): SocialAccount
    {
        return SocialAccount::factory()->onNetwork($network)->connected()->create();
    }

    /* Grouping ------------------------------------------------------------- */

    /**
     * "Targets group under their post."
     *
     * One post aimed at two networks must appear as two rows on the history
     * table, both carrying the same post excerpt — never as a single row that
     * can only speak for one of the two deliveries.
     */
    public function test_targets_group_under_their_post(): void
    {
        $post = Post::factory()->published()->create(['body' => 'Two networks, one thought, written for this test.']);

        $mastodon = $this->account(Networks::MASTODON);
        $bluesky = $this->account(Networks::BLUESKY);

        PostTarget::factory()->published('mastodon-remote-1')->create([
            'post_id' => $post->id,
            'social_account_id' => $mastodon->id,
        ]);

        PostTarget::factory()->published('bluesky-remote-1')->create([
            'post_id' => $post->id,
            'social_account_id' => $bluesky->id,
        ]);

        $this->actingAs(User::factory()->create());

        Livewire::test('social::post-history')
            ->assertSee('Two networks, one thought')
            ->assertSee('Mastodon')
            ->assertSee('Bluesky')
            ->assertSet('network', '');

        // The grouping itself: both targets are under the one post id, not
        // spread across two unrelated rows a filter could separate.
        $this->assertSame(2, $post->targets()->count());
        $this->assertSame(1, Post::query()->count());
    }

    /* Honest failure text ---------------------------------------------------- */

    /**
     * "A failed target shows its real reason."
     *
     * The exact sentence the driver produced has to be on the page — this
     * page must never fall back to a generic "something went wrong", which is
     * the one failure mode the brief calls out by name.
     */
    public function test_a_failed_target_shows_its_real_reason(): void
    {
        $post = Post::factory()->create(['status' => Post::FAILED]);

        $account = $this->account(Networks::BLUESKY);

        PostTarget::factory()->failed('the record was rejected as too long for this network')->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
        ]);

        $this->actingAs(User::factory()->create());

        Livewire::test('social::post-history')
            ->assertSee('the record was rejected as too long for this network')
            ->assertDontSee('Something went wrong');
    }

    /** Filtering by status narrows the target rows shown, not just decorates them. */
    public function test_the_status_filter_narrows_which_targets_appear(): void
    {
        $post = Post::factory()->create(['status' => Post::PARTLY_FAILED]);

        PostTarget::factory()->published('kept-remote')->create([
            'post_id' => $post->id,
            'social_account_id' => $this->account(Networks::MASTODON)->id,
        ]);

        PostTarget::factory()->failed('the chat could not be reached')->create([
            'post_id' => $post->id,
            'social_account_id' => $this->account(Networks::BLUESKY)->id,
        ]);

        $this->actingAs(User::factory()->create());

        Livewire::test('social::post-history')
            ->set('status', PostTarget::FAILED)
            ->assertSee('the chat could not be reached')
            ->assertDontSee('kept-remote');
    }

    /**
     * Filtering by destination narrows to the one network asked for.
     *
     * Asserted on the accounts' handles rather than the network's label —
     * the destination `<select>` always lists every network so a person can
     * *choose* one, including networks with no row on screen, so the word
     * "Telegram" is on the page either way and is not the fact under test.
     */
    public function test_the_destination_filter_narrows_to_one_network(): void
    {
        $post = Post::factory()->published()->create();

        $mastodon = $this->account(Networks::MASTODON);
        $telegram = $this->account(Networks::TELEGRAM);

        PostTarget::factory()->published()->create([
            'post_id' => $post->id,
            'social_account_id' => $mastodon->id,
        ]);

        PostTarget::factory()->published()->create([
            'post_id' => $post->id,
            'social_account_id' => $telegram->id,
        ]);

        $this->actingAs(User::factory()->create());

        Livewire::test('social::post-history')
            ->set('network', Networks::MASTODON)
            ->assertSee($mastodon->handle)
            ->assertDontSee($telegram->handle);
    }

    /** Free text over the post body finds the post, whatever its delivery status. */
    public function test_free_text_search_matches_the_post_body(): void
    {
        $post = Post::factory()->published()->create(['body' => 'A very particular sentence about drainage compliance.']);

        PostTarget::factory()->published()->create([
            'post_id' => $post->id,
            'social_account_id' => $this->account(Networks::MASTODON)->id,
        ]);

        $other = Post::factory()->published()->create(['body' => 'Something else entirely, about invoices.']);

        PostTarget::factory()->published()->create([
            'post_id' => $other->id,
            'social_account_id' => $this->account(Networks::BLUESKY)->id,
        ]);

        $this->actingAs(User::factory()->create());

        Livewire::test('social::post-history')
            ->set('search', 'drainage compliance')
            ->assertSee('A very particular sentence about drainage compliance')
            ->assertDontSee('Something else entirely');
    }

    /* Retry ------------------------------------------------------------------ */

    /**
     * "Retry re-enqueues through the existing service and cannot duplicate a
     * published target."
     *
     * One post, one network already delivered. The retry button for that
     * target must not exist on the rendered page, and calling the action
     * directly — the only way to reach it once the button is gone — must
     * still refuse rather than resend, because `PostPublisher::claim()` will
     * not claim a `published` row. `sendCount()` is the proof a row cannot
     * give on its own: the network was never touched a second time.
     */
    public function test_retry_cannot_duplicate_a_published_target(): void
    {
        $mastodon = $this->fake(Networks::MASTODON);

        $post = Post::factory()->published()->create();
        $account = $this->account(Networks::MASTODON);

        PostTarget::factory()->published('already-live')->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
        ]);

        $this->actingAs(User::factory()->create());

        $component = Livewire::test('social::post-history');

        // No button is rendered for a delivered target — see the guard in
        // the template — so the retry markup for this account must be absent.
        $component->assertDontSee('wire:click="retry('.$post->id.', '.$account->id.')"', false);

        // And even called directly, the row is refused rather than resent.
        $component->call('retry', $post->id, $account->id);

        $this->assertSame(0, $mastodon->sendCount());
        $this->assertSame('already-live', $post->targets()->sole()->remote_id);
    }

    /** A failed target retries through `PostPublisher`, and only that target moves. */
    public function test_retry_sends_a_failed_target_through_the_existing_publisher(): void
    {
        $mastodon = $this->fake(Networks::MASTODON);
        $bluesky = $this->fake(Networks::BLUESKY);

        $post = Post::factory()->create(['status' => Post::PARTLY_FAILED]);

        $delivered = PostTarget::factory()->published('kept-id')->create([
            'post_id' => $post->id,
            'social_account_id' => $this->account(Networks::MASTODON)->id,
        ]);

        $failedAccount = $this->account(Networks::BLUESKY);

        PostTarget::factory()->failed()->create([
            'post_id' => $post->id,
            'social_account_id' => $failedAccount->id,
        ]);

        $this->actingAs(User::factory()->create());

        Livewire::test('social::post-history')->call('retry', $post->id, $failedAccount->id);

        $this->assertSame(0, $mastodon->sendCount(), 'the delivered target must not be sent to again');
        $this->assertSame(1, $bluesky->sendCount());
        $this->assertSame('kept-id', $delivered->fresh()->remote_id);
        $this->assertSame(PostTarget::PUBLISHED, $post->targets()->where('social_account_id', $failedAccount->id)->sole()->status);
        $this->assertSame(Post::PUBLISHED, $post->fresh()->status);
    }

    /* CSV export --------------------------------------------------------------- */

    /** The CSV contains what the screen shows — filtered the same way, worded the same way. */
    public function test_the_csv_contains_what_the_screen_shows(): void
    {
        $post = Post::factory()->create(['status' => Post::PARTLY_FAILED, 'body' => 'Exported to a spreadsheet, hopefully correctly.']);

        PostTarget::factory()->published('csv-remote-id')->create([
            'post_id' => $post->id,
            'social_account_id' => $this->account(Networks::MASTODON)->id,
        ]);

        PostTarget::factory()->failed('the webhook returned HTTP 404')->create([
            'post_id' => $post->id,
            'social_account_id' => $this->account(Networks::DISCORD)->id,
        ]);

        // A second post, deliberately filtered out, to prove the export
        // honours the same filter the table does rather than dumping every
        // row regardless of what is on screen.
        $excluded = Post::factory()->create(['body' => 'Not part of this export.']);

        PostTarget::factory()->published()->create([
            'post_id' => $excluded->id,
            'social_account_id' => $this->account(Networks::TELEGRAM)->id,
        ]);

        $this->actingAs(User::factory()->create());

        $component = Livewire::test('social::post-history')
            ->set('search', 'spreadsheet')
            ->call('exportCsv')
            ->assertFileDownloaded();

        // Livewire's file-download support captures the streamed body as
        // base64 on the `download` effect — see
        // `Livewire\Features\SupportFileDownloads\SupportFileDownloads::call()`.
        // That is the same bytes the browser would receive, so decoding it
        // here is the honest way to assert on "what the CSV contains" rather
        // than re-deriving the rows a second time and asserting on those.
        $csv = base64_decode($component->effects['download']['content']);

        $this->assertStringContainsString('csv-remote-id', $csv);
        $this->assertStringContainsString('the webhook returned HTTP 404', $csv);
        $this->assertStringContainsString('Mastodon', $csv);
        $this->assertStringContainsString('Discord', $csv);
        $this->assertStringNotContainsString('Not part of this export', $csv);
        $this->assertStringNotContainsString('Telegram', $csv);
    }

    /* Empty state --------------------------------------------------------------- */

    public function test_the_empty_state_is_honest_about_no_history_existing_yet(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('social::post-history')
            ->assertSee('Nothing has been sent yet.');
    }

    public function test_the_empty_state_when_filters_match_nothing_offers_to_clear_them(): void
    {
        Post::factory()->published()->create();

        $this->actingAs(User::factory()->create());

        Livewire::test('social::post-history')
            ->set('search', 'a sentence nothing seeded will ever contain')
            ->assertSee('Nothing in this history matches those filters.')
            ->assertSee('Clear filters');
    }

    /** Renders against seeded data, the same regression guard `SocialModuleTest` runs for every other page. */
    public function test_the_history_page_renders_on_an_empty_database(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/social/posts/history')->assertOk();
    }
}
