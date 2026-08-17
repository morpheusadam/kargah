<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * `⚡calendar.blade.php` — moving a post through it, and the timezone decision
 * that makes moving it safe.
 *
 * Three things have to hold for the calendar and `PublishDue` to agree, and
 * each gets its own test rather than one big one, because a failure in any one
 * of them means a post either fires early, fires late, or never fires:
 *
 * - `reschedule()` writes `posts.scheduled_for` — the exact column
 *   `Post::scopeDue()` (Modules/Social/app/Models/Post.php:126) reads and
 *   `PublishDue::handle()` (Modules/Social/app/Console/PublishDue.php:35)
 *   dispatches from;
 * - it writes that column in UTC regardless of what timezone the person who
 *   dragged the event is in, because `now()` inside both of those is UTC;
 * - a wall-clock time on the far side of a DST change round-trips to the
 *   *same* wall-clock time, not one shifted by the hour the clocks moved.
 */
class CalendarSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests are about the claim `social:publish-due` makes — moving a
        // post onto `publishing` — not about what a real driver then does with
        // it. Faking the queue stops `PublishPost` running inline on the `sync`
        // driver and carrying the status past the point these tests assert on;
        // `preventStrayRequests()` is the same belt-and-braces `SocialModuleTest`
        // uses so a mistake here fails loudly rather than making a real request.
        Queue::fake();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function account(): SocialAccount
    {
        return SocialAccount::factory()->onNetwork(Networks::MASTODON)->connected()->create();
    }

    private function londoner(): User
    {
        return User::factory()->create(['timezone' => 'Europe/London']);
    }

    /* Criterion one: reschedule writes the field PublishDue reads, in UTC ----- */

    public function test_moving_a_post_on_the_calendar_writes_the_field_publish_due_reads(): void
    {
        Carbon::setTestNow('2026-08-17 10:00:00'); // UTC, no DST in play

        $account = $this->account();
        $post = Post::factory()->scheduled('2026-08-20 09:00:00')->create();
        PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);

        $this->actingAs($this->londoner());

        // London is UTC+1 (BST) in August, so 14:00 local is 13:00 UTC.
        Livewire::test('social::calendar')
            ->call('reschedule', $post->id, '2026-08-21T14:00');

        $post->refresh();

        $this->assertSame('2026-08-21 13:00:00', $post->scheduled_for->utc()->toDateTimeString());
        $this->assertSame(Post::SCHEDULED, $post->status);

        // And the field PublishDue actually reads is the same one: nothing is
        // due yet at 12:59 UTC, and the post is claimed by 13:00 UTC exactly.
        Carbon::setTestNow('2026-08-21 12:59:00');
        $this->artisan('social:publish-due')->assertSuccessful();
        $this->assertSame(Post::SCHEDULED, $post->fresh()->status);

        Carbon::setTestNow('2026-08-21 13:00:00');
        $this->artisan('social:publish-due')->assertSuccessful();
        $this->assertSame(Post::PUBLISHING, $post->fresh()->status);
    }

    public function test_a_post_already_published_to_one_network_cannot_be_moved(): void
    {
        $account = $this->account();
        $post = Post::factory()->create(['status' => Post::PARTLY_FAILED]);

        PostTarget::factory()->published()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);

        $this->actingAs($this->londoner());

        $moved = Livewire::test('social::calendar')
            ->call('reschedule', $post->id, '2026-09-01T09:00')
            ->assertDispatched('toast');

        $this->assertNull($post->fresh()->scheduled_for);
    }

    /* Criterion two: the explicit editor is the same write path as the drag --- */

    public function test_the_explicit_editor_in_next_up_moves_the_same_field_a_drag_would(): void
    {
        Carbon::setTestNow('2026-08-17 10:00:00');

        $account = $this->account();
        $post = Post::factory()->scheduled('2026-08-18 09:00:00')->create();
        $target = PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);

        $this->actingAs($this->londoner());

        Livewire::test('social::calendar')
            ->call('startEdit', $target->id)
            ->assertSet('editValue', '2026-08-18T10:00') // 09:00 UTC displayed as 10:00 BST
            ->set('editValue', '2026-08-19T11:00')
            ->call('saveEdit')
            ->assertSet('editingTargetId', null);

        // 11:00 BST on 19 Aug 2026 is 10:00 UTC.
        $this->assertSame('2026-08-19 10:00:00', $post->fresh()->scheduled_for->utc()->toDateTimeString());
    }

    /* Criterion three: the month boundary shows the right posts, in the person's zone -- */

    /**
     * A post stored a few minutes before midnight UTC on the last day of March
     * is already the first of April once it is shown in Europe/London — BST
     * starts that week, so the conversion crosses both a day and the DST
     * change in the same stroke. If the calendar filtered or grouped by the
     * stored UTC date instead of the displayed one, this post would appear on
     * the wrong side of the month boundary on screen.
     */
    public function test_the_calendar_shows_a_post_on_the_correct_side_of_the_month_boundary_in_the_users_zone(): void
    {
        Carbon::setTestNow('2026-03-31 20:00:00');

        $account = $this->account();

        // 2026-03-31 23:30 UTC = 2026-04-01 00:30 Europe/London (BST started
        // 2026-03-29, so London is already UTC+1 by the 31st).
        $post = Post::factory()->create([
            'status' => Post::SCHEDULED,
            'scheduled_for' => '2026-03-31 23:30:00',
        ]);
        PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);

        $this->actingAs($this->londoner());

        Livewire::test('social::calendar')
            ->assertViewHas('events', function (array $events) {
                $this->assertCount(1, $events);

                // The event's ISO instant is unchanged (it is still the same
                // moment); what matters is that rendering it in the label zone
                // gives 1 April, not 31 March.
                $start = Carbon::parse($events[0]['start'])->setTimezone('Europe/London');

                return $start->format('Y-m-d') === '2026-04-01';
            })
            ->assertViewHas('tz', 'Europe/London');
    }

    /* Criterion four: DST itself ------------------------------------------------ */

    /**
     * UK clocks go forward on the last Sunday of March. 2026-03-29 01:00 UTC
     * is 2026-03-29 02:00 BST — the clocks have just skipped from 00:59 GMT to
     * 02:00 BST, so 01:30 local does not exist that day. Picking a real local
     * time either side of the jump and asserting the exact UTC instant is what
     * proves the conversion uses the zone's table rather than a fixed offset.
     */
    public function test_rescheduling_across_the_spring_dst_boundary_saves_the_correct_utc_instant(): void
    {
        Carbon::setTestNow('2026-03-01 10:00:00');

        $account = $this->account();
        $post = Post::factory()->scheduled('2026-03-15 09:00:00')->create();
        PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);

        $this->actingAs($this->londoner());

        // 03:30 on 29 March 2026 is BST (UTC+1) — the clocks jumped at 01:00 UTC.
        Livewire::test('social::calendar')->call('reschedule', $post->id, '2026-03-29T03:30');

        $this->assertSame('2026-03-29 02:30:00', $post->fresh()->scheduled_for->utc()->toDateTimeString());

        // PublishDue must not claim it a minute early, in either clock.
        Carbon::setTestNow('2026-03-29 02:29:59');
        $this->artisan('social:publish-due')->assertSuccessful();
        $this->assertSame(Post::SCHEDULED, $post->fresh()->status);

        // …and must claim it the instant it is due.
        Carbon::setTestNow('2026-03-29 02:30:00');
        $this->artisan('social:publish-due')->assertSuccessful();
        $this->assertSame(Post::PUBLISHING, $post->fresh()->status);
    }

    /**
     * The display side of the same boundary: a post stored as a UTC instant
     * just after the jump must read back as the same local wall-clock time
     * that was typed in, not shifted by the hour the clocks moved.
     */
    public function test_a_post_just_after_the_dst_jump_displays_at_the_local_time_it_was_scheduled_for(): void
    {
        // `targets()` only loads what is within three months of "now" (see its
        // docblock), so the clock has to sit near the scheduled date for the
        // row to be on the page at all — same as it would the week this fires.
        Carbon::setTestNow('2026-03-29 10:00:00');

        $account = $this->account();

        // Saved as 02:30 UTC — the instant the previous test wrote for "03:30
        // local". Reading it back must give 03:30 again, not 02:30.
        $post = Post::factory()->create([
            'status' => Post::SCHEDULED,
            'scheduled_for' => '2026-03-29 02:30:00',
        ]);
        PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);

        $this->actingAs($this->londoner());

        Livewire::test('social::calendar')->assertSee('29 Mar, 03:30');
    }

    /* The empty calendar vs. a stopped cron -------------------------------------- */

    public function test_an_empty_calendar_shows_no_overdue_warning(): void
    {
        $this->actingAs($this->londoner());

        Livewire::test('social::calendar')
            ->assertViewHas('overdueMinutes', null)
            ->assertDontSee('may not be running', false)
            ->assertSee('Nothing queued');
    }

    public function test_a_post_overdue_by_more_than_two_minutes_raises_the_scheduler_warning(): void
    {
        Carbon::setTestNow('2026-08-17 10:00:00');

        $account = $this->account();
        $post = Post::factory()->overdue(5)->create();
        PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);

        $this->actingAs($this->londoner());

        Livewire::test('social::calendar')
            ->assertViewHas('overdueMinutes', fn (?int $minutes): bool => $minutes >= 5)
            ->assertSee('waiting');
    }

    public function test_a_post_overdue_by_less_than_two_minutes_is_not_a_false_alarm(): void
    {
        Carbon::setTestNow('2026-08-17 10:00:00');

        $account = $this->account();
        $post = Post::factory()->overdue(1)->create();
        PostTarget::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);

        $this->actingAs($this->londoner());

        Livewire::test('social::calendar')->assertViewHas('overdueMinutes', null);
    }
}
