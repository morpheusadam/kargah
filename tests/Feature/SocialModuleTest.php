<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Modules\Social\Database\Seeders\SocialDatabaseSeeder;
use Modules\Social\Jobs\PublishPost;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Models\SocialNotification;
use Modules\Social\Services\PostPublisher;
use Modules\Social\Services\Publishers\BlueskyPublisher;
use Modules\Social\Services\Publishers\FakePublisher;
use Modules\Social\Services\Publishers\MastodonPublisher;
use Modules\Social\Services\Publishers\PublishFailed;
use Modules\Social\Services\Publishers\TelegramPublisher;
use Modules\Social\Services\Publishing;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * The Social module.
 *
 * Three properties decide whether this is safe to put on cron, and they are the
 * three the build order names:
 *
 * - one post publishes to two networks with independent status;
 * - a failed target retries without resending the succeeded one;
 * - a scheduled post fires from cron within a minute of its time.
 *
 * The second is the one the whole design turns on, and it is the reason a
 * `FakePublisher` exists at all: a recorded row cannot tell a preserved success
 * from a resend that happened to write the same values, so the fake counts
 * sends and the test asserts on the count.
 *
 * **No test touches the network.** `preventStrayRequests()` makes that a failure
 * rather than a slow test, and the registry holds factories rather than
 * instances so a swapped network never constructs its real driver at all.
 */
class SocialModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A fixed moment, because every `scheduled_for` assertion is relative
        // to it and 'within a minute' is not a thing you can assert on a clock
        // that moves between the arrange and the act.
        Carbon::setTestNow('2026-08-03 09:30:00');

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* Helpers ----------------------------------------------------------------- */

    private function registry(): Publishing
    {
        return $this->app->make(Publishing::class);
    }

    /** Replace a network's driver with one that cannot leave the process. */
    private function fake(string $network): FakePublisher
    {
        $fake = new FakePublisher($network);

        $this->registry()->swap($fake);

        return $fake;
    }

    private function account(string $network): SocialAccount
    {
        return SocialAccount::factory()->onNetwork($network)->connected()->create();
    }

    /**
     * A post aimed at the given accounts, all pending.
     *
     * @param  list<SocialAccount>  $accounts
     */
    private function postTo(array $accounts, array $attributes = []): Post
    {
        $post = Post::factory()->create($attributes);

        foreach ($accounts as $account) {
            PostTarget::factory()->create([
                'post_id' => $post->id,
                'social_account_id' => $account->id,
            ]);
        }

        return $post->refresh();
    }

    private function publisher(): PostPublisher
    {
        return $this->app->make(PostPublisher::class);
    }

    /* Criterion one ------------------------------------------------------------ */

    /**
     * "One post publishes to two networks with independent status."
     *
     * Independent means the row, not the outcome: both targets carry their own
     * status, their own remote id and their own error, and the post's own
     * column is a summary derived from them rather than a fifth opinion.
     */
    public function test_one_post_publishes_to_two_networks_with_independent_status(): void
    {
        $mastodon = $this->fake(Networks::MASTODON);
        $bluesky = $this->fake(Networks::BLUESKY);
        $bluesky->failWith('the record was rejected as too long');

        $post = $this->postTo([
            $this->account(Networks::MASTODON),
            $this->account(Networks::BLUESKY),
        ]);

        $report = $this->publisher()->publishPost($post);

        $this->assertSame(1, $report->published);
        $this->assertSame(1, $report->failed);

        $sent = $post->targets()->with('account')->get()
            ->keyBy(fn (PostTarget $target): string => $target->account->network);

        $this->assertSame(PostTarget::PUBLISHED, $sent[Networks::MASTODON]->status);
        $this->assertNotNull($sent[Networks::MASTODON]->remote_id);
        $this->assertNotNull($sent[Networks::MASTODON]->published_at);
        $this->assertNull($sent[Networks::MASTODON]->error);

        $this->assertSame(PostTarget::FAILED, $sent[Networks::BLUESKY]->status);
        $this->assertNull($sent[Networks::BLUESKY]->remote_id);
        $this->assertNull($sent[Networks::BLUESKY]->published_at);
        $this->assertStringContainsString('too long', $sent[Networks::BLUESKY]->error);

        // One succeeded and one did not, so neither 'published' nor 'failed' is
        // the truth about the post.
        $this->assertSame(Post::PARTLY_FAILED, $post->fresh()->status);

        $this->assertSame(1, $mastodon->sendCount());
        $this->assertSame(0, $bluesky->sendCount());
    }

    public function test_both_targets_succeeding_makes_the_post_published(): void
    {
        $this->fake(Networks::MASTODON);
        $this->fake(Networks::BLUESKY);

        $post = $this->postTo([
            $this->account(Networks::MASTODON),
            $this->account(Networks::BLUESKY),
        ]);

        $this->publisher()->publishPost($post);

        $this->assertSame(Post::PUBLISHED, $post->fresh()->status);
        $this->assertSame(2, $post->targets()->where('status', PostTarget::PUBLISHED)->count());
    }

    /* Criterion two — the one that matters ------------------------------------- */

    /**
     * "A failed target retries without resending the succeeded one."
     *
     * The assertion that carries the weight is `sendCount()`. The succeeded
     * target's `remote_id` and `published_at` being unchanged proves the row was
     * not rewritten; the send count proves nothing went to the network either,
     * which is the part a row cannot tell you.
     */
    public function test_a_failed_target_retries_without_resending_the_succeeded_one(): void
    {
        $mastodon = $this->fake(Networks::MASTODON);
        $bluesky = $this->fake(Networks::BLUESKY);
        $bluesky->failWith('the network was rate limited');

        $post = $this->postTo([
            $this->account(Networks::MASTODON),
            $this->account(Networks::BLUESKY),
        ]);

        $this->publisher()->publishPost($post);

        $succeeded = $post->targets()->where('status', PostTarget::PUBLISHED)->sole();

        $remoteId = $succeeded->remote_id;
        $remoteUrl = $succeeded->remote_url;
        $publishedAt = $succeeded->published_at;
        $attempts = $succeeded->attempts;

        $this->assertSame(1, $mastodon->sendCount());

        // Time moves on and whatever was wrong with the other network is fixed.
        Carbon::setTestNow('2026-08-03 09:45:00');
        $bluesky->succeed();

        $report = $this->publisher()->publishPost($post);

        // The one that worked was left entirely alone.
        $this->assertSame(1, $mastodon->sendCount(), 'the published target was sent to the network a second time');

        $succeeded->refresh();
        $this->assertSame($remoteId, $succeeded->remote_id);
        $this->assertSame($remoteUrl, $succeeded->remote_url);
        $this->assertTrue($publishedAt->equalTo($succeeded->published_at));
        $this->assertSame($attempts, $succeeded->attempts, 'a published target must not even be claimed');
        $this->assertSame(PostTarget::PUBLISHED, $succeeded->status);

        // The one that failed went out on the retry, and only it.
        $this->assertSame(1, $report->published);
        $this->assertSame(1, $report->untouched);
        $this->assertSame(0, $report->failed);
        $this->assertSame(1, $bluesky->sendCount());

        $this->assertSame(Post::PUBLISHED, $post->fresh()->status);
    }

    /**
     * The claim is what does it, and it is a database condition rather than code.
     *
     * Asserted directly because `Claimable` is the single point on which the
     * criterion above rests: if this scope ever starts matching `published`, the
     * retry test fails for a reason nobody would find quickly.
     */
    public function test_a_published_target_is_never_claimable(): void
    {
        $post = $this->postTo([$this->account(Networks::MASTODON), $this->account(Networks::BLUESKY)]);

        $post->targets()->first()->update(['status' => PostTarget::PUBLISHED]);

        $claimable = PostTarget::query()->claimable()->pluck('status');

        $this->assertEqualsCanonicalizing([PostTarget::PENDING], $claimable->all());
    }

    /**
     * A worker killed holding a claim must not strand the post forever.
     *
     * Forward-only status is exactly what would strand it, so the stale window
     * is the escape hatch — and it has to be a window rather than a blanket
     * retry, or a slow provider would be overtaken by a second attempt.
     */
    public function test_a_stale_publishing_claim_is_reclaimed_but_a_fresh_one_is_not(): void
    {
        $post = Post::factory()->create();

        $fresh = PostTarget::factory()->stuckPublishing(minutesAgo: 2)->create([
            'post_id' => $post->id,
            'social_account_id' => $this->account(Networks::MASTODON)->id,
        ]);

        $stale = PostTarget::factory()->stuckPublishing(minutesAgo: 60)->create([
            'post_id' => $post->id,
            'social_account_id' => $this->account(Networks::BLUESKY)->id,
        ]);

        $claimable = PostTarget::query()->claimable()->pluck('id')->all();

        $this->assertSame([$stale->id], $claimable);
        $this->assertNotContains($fresh->id, $claimable);
    }

    /* Idempotency -------------------------------------------------------------- */

    /**
     * Running the job twice changes nothing the second time.
     *
     * `updated_at` is included on purpose: the post's summary status is only
     * saved when the fill actually made it dirty, so a second run leaves even
     * the timestamps alone. Anything less than that and 'idempotent' would mean
     * 'writes the same values again', which is not the same promise.
     */
    public function test_running_the_publish_job_twice_changes_nothing_the_second_time(): void
    {
        $mastodon = $this->fake(Networks::MASTODON);
        $bluesky = $this->fake(Networks::BLUESKY);

        $post = $this->postTo([
            $this->account(Networks::MASTODON),
            $this->account(Networks::BLUESKY),
        ], ['status' => Post::SCHEDULED, 'scheduled_for' => now()->subMinute()]);

        (new PublishPost($post->id))->handle($this->publisher());

        $before = PostTarget::query()->orderBy('id')->get()->toArray();
        $postBefore = $post->fresh()->toArray();

        Carbon::setTestNow('2026-08-03 10:15:00');

        $report = (new PublishPost($post->id))->handle($this->publisher());

        $this->assertSame($before, PostTarget::query()->orderBy('id')->get()->toArray());
        $this->assertSame($postBefore, $post->fresh()->toArray());

        $this->assertSame(2, $report->untouched);
        $this->assertSame(0, $report->published);
        $this->assertSame(2, $mastodon->sendCount() + $bluesky->sendCount());
    }

    public function test_the_job_does_nothing_when_the_post_was_deleted_between_dispatch_and_run(): void
    {
        $this->fake(Networks::MASTODON);

        $post = $this->postTo([$this->account(Networks::MASTODON)]);
        $post->forceDelete();

        $report = (new PublishPost($post->id))->handle($this->publisher());

        $this->assertFalse($report->didAnything());
    }

    /* Credentials -------------------------------------------------------------- */

    /**
     * Credentials are absent on this machine and that is expected.
     *
     * The requirement is that it fails *cleanly* — into `post_targets.error`,
     * not out of the job — because a job that dies takes the post's other
     * targets with it and the retry would resend the ones that worked.
     */
    public function test_an_account_with_no_credentials_fails_into_the_target_rather_than_out_of_the_job(): void
    {
        // The real driver, deliberately: its `unavailableReason()` is the thing
        // under test, and it must decide before any request is attempted —
        // `preventStrayRequests()` proves none was.
        $account = SocialAccount::factory()->onNetwork(Networks::MASTODON)->create();

        $post = $this->postTo([$account]);

        $report = $this->publisher()->publishPost($post);

        $target = $post->targets()->sole();

        $this->assertSame(PostTarget::FAILED, $target->status);
        $this->assertStringContainsString('credentials are not configured', $target->error);
        $this->assertStringContainsString('Access token', $target->error);
        $this->assertNull($target->remote_id);

        $this->assertSame(1, $report->failed);
        $this->assertSame(Post::FAILED, $post->fresh()->status);
    }

    public function test_an_account_switched_off_says_so_rather_than_being_sent_to(): void
    {
        $account = SocialAccount::factory()->onNetwork(Networks::TELEGRAM)->connected()->inactive()->create();

        $post = $this->postTo([$account]);

        $this->publisher()->publishPost($post);

        $this->assertStringContainsString('switched off', $post->targets()->sole()->error);
    }

    public function test_a_network_with_no_driver_records_an_error_rather_than_killing_the_post(): void
    {
        $this->fake(Networks::MASTODON);

        $orphan = SocialAccount::factory()->create(['network' => 'friendfeed', 'handle' => '@nima']);

        $post = $this->postTo([$this->account(Networks::MASTODON), $orphan]);

        $report = $this->publisher()->publishPost($post);

        $this->assertSame(1, $report->published);
        $this->assertSame(1, $report->failed);
        $this->assertStringContainsString('no driver', $report->firstError());
    }

    /**
     * The credential never appears in rendered HTML.
     *
     * Asserted on a rendered page rather than on `toArray()`, because the array
     * is not where it would hurt. The `encrypted` cast *decrypts on read*, so
     * without `$hidden` a Livewire component that put the model in its payload
     * would print the plaintext into the page source.
     */
    public function test_a_credential_never_reaches_the_rendered_page(): void
    {
        $secret = 'kargah-super-secret-token-9f2b41';

        $account = SocialAccount::factory()
            ->onNetwork(Networks::MASTODON)
            ->connected($secret)
            ->create(['handle' => '@kargah@mastodon.social']);

        $this->assertSame($secret, $account->credential('access_token'));

        $user = User::factory()->create();

        foreach (['/social/accounts', '/social/accounts/connect', '/social/publish'] as $url) {
            $response = $this->actingAs($user)->get($url);

            $response->assertOk();
            $response->assertDontSee($secret, false);
            $response->assertDontSee($account->getRawOriginal('credentials_encrypted'), false);
        }

        // And it is out of the array representation, which is what a payload,
        // a JSON response and a `dd()` in a template all go through.
        $this->assertArrayNotHasKey('credentials', $account->toArray());
        $this->assertArrayNotHasKey('credentials_encrypted', $account->toArray());

        // Stored as ciphertext rather than as the value with a lock drawn on it.
        $this->assertStringNotContainsString($secret, (string) $account->getRawOriginal('credentials_encrypted'));
    }

    /* Criterion three ---------------------------------------------------------- */

    /**
     * "A scheduled post fires from cron within a minute of its time."
     *
     * The command runs every minute, so a post thirty seconds past its time is
     * picked up by the next tick and a post an hour early is not. Both halves
     * are asserted, because a command that published everything would pass the
     * first on its own.
     */
    public function test_a_scheduled_post_fires_from_cron_within_a_minute_of_its_time(): void
    {
        $mastodon = $this->fake(Networks::MASTODON);

        $due = $this->postTo([$this->account(Networks::MASTODON)], [
            'status' => Post::SCHEDULED,
            'scheduled_for' => now()->subSeconds(30),
        ]);

        $notYet = $this->postTo([$this->account(Networks::MASTODON)], [
            'status' => Post::SCHEDULED,
            'scheduled_for' => now()->addHour(),
        ]);

        $this->artisan('social:publish-due')->assertSuccessful();

        $this->assertSame(Post::PUBLISHED, $due->fresh()->status);
        $this->assertSame(PostTarget::PUBLISHED, $due->targets()->sole()->status);

        $this->assertSame(Post::SCHEDULED, $notYet->fresh()->status);
        $this->assertSame(PostTarget::PENDING, $notYet->targets()->sole()->status);

        $this->assertSame(1, $mastodon->sendCount());
    }

    public function test_the_scheduler_never_publishes_a_draft(): void
    {
        $mastodon = $this->fake(Networks::MASTODON);

        $draft = $this->postTo([$this->account(Networks::MASTODON)], [
            'status' => Post::DRAFT,
            'scheduled_for' => now()->subDay(),
        ]);

        $this->artisan('social:publish-due')->assertSuccessful();

        $this->assertSame(Post::DRAFT, $draft->fresh()->status);
        $this->assertSame(0, $mastodon->sendCount());
    }

    /**
     * The command dispatches; it never does the work itself.
     *
     * Asserted by watching the queue rather than the rows: with the jobs faked,
     * a command that published inline would leave the targets published and no
     * job on the queue, and this would fail.
     */
    public function test_the_command_dispatches_one_job_per_post_and_publishes_nothing_itself(): void
    {
        Queue::fake();

        $this->fake(Networks::MASTODON);

        $first = $this->postTo([$this->account(Networks::MASTODON)], [
            'status' => Post::SCHEDULED, 'scheduled_for' => now()->subMinutes(5),
        ]);

        $second = $this->postTo([$this->account(Networks::MASTODON)], [
            'status' => Post::SCHEDULED, 'scheduled_for' => now()->subMinutes(2),
        ]);

        $this->artisan('social:publish-due')->assertSuccessful();

        Queue::assertPushed(PublishPost::class, 2);
        Queue::assertPushed(
            fn (PublishPost $job): bool => $job->postId === $first->id,
        );

        $this->assertSame(PostTarget::PENDING, $second->targets()->sole()->status);
    }

    /** The batch is bounded, because an unbounded one is how a shared host suspends an account. */
    public function test_the_due_batch_is_bounded(): void
    {
        Queue::fake();

        $account = $this->account(Networks::MASTODON);

        for ($i = 0; $i < 5; $i++) {
            $this->postTo([$account], [
                'status' => Post::SCHEDULED,
                'scheduled_for' => now()->subMinutes(10 - $i),
            ]);
        }

        $this->artisan('social:publish-due --limit=2')->assertSuccessful();

        Queue::assertPushed(PublishPost::class, 2);
    }

    public function test_running_publish_due_twice_publishes_once(): void
    {
        $mastodon = $this->fake(Networks::MASTODON);

        $this->postTo([$this->account(Networks::MASTODON)], [
            'status' => Post::SCHEDULED,
            'scheduled_for' => now()->subMinute(),
        ]);

        $this->artisan('social:publish-due')->assertSuccessful();
        $this->artisan('social:publish-due')->assertSuccessful();

        $this->assertSame(1, $mastodon->sendCount());
    }

    /* The drivers, without the network ----------------------------------------- */

    public function test_the_mastodon_driver_publishes_and_returns_a_remote_id_and_url(): void
    {
        Http::fake([
            'mastodon.test/api/v1/statuses' => Http::response([
                'id' => '112934402118440021',
                'url' => 'https://mastodon.test/@kargah/112934402118440021',
            ]),
        ]);

        $account = $this->account(Networks::MASTODON);

        $result = (new MastodonPublisher)->publish($account, 'Shipped the board this week.');

        $this->assertSame('112934402118440021', $result->remoteId);
        $this->assertSame('https://mastodon.test/@kargah/112934402118440021', $result->remoteUrl);

        Http::assertSent(fn ($request): bool => $request['status'] === 'Shipped the board this week.'
            && $request->hasHeader('Idempotency-Key'));
    }

    public function test_a_network_that_answers_with_an_error_becomes_a_readable_target_error(): void
    {
        Http::fake([
            'mastodon.test/*' => Http::response(['error' => 'Validation failed: Text is too long'], 422),
        ]);

        $account = $this->account(Networks::MASTODON);
        $post = $this->postTo([$account]);

        $this->publisher()->publishPost($post);

        $error = $post->targets()->sole()->error;

        $this->assertStringContainsString('HTTP 422', $error);
        $this->assertStringContainsString('Text is too long', $error);
    }

    /**
     * Telegram answers HTTP 200 with `ok: false` for a refused send.
     *
     * A successful status code is therefore not evidence the message went
     * anywhere, and a driver that trusted it would mark the target published
     * with a remote id it invented.
     */
    public function test_telegram_answering_ok_false_is_a_failure_rather_than_a_publish(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'description' => 'Bad Request: chat not found',
            ]),
        ]);

        $account = $this->account(Networks::TELEGRAM);

        $this->expectException(PublishFailed::class);
        $this->expectExceptionMessage('chat not found');

        (new TelegramPublisher)->publish($account, 'Build log for the week.');
    }

    public function test_the_bluesky_driver_signs_in_then_writes_a_record(): void
    {
        Http::fake([
            'bsky.social/xrpc/com.atproto.server.createSession' => Http::response([
                'accessJwt' => 'session-token',
                'did' => 'did:plc:kargah',
                'handle' => 'kargah.bsky.social',
            ]),
            'bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response([
                'uri' => 'at://did:plc:kargah/app.bsky.feed.post/3kxq2vh7t2s2f',
                'cid' => 'bafyrei',
            ]),
        ]);

        $result = (new BlueskyPublisher)->publish($this->account(Networks::BLUESKY), 'Short enough for Bluesky.');

        $this->assertSame('at://did:plc:kargah/app.bsky.feed.post/3kxq2vh7t2s2f', $result->remoteId);
        $this->assertSame(
            'https://bsky.app/profile/kargah.bsky.social/post/3kxq2vh7t2s2f',
            $result->remoteUrl,
        );
    }

    /* Notification ingestion ---------------------------------------------------- */

    public function test_sync_notifications_writes_what_a_network_reports(): void
    {
        $mastodon = $this->fake(Networks::MASTODON);
        $mastodon->willReturnNotifications([
            FakePublisher::notification('44119', SocialNotification::REPLY),
            FakePublisher::notification('44107', SocialNotification::REPOST),
        ]);

        $account = $this->account(Networks::MASTODON);

        $this->artisan('social:sync-notifications')->assertSuccessful();

        $this->assertSame(2, SocialNotification::query()->where('social_account_id', $account->id)->count());
        $this->assertNotNull($account->fresh()->last_checked_at);
    }

    /**
     * Running it twice leaves the same rows, and leaves what the reader did alone.
     *
     * The unique index on (social_account_id, remote_id) is what makes the first
     * half true. `is_read` never being written by ingestion is what makes the
     * second half true, and it is the half that would otherwise undo somebody's
     * afternoon on the next cron tick.
     */
    public function test_running_sync_notifications_twice_changes_nothing_the_second_time(): void
    {
        $mastodon = $this->fake(Networks::MASTODON);
        $mastodon->willReturnNotifications([
            FakePublisher::notification('44119', SocialNotification::REPLY),
        ]);

        $this->account(Networks::MASTODON);

        $this->artisan('social:sync-notifications')->assertSuccessful();

        $notification = SocialNotification::query()->sole();
        $notification->update(['is_read' => true]);

        $before = $notification->fresh()->toArray();

        Carbon::setTestNow('2026-08-03 09:45:00');

        $this->artisan('social:sync-notifications')->assertSuccessful();

        $this->assertSame(1, SocialNotification::query()->count());
        $this->assertSame($before, $notification->fresh()->toArray());
        $this->assertTrue($notification->fresh()->is_read);
    }

    public function test_an_account_with_no_credentials_is_skipped_cleanly_by_the_notification_sync(): void
    {
        SocialAccount::factory()->onNetwork(Networks::MASTODON)->create();

        $this->artisan('social:sync-notifications')
            ->expectsOutputToContain('credentials are not configured')
            ->assertSuccessful();

        $this->assertSame(0, SocialNotification::query()->count());
    }

    /**
     * A network with no notifications API is skipped by name.
     *
     * LinkedIn's needs partner access nobody self-serving has, and Telegram's
     * `getUpdates` would consume the update queue the bot itself depends on.
     * Saying so is the difference between 'nothing to show' and 'nothing
     * happened'.
     */
    public function test_a_network_without_a_notifications_api_is_skipped_by_name(): void
    {
        $this->account(Networks::LINKEDIN);
        $this->account(Networks::TELEGRAM);

        $this->artisan('social:sync-notifications')
            ->expectsOutputToContain('no notifications API')
            ->assertSuccessful();

        $this->assertSame(0, SocialNotification::query()->count());
    }

    public function test_a_failing_network_is_reported_without_costing_the_others_their_feed(): void
    {
        $mastodon = $this->fake(Networks::MASTODON);
        $mastodon->willReturnNotifications([FakePublisher::notification('44119', SocialNotification::REPLY)]);

        $bluesky = $this->fake(Networks::BLUESKY);
        $bluesky->failWith('the session could not be created');

        $this->account(Networks::MASTODON);
        $failing = $this->account(Networks::BLUESKY);

        $this->artisan('social:sync-notifications')->assertFailed();

        $this->assertSame(1, SocialNotification::query()->count());
        $this->assertStringContainsString('session could not be created', $failing->fresh()->last_error);
    }

    /* The seeder ---------------------------------------------------------------- */

    /**
     * Running the seeder twice changes nothing.
     *
     * It runs from the deploy script, and a deploy that duplicated the queue
     * would send everything twice.
     */
    public function test_the_seeder_is_idempotent(): void
    {
        $this->seed(SocialDatabaseSeeder::class);

        $accounts = SocialAccount::query()->orderBy('id')->get()->toArray();
        $posts = Post::query()->orderBy('id')->get()->toArray();
        $targets = PostTarget::query()->orderBy('id')->get()->toArray();
        $notifications = SocialNotification::query()->orderBy('id')->get()->toArray();

        $this->assertNotEmpty($accounts);
        $this->assertNotEmpty($targets);

        Carbon::setTestNow('2026-08-03 14:00:00');

        $this->seed(SocialDatabaseSeeder::class);

        $this->assertSame($accounts, SocialAccount::query()->orderBy('id')->get()->toArray());
        $this->assertSame($posts, Post::query()->orderBy('id')->get()->toArray());
        $this->assertSame($targets, PostTarget::query()->orderBy('id')->get()->toArray());
        $this->assertSame($notifications, SocialNotification::query()->orderBy('id')->get()->toArray());
    }

    /** Nothing seeded carries a secret, so a fresh install cannot pretend it can publish. */
    public function test_the_seeder_connects_nothing(): void
    {
        $this->seed(SocialDatabaseSeeder::class);

        foreach (SocialAccount::query()->get() as $account) {
            $this->assertFalse($account->hasCredentials(), $account->handle.' was seeded with a credential');
        }
    }

    /* The pages ----------------------------------------------------------------- */

    public function test_the_publish_page_writes_a_post_and_a_target_per_account(): void
    {
        $mastodon = $this->fake(Networks::MASTODON);
        $account = $this->account(Networks::MASTODON);

        $this->actingAs(User::factory()->create());

        Livewire::test('social::publish')
            ->set('body', 'Wrote the publishing layer today.')
            ->set('targets', [$account->id])
            ->set('schedule', 'now')
            ->call('submit');

        $post = Post::query()->sole();

        $this->assertSame('Wrote the publishing layer today.', $post->body);
        $this->assertSame(Post::PUBLISHED, $post->status);
        $this->assertSame(PostTarget::PUBLISHED, $post->targets()->sole()->status);
        $this->assertSame(1, $mastodon->sendCount());
    }

    public function test_the_publish_page_schedules_rather_than_sending_when_asked_to(): void
    {
        $mastodon = $this->fake(Networks::MASTODON);
        $account = $this->account(Networks::MASTODON);

        $this->actingAs(User::factory()->create());

        Livewire::test('social::publish')
            ->set('body', 'Scheduled for the morning.')
            ->set('targets', [$account->id])
            ->set('schedule', 'later')
            ->set('scheduledAt', now()->addDay()->format('Y-m-d\TH:i'))
            ->call('submit');

        $post = Post::query()->sole();

        $this->assertSame(Post::SCHEDULED, $post->status);
        $this->assertSame(PostTarget::PENDING, $post->targets()->sole()->status);
        $this->assertSame(0, $mastodon->sendCount());
    }

    /**
     * An unconfigured account says credentials are missing, not that it worked.
     *
     * This is the page-level half of the requirement: the row records the
     * reason and the toast repeats it, so nothing anywhere claims a post went
     * out when it did not.
     */
    public function test_publishing_to_an_unconfigured_account_says_the_credentials_are_missing(): void
    {
        $account = SocialAccount::factory()->onNetwork(Networks::MASTODON)->create();

        $this->actingAs(User::factory()->create());

        Livewire::test('social::publish')
            ->set('body', 'This one cannot go anywhere.')
            ->set('targets', [$account->id])
            ->call('submit');

        // The page redirects to the post, so the toast is flashed rather than
        // dispatched — a dispatched event would be lost with the old page.
        $toast = session('toast');

        $this->assertSame('error', $toast['type']);
        $this->assertSame('Nothing was published', $toast['message']);
        $this->assertStringContainsString('credentials are not configured', $toast['description']);

        $this->assertStringContainsString(
            'credentials are not configured',
            Post::query()->sole()->targets()->sole()->error,
        );
    }

    public function test_the_posts_page_retries_only_the_failed_target(): void
    {
        $mastodon = $this->fake(Networks::MASTODON);
        $bluesky = $this->fake(Networks::BLUESKY);

        $post = Post::factory()->create(['status' => Post::PARTLY_FAILED]);

        $done = PostTarget::factory()->published('kept-id')->create([
            'post_id' => $post->id,
            'social_account_id' => $this->account(Networks::MASTODON)->id,
        ]);

        PostTarget::factory()->failed()->create([
            'post_id' => $post->id,
            'social_account_id' => $this->account(Networks::BLUESKY)->id,
        ]);

        $this->actingAs(User::factory()->create());

        Livewire::test('social::posts')->set('tab', 'failed')->call('retry', $post->id);

        $this->assertSame('kept-id', $done->fresh()->remote_id);
        $this->assertSame(0, $mastodon->sendCount());
        $this->assertSame(1, $bluesky->sendCount());
        $this->assertSame(Post::PUBLISHED, $post->fresh()->status);
    }

    public function test_the_notifications_page_marks_rows_read(): void
    {
        $account = $this->account(Networks::MASTODON);

        SocialNotification::factory()->count(3)->create(['social_account_id' => $account->id]);

        $this->actingAs(User::factory()->create());

        Livewire::test('social::notifications')
            ->call('markAllRead')
            // A write nobody watched: the rows it cleared are the ones the
            // filter may already have taken off the screen.
            ->assertDispatched('toast');

        $this->assertSame(0, SocialNotification::query()->unread()->count());
    }

    /**
     * An action whose whole effect is on screen says nothing.
     *
     * Picking an account, forking its copy and closing a confirmation are all
     * things you are looking at while they happen, so a toast would only read
     * the screen back to you. The assertions on state are what prove the
     * action still ran — silence is only correct if the effect is real.
     */
    public function test_the_actions_you_can_see_happen_silently(): void
    {
        $account = $this->account(Networks::MASTODON);

        $this->actingAs(User::factory()->create());

        Livewire::test('social::publish')
            ->set('body', 'Two networks, one thought.')
            ->set('targets', [])
            ->call('toggleTarget', $account->id)
            ->assertNotDispatched('toast')
            ->assertSet('targets', [$account->id])
            ->call('toggleOverride', $account->id)
            ->assertNotDispatched('toast')
            ->assertSet('overrides.'.$account->id, 'Two networks, one thought.');

        Livewire::test('social::accounts')
            ->call('confirmDisconnect', $account->id)
            ->call('cancelDisconnect')
            ->assertNotDispatched('toast')
            ->assertSet('confirming', null);

        Livewire::test('social::notifications')
            ->call('toggleUnreadOnly')
            ->assertNotDispatched('toast')
            ->assertSet('unreadOnly', true);
    }

    public function test_the_connect_page_stores_a_credential_encrypted(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('social::account-connect')
            ->call('choose', Networks::TELEGRAM)
            ->set('handle', '@kargah_buildlog')
            ->set('fields.bot_token', '7104932188:AAFsomethingsecret')
            ->set('fields.chat_id', '@kargah_buildlog')
            ->call('save');

        $account = SocialAccount::query()->sole();

        $this->assertSame(Networks::TELEGRAM, $account->network);
        $this->assertTrue($account->hasCredentials());
        $this->assertStringNotContainsString(
            '7104932188:AAFsomethingsecret',
            (string) $account->getRawOriginal('credentials_encrypted'),
        );
    }

    public function test_disconnecting_an_account_clears_the_credential(): void
    {
        $account = $this->account(Networks::MASTODON);

        $this->actingAs(User::factory()->create());

        Livewire::test('social::accounts')->call('disconnect', $account->id);

        $account->refresh();

        $this->assertFalse($account->hasCredentials());
        $this->assertFalse($account->is_active);
    }

    /** Every page renders on a seeded database as well as on an empty one. */
    public function test_every_social_page_renders_against_seeded_rows(): void
    {
        $this->seed(SocialDatabaseSeeder::class);

        $post = Post::query()->firstOrFail();

        $user = User::factory()->create();

        $urls = [
            '/social/notifications',
            '/social/publish',
            '/social/calendar',
            '/social/posts',
            '/social/posts/'.$post->id,
            '/social/accounts',
            '/social/accounts/connect',
        ];

        foreach ($urls as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }
    }
}
