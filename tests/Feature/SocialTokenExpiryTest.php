<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Notification;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * `social:check-token-expiry` — the one gap `08-postiz-parity.md` names as
 * already built and never read: `social_accounts.token_expires_at` with
 * nothing consulting it. This is the daily sweep that does.
 *
 * `dedupe_key` is the whole design here, same as `DueCardSweepTest` for
 * `project:notify-due-cards`, and the one property that matters beyond the
 * ordinary "runs twice, changes nothing" is what happens when a credential
 * is re-pasted: the expiry moves, and a dedupe key that did not fold the new
 * expiry in would go on suppressing warnings against a token that no longer
 * exists. `test_re_pasting_a_credential_earns_a_fresh_warning_rather_than_being_suppressed`
 * is the test that would fail against a naive `social_account:{id}:expiring`
 * key.
 */
class SocialTokenExpiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-03 09:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function linkedin(array $attributes = []): SocialAccount
    {
        return SocialAccount::factory()->onNetwork(Networks::LINKEDIN)->connected()->create($attributes);
    }

    /* The window ---------------------------------------------------------------- */

    public function test_an_account_expiring_inside_the_window_produces_one_notification(): void
    {
        $user = User::factory()->create();
        $account = $this->linkedin(['created_by' => $user->id, 'token_expires_at' => now()->addDays(3)]);

        $this->artisan('social:check-token-expiry')->assertExitCode(0);

        $this->assertSame(1, Notification::query()->count());
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
            'event' => 'social.token_expiring',
            'dedupe_key' => 'social_account:'.$account->id.':expiring:'.$account->token_expires_at->getTimestamp().':7d',
        ]);
    }

    public function test_an_account_with_no_expiry_set_produces_no_notification(): void
    {
        $user = User::factory()->create();
        // The factory default: no credentials, no expiry — the ordinary state
        // of a network that was never connected.
        SocialAccount::factory()->onNetwork(Networks::MASTODON)->create(['created_by' => $user->id]);

        $this->artisan('social:check-token-expiry')->assertExitCode(0);

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_an_account_outside_the_window_produces_no_notification(): void
    {
        $user = User::factory()->create();
        $this->linkedin(['created_by' => $user->id, 'token_expires_at' => now()->addDays(45)]);

        $this->artisan('social:check-token-expiry')->assertExitCode(0);

        $this->assertSame(0, Notification::query()->count());
    }

    /* Idempotence — the standing rule -------------------------------------------- */

    public function test_running_the_check_twice_changes_nothing_the_second_time(): void
    {
        $user = User::factory()->create();
        $this->linkedin(['created_by' => $user->id, 'token_expires_at' => now()->addDays(3)]);

        $this->artisan('social:check-token-expiry')->assertExitCode(0);
        $this->assertSame(1, Notification::query()->count());

        $this->artisan('social:check-token-expiry')->assertExitCode(0);
        $this->assertSame(1, Notification::query()->count());
    }

    /* Expired reads louder than expiring soon ------------------------------------ */

    public function test_an_already_expired_account_is_reported_as_expired_rather_than_expiring(): void
    {
        $user = User::factory()->create();
        $expiring = $this->linkedin(['created_by' => $user->id, 'token_expires_at' => now()->addDays(3)]);
        $expired = $this->linkedin(['created_by' => $user->id, 'token_expires_at' => now()->subDay()]);

        $this->artisan('social:check-token-expiry')->assertExitCode(0);

        $this->assertSame(2, Notification::query()->count());
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
            'event' => 'social.token_expiring',
            'subject_id' => $expiring->id,
        ]);
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $user->id,
            'event' => 'social.token_expired',
            'subject_id' => $expired->id,
        ]);
    }

    /* Re-pasting a credential ------------------------------------------------------ */

    /**
     * The case a naive key gets wrong. A first paste puts the token inside
     * the window, gets warned about once, is then replaced with a token that
     * expires far in the future, and eventually drifts back inside the
     * window again under its own new expiry. The second warning must arrive
     * — a dedupe key built from the account id alone would still be sitting
     * on the row the first warning wrote and would swallow this one.
     */
    public function test_re_pasting_a_credential_earns_a_fresh_warning_rather_than_being_suppressed(): void
    {
        $user = User::factory()->create();
        $account = $this->linkedin(['created_by' => $user->id, 'token_expires_at' => now()->addDays(3)]);

        $this->artisan('social:check-token-expiry')->assertExitCode(0);
        $this->assertSame(1, Notification::query()->count());
        $firstKey = 'social_account:'.$account->id.':expiring:'.$account->token_expires_at->getTimestamp().':7d';
        $this->assertDatabaseHas('user_notifications', ['dedupe_key' => $firstKey]);

        // Re-paste: a fresh sixty-day token, the way `⚡account-connect`'s
        // `save()` would set it.
        $account->forceFill(['token_expires_at' => now()->addDays(60)])->save();

        // Not due yet — the new expiry is well outside the window.
        $this->artisan('social:check-token-expiry')->assertExitCode(0);
        $this->assertSame(1, Notification::query()->count());

        // Drift forward until the new token is inside the window under its
        // own expiry.
        $this->travel(56)->days();

        $this->artisan('social:check-token-expiry')->assertExitCode(0);

        $this->assertSame(2, Notification::query()->count());
        $secondKey = 'social_account:'.$account->id.':expiring:'.$account->fresh()->token_expires_at->getTimestamp().':7d';
        $this->assertNotSame($firstKey, $secondKey);
        $this->assertDatabaseHas('user_notifications', ['dedupe_key' => $secondKey]);

        // Running it again from here changes nothing further.
        $this->artisan('social:check-token-expiry')->assertExitCode(0);
        $this->assertSame(2, Notification::query()->count());
    }

    /* Skipped rather than notified ------------------------------------------------- */

    public function test_an_inactive_account_is_skipped(): void
    {
        $user = User::factory()->create();
        SocialAccount::factory()->onNetwork(Networks::LINKEDIN)->connected()->inactive()->create([
            'created_by' => $user->id,
            'token_expires_at' => now()->addDays(3),
        ]);

        $this->artisan('social:check-token-expiry')->assertExitCode(0);

        $this->assertSame(0, Notification::query()->count());
    }

    /**
     * A row can carry a stale `token_expires_at` with no credential behind
     * it — data left over from before a manual edit, or from before this
     * feature existed. `isConnected()` is the gate, not merely `is_active`.
     */
    public function test_an_account_with_no_credentials_is_skipped_despite_an_expiry_being_set(): void
    {
        $user = User::factory()->create();
        SocialAccount::factory()->onNetwork(Networks::LINKEDIN)->create([
            'created_by' => $user->id,
            'credentials' => null,
            'token_expires_at' => now()->addDays(3),
        ]);

        $this->artisan('social:check-token-expiry')->assertExitCode(0);

        $this->assertSame(0, Notification::query()->count());
    }

    /* Recipients -------------------------------------------------------------------- */

    public function test_an_account_with_no_creator_notifies_every_user(): void
    {
        $one = User::factory()->create();
        $two = User::factory()->create();
        $this->linkedin(['created_by' => null, 'token_expires_at' => now()->addDays(3)]);

        $this->artisan('social:check-token-expiry')->assertExitCode(0);

        $this->assertSame(2, Notification::query()->count());
        $this->assertDatabaseHas('user_notifications', ['user_id' => $one->id]);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $two->id]);
    }

    /* The accounts page --------------------------------------------------------------- */

    public function test_the_accounts_page_shows_expiring_and_expired_state(): void
    {
        $user = User::factory()->create();
        $this->linkedin(['created_by' => $user->id, 'handle' => 'in/expiring-soon-test', 'token_expires_at' => now()->addDays(3)]);
        $this->linkedin(['created_by' => $user->id, 'handle' => 'in/already-expired-test', 'token_expires_at' => now()->subDay()]);

        $response = $this->actingAs($user)->get('/social/accounts');

        $response->assertOk();
        $response->assertSee('Token expiring soon');
        $response->assertSee('Token expired');
    }

    /* The scheduler --------------------------------------------------------------------- */

    public function test_the_check_is_registered_on_the_scheduler_without_overlapping(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains($event->command ?? '', 'social:check-token-expiry'));

        $this->assertCount(1, $events, 'social:check-token-expiry is not on the scheduler.');
        $this->assertNotNull(
            $events->first()->withoutOverlapping,
            'social:check-token-expiry must be withoutOverlapping().',
        );
    }
}
