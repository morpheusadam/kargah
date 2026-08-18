<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\Social\Models\CurationSetting;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\PostPublisher;
use Modules\Social\Services\PublishAnnouncer;
use Modules\Social\Services\Publishers\FakePublisher;
use Modules\Social\Services\Publishing;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * Telling the operator, on Telegram, that a post has gone out.
 *
 * The feature exists because of a consequence of the curator rather than as a
 * decoration: posts publish at an hour chosen at random with nobody present, so
 * the owner has no idea anything happened until they open the panel — and no idea
 * at all when it silently stops.
 *
 * Two properties carry the weight here and both are asserted rather than assumed.
 *
 * **A post that reached three networks is one message.** Three would be the
 * quickest way to get the bot muted, and a muted bot costs exactly the case this
 * exists for.
 *
 * **Nothing here may break publishing.** The post is already out by the time this
 * runs. A revoked bot token must not fail the job, because a failed job is a
 * retry, and a retry is a second publication of something that already went out.
 */
class PublishAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-18 20:14:00');
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────── When it says something

    public function test_a_published_post_is_announced_once_however_many_networks_it_reached(): void
    {
        $this->configured();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $post = $this->makePost('یک تیتر فارسی'."\n\n".'و متن خبر که ادامه دارد.');

        foreach ([Networks::LINKEDIN, Networks::TELEGRAM, Networks::THREADS] as $network) {
            $this->target($post, $network);
            app(Publishing::class)->swap(new FakePublisher($network));
        }

        app(PostPublisher::class)->publishPost($post);

        // One message for one thing that happened. Three is how a bot gets muted.
        Http::assertSentCount(1);
    }

    public function test_the_message_carries_the_headline_and_a_preview_of_the_copy(): void
    {
        $this->configured();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $post = $this->makePost(
            'گوگل یک آسیب‌پذیری بحرانی در Chrome را وصله کرد'."\n\n"
            .'این نقص به مهاجم اجازه می‌داد کد دلخواه اجرا کند.'."\n\n"
            .'#امنیت #مرورگر',
        );
        $this->target($post, Networks::TELEGRAM);
        app(Publishing::class)->swap(new FakePublisher(Networks::TELEGRAM));

        app(PostPublisher::class)->publishPost($post);

        Http::assertSent(function ($request): bool {
            $text = $request['text'] ?? $request['caption'] ?? '';

            return str_contains($text, 'PUBLISHED')
                && str_contains($text, 'Chrome')
                && str_contains($text, 'کد دلخواه')
                // The hashtag block belongs to the post, not to this message: it
                // is the least informative part of the copy and would eat the
                // preview.
                && ! str_contains($text, '#امنیت');
        });
    }

    public function test_the_message_carries_no_emoji(): void
    {
        $this->configured();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $post = $this->makePost('یک تیتر'."\n\n".'و متن.');
        $this->target($post, Networks::TELEGRAM);
        app(Publishing::class)->swap(new FakePublisher(Networks::TELEGRAM));

        app(PostPublisher::class)->publishPost($post);

        Http::assertSent(function ($request): bool {
            $text = $request['text'] ?? $request['caption'] ?? '';

            // Asked for, and right: this is a record of something that happened,
            // and a pictogram in front of it makes it read as marketing.
            return preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', $text) === 0;
        });
    }

    public function test_each_network_gets_a_button_to_the_post_that_went_out(): void
    {
        $this->configured();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $post = $this->makePost('تیتر');
        $this->target($post, Networks::TELEGRAM);
        app(Publishing::class)->swap(new FakePublisher(Networks::TELEGRAM));

        app(PostPublisher::class)->publishPost($post);

        Http::assertSent(function ($request): bool {
            $buttons = $request['reply_markup']['inline_keyboard'] ?? [];

            // `url` rather than `callback_data`, for the reason the publishing
            // side already documents: a callback needs something alive to answer
            // it, and this application exists for one cron minute.
            return $buttons !== [] && str_starts_with($buttons[0][0]['url'] ?? '', 'http');
        });
    }

    // ────────────────────────────────────────────────────── When it stays quiet

    public function test_nothing_is_sent_while_the_feature_is_switched_off(): void
    {
        // The default. A token pasted with no chat, or a chat with no token, is
        // half a configuration and must not half-work.
        CurationSetting::current()->update(['notify_enabled' => false]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $post = $this->makePost('تیتر');
        $this->target($post, Networks::TELEGRAM);
        app(Publishing::class)->swap(new FakePublisher(Networks::TELEGRAM));

        app(PostPublisher::class)->publishPost($post);

        Http::assertNothingSent();
    }

    public function test_a_run_that_published_nothing_is_not_an_event(): void
    {
        $this->configured();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $post = $this->makePost('تیتر');
        $target = $this->target($post, Networks::TELEGRAM);
        app(Publishing::class)->swap(new FakePublisher(Networks::TELEGRAM));

        app(PostPublisher::class)->publishPost($post);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        // The second run claims nothing, because `published` is terminal. Without
        // the guard, every stale-claim sweep would ping the operator about
        // yesterday's post.
        app(PostPublisher::class)->publishPost($post->fresh());

        Http::assertNothingSent();
    }

    public function test_a_refused_announcement_does_not_fail_the_publish(): void
    {
        $this->configured();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);

        $post = $this->makePost('تیتر');
        $target = $this->target($post, Networks::TELEGRAM);
        app(Publishing::class)->swap(new FakePublisher(Networks::TELEGRAM));

        $report = app(PostPublisher::class)->publishPost($post);

        // 🔴 The post is already out. A revoked token must not fail the job,
        // because a failed job is a retry and a retry is a second publication.
        $this->assertSame(1, $report->published);
        $this->assertTrue($target->fresh()->isPublished());
    }

    public function test_an_unreachable_telegram_does_not_fail_the_publish(): void
    {
        $this->configured();
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timed out');
        });

        $post = $this->makePost('تیتر');
        $this->target($post, Networks::TELEGRAM);
        app(Publishing::class)->swap(new FakePublisher(Networks::TELEGRAM));

        $report = app(PostPublisher::class)->publishPost($post);

        $this->assertSame(1, $report->published);
    }

    // ───────────────────────────────────────────────────────── The stored token

    public function test_the_bot_token_is_not_stored_in_clear_text(): void
    {
        $token = '8464432090:AA-this-is-a-fake-token-for-testing-only';

        CurationSetting::current()->update(['notify_bot_token' => $token]);

        $raw = (string) \Illuminate\Support\Facades\DB::table('curation_settings')
            ->value('notify_bot_token_encrypted');

        // The documented Eloquent mutator idiom writes a secret to the column
        // verbatim, silently, and looks right while doing it. This is the test
        // that says which form was used.
        $this->assertNotSame($token, $raw);
        $this->assertNotEmpty($raw);
        $this->assertSame($token, CurationSetting::current()->notify_bot_token);
    }

    public function test_a_test_message_can_be_sent_before_any_post_exists(): void
    {
        $this->configured();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        // The whole value of this feature is that it speaks when nobody is
        // watching, which is also why a wrong chat id would go unnoticed for days.
        $this->assertTrue(app(PublishAnnouncer::class)->test());

        Http::assertSent(fn ($request): bool => str_contains($request['text'] ?? '', 'PUBLISHED'));
    }

    // ───────────────────────────────────────────────────────────────── Helpers

    private function configured(): void
    {
        CurationSetting::current()->update([
            'notify_enabled' => true,
            'notify_bot_token' => '8464432090:AA-fake-token-for-tests-only-not-real',
            'notify_chat_id' => '123456789',
        ]);
    }

    private function makePost(string $body): Post
    {
        return Post::factory()->create(['body' => $body, 'status' => Post::SCHEDULED]);
    }

    private function target(Post $post, string $network): PostTarget
    {
        $account = SocialAccount::factory()->onNetwork($network)->create([
            'handle' => '@kargah-'.$network,
            'credentials' => ['access_token' => 't', 'member_urn' => 'urn:li:person:x', 'bot_token' => 'b', 'chat_id' => '@c'],
            'is_active' => true,
        ]);

        return PostTarget::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'status' => PostTarget::PENDING,
        ]);
    }
}
