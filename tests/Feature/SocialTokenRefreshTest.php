<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * `social:refresh-tokens` — the half of token upkeep that keeps a connection
 * alive rather than announcing its death.
 *
 * These tests exist because this command **writes a credential**, and that is
 * the one thing in the Social module that cannot be un-broken by pressing retry:
 * a botched write leaves an account that looks connected, verifies as connected
 * and cannot publish, with the working token gone. Two properties carry the
 * weight and both are asserted here rather than reasoned about —
 *
 * - `test_a_refresh_replaces_only_the_access_token_and_keeps_the_account_id`,
 *   which fails the moment the credential bag is assigned instead of merged;
 * - `test_a_refused_refresh_leaves_the_stored_credential_untouched`, which is
 *   what makes a bad day at Meta cost nothing.
 *
 * The rest are the window: the whole design of the command is that it asks
 * early and often instead of once at the last minute, because the failure worth
 * defending against is not a refused request but a cron that did not run.
 */
class SocialTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    /** ~59.99 days, which is what Meta actually answers with for a sixty-day token. */
    private const SIXTY_DAYS = 5183944;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-06 09:30:00');

        // Nothing in this file should ever reach a real host; the drivers under
        // test are the two that talk to the owner's live account.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function instagram(int $daysLeft, string $token = 'IGAA-old'): SocialAccount
    {
        return SocialAccount::factory()->onNetwork(Networks::INSTAGRAM)->create([
            'credentials' => ['ig_user_id' => '17841400000000000', 'access_token' => $token],
            'token_expires_at' => now()->addDays($daysLeft),
            'connected_at' => now()->subDays(60 - $daysLeft),
        ]);
    }

    private function fakeRefresh(string $host, string $token = 'IGAA-new', int $expiresIn = self::SIXTY_DAYS): void
    {
        Http::fake([
            $host.'/refresh_access_token*' => Http::response([
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_in' => $expiresIn,
            ]),
        ]);
    }

    /* What gets written ---------------------------------------------------------- */

    /**
     * 🔴 The test that would catch the worst available mistake.
     *
     * `access_token` is one of two credentials on this network. A refresh that
     * assigned `['access_token' => …]` over the bag rather than merging into it
     * would encrypt away `ig_user_id`, which is in every publish URL's path —
     * and it would do it silently, thirty days before anybody tried to post.
     */
    public function test_a_refresh_replaces_only_the_access_token_and_keeps_the_account_id(): void
    {
        $this->fakeRefresh('graph.instagram.com');
        $account = $this->instagram(daysLeft: 20);

        $this->artisan('social:refresh-tokens')->assertExitCode(0);

        $account->refresh();
        $this->assertSame('IGAA-new', $account->credential('access_token'));
        $this->assertSame('17841400000000000', $account->credential('ig_user_id'));
    }

    /**
     * The expiry comes from the network's `expires_in`, not from the
     * catalogue's sixty days. They are close and they are not the same number,
     * which is exactly why this asserts on the one Meta sent.
     */
    public function test_the_new_expiry_is_the_one_the_network_answered_with(): void
    {
        $this->fakeRefresh('graph.instagram.com', expiresIn: 4000000);
        $account = $this->instagram(daysLeft: 20);

        $this->artisan('social:refresh-tokens')->assertExitCode(0);

        $this->assertTrue(
            $account->refresh()->token_expires_at->equalTo(now()->addSeconds(4000000)),
            'The stored expiry should be now + expires_in, not the catalogue estimate.',
        );
    }

    public function test_instagram_sends_its_own_grant_name_and_no_account_id(): void
    {
        $this->fakeRefresh('graph.instagram.com');
        $this->instagram(daysLeft: 20);

        $this->artisan('social:refresh-tokens')->assertExitCode(0);

        Http::assertSent(fn ($request): bool => $request['grant_type'] === 'ig_refresh_token'
            && $request['access_token'] === 'IGAA-old'
            && ! isset($request['ig_user_id']));
    }

    /**
     * Threads is the same shape under a different name, on a different host,
     * and the two grant names are not interchangeable — the same lesson the
     * tokens themselves teach.
     */
    public function test_threads_sends_its_own_grant_name_on_its_own_host(): void
    {
        $this->fakeRefresh('graph.threads.net', token: 'THQV-new');

        SocialAccount::factory()->onNetwork(Networks::THREADS)->create([
            'credentials' => ['threads_user_id' => '78901234567890123', 'access_token' => 'THQV-old'],
            'token_expires_at' => now()->addDays(20),
        ]);

        $this->artisan('social:refresh-tokens')->assertExitCode(0);

        Http::assertSent(fn ($request): bool => $request['grant_type'] === 'th_refresh_token'
            && str_contains($request->url(), 'graph.threads.net'));
    }

    /* The window ------------------------------------------------------------------ */

    public function test_a_token_with_more_than_half_its_life_left_is_not_touched(): void
    {
        $account = $this->instagram(daysLeft: 45);

        $this->artisan('social:refresh-tokens')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame('IGAA-old', $account->refresh()->credential('access_token'));
    }

    public function test_a_token_just_inside_the_window_is_refreshed(): void
    {
        $this->fakeRefresh('graph.instagram.com');
        $this->instagram(daysLeft: 29);

        $this->artisan('social:refresh-tokens')->assertExitCode(0);

        Http::assertSentCount(1);
    }

    /**
     * An expired token cannot be extended by any means Kargah has — somebody
     * has to authorise the app again. Asking anyway would spend a request to be
     * told so and would overwrite `last_error` with a refusal less useful than
     * the expiry warning already sitting in the feed.
     */
    public function test_an_already_expired_token_is_not_asked_about(): void
    {
        $this->instagram(daysLeft: -1);

        $this->artisan('social:refresh-tokens')->assertExitCode(0);

        Http::assertNothingSent();
    }

    /** `--force` is for proving the path works on the day it is built. */
    public function test_force_refreshes_a_token_that_is_nowhere_near_due(): void
    {
        $this->fakeRefresh('graph.instagram.com');
        $this->instagram(daysLeft: 59);

        $this->artisan('social:refresh-tokens', ['--force' => true])->assertExitCode(0);

        Http::assertSentCount(1);
        $this->assertSame('IGAA-new', SocialAccount::query()->sole()->credential('access_token'));
    }

    /** Even forced. The precondition is Meta's, not the window's. */
    public function test_force_does_not_reach_for_an_expired_token(): void
    {
        $this->instagram(daysLeft: -1);

        $this->artisan('social:refresh-tokens', ['--force' => true])->assertExitCode(0);

        Http::assertNothingSent();
    }

    /* Idempotence — the standing rule ---------------------------------------------- */

    /**
     * No lock and no marker: the first run pushes the expiry sixty days out and
     * the second finds it outside the window. The property falls out of the
     * window rule, which is what makes it safe on cron.
     */
    public function test_running_it_twice_asks_once(): void
    {
        $this->fakeRefresh('graph.instagram.com');
        $this->instagram(daysLeft: 20);

        $this->artisan('social:refresh-tokens')->assertExitCode(0);
        $this->artisan('social:refresh-tokens')->assertExitCode(0);

        Http::assertSentCount(1);
    }

    /* Failure --------------------------------------------------------------------- */

    /**
     * 🔴 A refusal must cost nothing. The stored token is still valid for
     * another twenty days at this point, and tomorrow's run will try again.
     */
    public function test_a_refused_refresh_leaves_the_stored_credential_untouched(): void
    {
        Http::fake([
            'graph.instagram.com/*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token', 'type' => 'OAuthException', 'code' => 190],
            ], 400),
        ]);

        $account = $this->instagram(daysLeft: 20);
        $expiry = $account->token_expires_at;

        $this->artisan('social:refresh-tokens')->assertExitCode(1);

        $account->refresh();
        $this->assertSame('IGAA-old', $account->credential('access_token'));
        $this->assertTrue($account->token_expires_at->equalTo($expiry));
        $this->assertNotNull($account->last_error);
    }

    /** The replacement credential never appears in a message a page will render. */
    public function test_a_refusal_message_carries_no_credential(): void
    {
        Http::fake([
            'graph.instagram.com/*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token', 'code' => 190],
            ], 400),
        ]);

        $this->instagram(daysLeft: 20);

        $this->artisan('social:refresh-tokens')->assertExitCode(1);

        $this->assertStringNotContainsString('IGAA-old', (string) SocialAccount::query()->sole()->last_error);
    }

    /**
     * A 200 with no token in it is not a success. Meta answers 200 with an
     * `error` key often enough that the whole family reads the body first, and
     * a body that decodes but carries nothing usable is the same problem one
     * step further on.
     */
    public function test_a_response_with_no_replacement_token_is_a_failure_rather_than_a_write(): void
    {
        Http::fake(['graph.instagram.com/*' => Http::response(['token_type' => 'bearer'])]);

        $this->instagram(daysLeft: 20);

        $this->artisan('social:refresh-tokens')->assertExitCode(1);

        $this->assertSame('IGAA-old', SocialAccount::query()->sole()->credential('access_token'));
    }

    /* Networks with nothing to renew ------------------------------------------------ */

    /**
     * LinkedIn expires in sixty days and has no refresh a self-serving
     * developer can call, which is why its `requirement` copy tells the person
     * to come back and paste one. It must be left entirely alone here rather
     * than tried and reported as broken.
     */
    public function test_a_network_that_cannot_renew_is_passed_over_in_silence(): void
    {
        $account = SocialAccount::factory()->onNetwork(Networks::LINKEDIN)->connected()->create([
            'token_expires_at' => now()->addDays(3),
        ]);

        $this->artisan('social:refresh-tokens')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertNull($account->refresh()->last_error);
    }

    public function test_an_inactive_instagram_account_is_left_alone(): void
    {
        SocialAccount::factory()->onNetwork(Networks::INSTAGRAM)->inactive()->create([
            'credentials' => ['ig_user_id' => '178414', 'access_token' => 'IGAA-old'],
            'token_expires_at' => now()->addDays(20),
        ]);

        $this->artisan('social:refresh-tokens')->assertExitCode(0);

        Http::assertNothingSent();
    }

    /* The scheduler ------------------------------------------------------------------ */

    /**
     * Both commands, and the ten minutes between them. The order is the part
     * worth asserting: a warning that arrives before the refresh has had its
     * turn tells the owner to go and paste a token Kargah was about to fetch.
     */
    public function test_the_refresh_runs_daily_and_ahead_of_the_expiry_warning(): void
    {
        $events = collect(app(Schedule::class)->events());

        $refresh = $events->first(fn ($event): bool => str_contains($event->command ?? '', 'social:refresh-tokens'));
        $check = $events->first(fn ($event): bool => str_contains($event->command ?? '', 'social:check-token-expiry'));

        $this->assertNotNull($refresh, 'social:refresh-tokens is not on the scheduler.');
        $this->assertNotNull($refresh->withoutOverlapping, 'social:refresh-tokens must be withoutOverlapping().');
        $this->assertSame('5 8 * * *', $refresh->expression);
        $this->assertSame('15 8 * * *', $check->expression);
    }
}
