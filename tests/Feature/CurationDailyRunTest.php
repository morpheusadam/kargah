<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\Platform\Models\AssistantProvider;
use Modules\Platform\Services\Assistant\Assistant;
use Modules\Platform\Services\Assistant\FakeAssistantDriver;
use Modules\Platform\Support\AssistantDrivers;
use Modules\Social\Models\CuratedStory;
use Modules\Social\Models\CurationFeed;
use Modules\Social\Models\CurationSetting;
use Modules\Social\Models\CurationWindow;
use Modules\Social\Models\Post;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\Curation\DailyCurator;
use Modules\Social\Services\Curation\Windows;
use Modules\Social\Support\Networks;
use Tests\TestCase;

/**
 * One day of the curator, end to end.
 *
 * The two properties this file exists to hold down:
 *
 * **Each network is scheduled in its own window.** This is the whole reason the
 * feature creates one `Post` per network rather than one post with several
 * targets. Instagram in Iran peaks in the evening; LinkedIn is read on weekday
 * mornings. A single shared slot means deliberately posting to LinkedIn at its
 * worst hour every day, and LinkedIn is the network the owner named as most
 * important.
 *
 * **A second run the same day writes nothing.** Cron misses runs and repeats
 * them; that is normal and the design has to be indifferent to it. The guarantee
 * is a unique index rather than a flag somebody remembers to check.
 */
class CurationDailyRunTest extends TestCase
{
    use RefreshDatabase;

    private FakeAssistantDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        // A Tuesday, so the weekday window applies rather than the Iranian
        // weekend one. 02:00 UTC is 05:30 in Tehran — the shape of a real run,
        // which happens before the earliest window of the day opens.
        Carbon::setTestNow('2026-08-18 02:00:00');
        Http::preventStrayRequests();

        $this->driver = new FakeAssistantDriver(AssistantDrivers::GEMINI);
        app(Assistant::class)->swap($this->driver);

        AssistantProvider::factory()->create([
            'driver' => AssistantDrivers::GEMINI,
            'api_key' => 'AIza-test',
            'is_active' => true,
        ]);

        User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ─────────────────────────────────────────────────────────── The whole run

    public function test_one_story_becomes_one_post_per_network_each_in_its_own_window(): void
    {
        $this->connect(Networks::LINKEDIN);
        $this->connect(Networks::INSTAGRAM);
        $this->windows();
        $this->oneFeed();
        $this->modelWrites([Networks::LINKEDIN, Networks::INSTAGRAM]);

        $report = app(DailyCurator::class)->run();

        $this->assertNotNull($report->chosen);
        $this->assertCount(2, $report->posts);

        $tehran = 'Asia/Tehran';
        $linkedin = Post::find($report->posts[Networks::LINKEDIN])->scheduled_for->setTimezone($tehran);
        $instagram = Post::find($report->posts[Networks::INSTAGRAM])->scheduled_for->setTimezone($tehran);

        // 🔴 The point of the whole design. A shared slot cannot be both.
        $this->assertGreaterThanOrEqual(8, $linkedin->hour);
        $this->assertLessThanOrEqual(11, $linkedin->hour);
        $this->assertGreaterThanOrEqual(19, $instagram->hour);
        $this->assertLessThanOrEqual(23, $instagram->hour);
    }

    public function test_each_network_gets_its_own_copy_rather_than_the_same_text_four_times(): void
    {
        $this->connect(Networks::LINKEDIN);
        $this->connect(Networks::TELEGRAM);
        $this->windows();
        $this->oneFeed();

        $this->driver->willReply(json_encode([
            'skip' => false,
            'networks' => [
                'linkedin' => ['body' => 'یک تحلیل بلند و حرفه‌ای برای مخاطب کاری.', 'hashtags' => ['SECURITY']],
                'telegram' => ['body' => 'کوتاه و سریع برای کانال.', 'hashtags' => ['SECURITY', 'RANSOMWARE']],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $report = app(DailyCurator::class)->run();

        $linkedin = Post::find($report->posts[Networks::LINKEDIN]);
        $telegram = Post::find($report->posts[Networks::TELEGRAM]);

        // Identical text on several networks reads as automation to a person and
        // looks like it to a platform.
        $this->assertNotSame($linkedin->body, $telegram->body);
        $this->assertStringContainsString('حرفه‌ای', $linkedin->body);
    }

    public function test_a_target_is_created_for_every_connected_account_on_a_network(): void
    {
        $this->connect(Networks::TELEGRAM, '@channel_one');
        $this->connect(Networks::TELEGRAM, '@channel_two');
        $this->windows();
        $this->oneFeed();
        $this->modelWrites([Networks::TELEGRAM]);

        $report = app(DailyCurator::class)->run();

        // Two channels, one post, one delivery record each — which is exactly
        // what `post_targets` is for.
        $this->assertCount(2, Post::find($report->posts[Networks::TELEGRAM])->targets);
    }

    public function test_running_again_the_same_day_creates_nothing(): void
    {
        $this->connect(Networks::LINKEDIN);
        $this->windows();
        $this->oneFeed();
        $this->modelWrites([Networks::LINKEDIN]);

        app(DailyCurator::class)->run();
        $after = Post::count();

        $this->modelWrites([Networks::LINKEDIN]);
        app(DailyCurator::class)->run();

        // Cron misses runs and repeats them. The story is in `curated_stories`
        // with a unique url_key, so the second run finds no candidate at all.
        $this->assertSame($after, Post::count());
        $this->assertSame(1, CuratedStory::count());
    }

    public function test_a_dry_run_writes_nothing_and_forgets_nothing(): void
    {
        $this->connect(Networks::LINKEDIN);
        $this->windows();
        $this->oneFeed();
        $this->modelWrites([Networks::LINKEDIN]);

        $report = app(DailyCurator::class)->run(dryRun: true);

        $this->assertNotNull($report->chosen);
        $this->assertSame(0, Post::count());
        // Forgetting nothing is the half that makes it useful: a real run
        // afterwards makes exactly the same choice.
        $this->assertSame(0, CuratedStory::count());
    }

    // ──────────────────────────────────────────────────────── When it will not

    public function test_a_story_the_model_refuses_is_remembered_so_it_is_not_re_purchased(): void
    {
        $this->connect(Networks::LINKEDIN);
        $this->windows();
        $this->oneFeed();

        $this->driver->willReply('{"skip": true, "reason": "خبر ورزشی است."}');

        app(DailyCurator::class)->run();

        // Every one of the forty feeds will still be carrying this article
        // tomorrow. Without the record, the same verdict is bought again from a
        // daily free-tier quota every morning.
        $skipped = CuratedStory::query()->where('was_skipped', true)->first();
        $this->assertNotNull($skipped);
        $this->assertSame(0, Post::count());
    }

    public function test_a_dead_feed_is_reported_and_the_run_carries_on(): void
    {
        $this->connect(Networks::LINKEDIN);
        $this->windows();

        CurationFeed::query()->create(['label' => 'Dead Wire', 'url' => 'https://dead.test/feed', 'authority' => 0.8]);
        CurationFeed::query()->create(['label' => 'Live Wire', 'url' => 'https://live.test/feed', 'authority' => 0.8]);

        Http::fake([
            'dead.test/*' => Http::response('gone', 503),
            'live.test/*' => Http::response($this->feed(), 200),
            'bleeping.test/*' => Http::response('<html><body></body></html>', 200, ['content-type' => 'text/html']),
        ]);

        $this->modelWrites([Networks::LINKEDIN]);

        $report = app(DailyCurator::class)->run();

        // Forty outlets exist precisely so the day does not depend on any of them.
        $this->assertNotEmpty($report->problems);
        $this->assertNotNull($report->chosen);
    }

    public function test_with_no_connected_account_the_run_stops_and_says_why(): void
    {
        $this->windows();
        $this->oneFeed();

        $report = app(DailyCurator::class)->run();

        $this->assertNull($report->chosen);
        $this->assertStringContainsString('account', (string) $report->stoppedBecause);
    }

    public function test_a_network_switched_off_in_settings_is_not_posted_to(): void
    {
        $this->connect(Networks::LINKEDIN);
        $this->connect(Networks::INSTAGRAM);
        $this->windows();

        CurationWindow::query()->where('network', Networks::INSTAGRAM)->update(['is_active' => false]);

        $this->oneFeed();
        $this->modelWrites([Networks::LINKEDIN, Networks::INSTAGRAM]);

        $report = app(DailyCurator::class)->run();

        // Switching a network off must not require disconnecting the account.
        $this->assertArrayHasKey(Networks::LINKEDIN, $report->posts);
        $this->assertArrayNotHasKey(Networks::INSTAGRAM, $report->posts);
    }

    // ───────────────────────────────────────────────────────────── The command

    public function test_the_command_does_nothing_while_curation_is_switched_off(): void
    {
        $this->connect(Networks::LINKEDIN);
        $this->windows();
        $this->oneFeed();

        // Off by default. A deploy must not be what starts posting to live
        // accounts with nobody present.
        $this->artisan('social:curate-daily')
            ->expectsOutputToContain('switched off')
            ->assertSuccessful();

        $this->assertSame(0, Post::count());
    }

    public function test_a_quiet_day_exits_successfully_rather_than_failing_the_cron(): void
    {
        $this->connect(Networks::LINKEDIN);
        $this->windows();
        CurationSetting::current()->update(['is_enabled' => true]);

        Http::fake(['*' => Http::response('<?xml version="1.0"?><rss version="2.0"><channel/></rss>', 200)]);

        // A non-zero status from cron is a mail to somebody's inbox every morning
        // until they stop reading them, and a slow news day is not a fault.
        $this->artisan('social:curate-daily')->assertSuccessful();
    }

    // ─────────────────────────────────────────────────────────────── Windows

    public function test_the_scheduled_minute_is_not_the_same_every_day(): void
    {
        $this->windows();

        $minutes = [];

        for ($i = 0; $i < 25; $i++) {
            $minutes[] = Windows::make()->slotFor(Networks::INSTAGRAM)->format('H:i');
        }

        // The owner asked for a completely random time. A channel that posts at
        // 21:03, 21:07, 20:58 is a channel that posts at nine.
        $this->assertGreaterThan(5, count(array_unique($minutes)));
    }

    public function test_linkedin_moves_to_its_weekend_window_on_the_iranian_weekend(): void
    {
        $this->windows();

        // Thursday: the Iranian weekend, when LinkedIn is dead.
        $friday = Carbon::parse('2026-08-20 06:00:00', 'Asia/Tehran');

        $slot = Windows::make()->slotFor(Networks::LINKEDIN, $friday)->setTimezone('Asia/Tehran');

        $this->assertGreaterThanOrEqual(10, $slot->hour);
        $this->assertLessThanOrEqual(12, $slot->hour);
    }

    public function test_a_network_with_no_window_row_still_gets_a_slot(): void
    {
        // Connecting a seventeenth account must not need a migration before it
        // can be posted to.
        $slot = Windows::make()->slotFor('some_new_network')->setTimezone('Asia/Tehran');

        $this->assertGreaterThanOrEqual(20, $slot->hour);
    }

    // ───────────────────────────────────────────────────────────────── Helpers

    private function connect(string $network, string $handle = '@kargah'): SocialAccount
    {
        return SocialAccount::factory()->onNetwork($network)->create([
            'handle' => $handle.'-'.$network,
            'credentials' => ['access_token' => 'test-token', 'member_urn' => 'urn:li:person:x', 'chat_id' => '@c', 'bot_token' => 'b', 'ig_user_id' => '1'],
            'is_active' => true,
            'created_by' => User::query()->value('id'),
        ]);
    }

    private function windows(): void
    {
        // Deliberately does *not* switch curation on. `DailyCurator::run()` does
        // not read that flag — only the command does — and a helper that quietly
        // enabled the feature would make the "it stays off" test pass for the
        // wrong reason, which is how that test failed the first time it was run.
        CurationSetting::current()->update(['timezone' => 'Asia/Tehran']);

        CurationWindow::query()->create([
            'network' => Networks::LINKEDIN,
            'starts_at' => '08:00', 'ends_at' => '11:30',
            'weekend_starts_at' => '10:00', 'weekend_ends_at' => '12:00',
            'hashtags_min' => 2, 'hashtags_max' => 3,
        ]);

        CurationWindow::query()->create([
            'network' => Networks::INSTAGRAM,
            'starts_at' => '19:00', 'ends_at' => '23:00',
            'hashtags_min' => 18, 'hashtags_max' => 25,
        ]);

        CurationWindow::query()->create([
            'network' => Networks::TELEGRAM,
            'starts_at' => '20:00', 'ends_at' => '23:30',
            'hashtags_min' => 2, 'hashtags_max' => 3,
        ]);
    }

    private function oneFeed(): void
    {
        CurationFeed::query()->create([
            'label' => 'BleepingComputer',
            'url' => 'https://feeds.test/security',
            'authority' => 0.9,
        ]);

        Http::fake([
            'feeds.test/*' => Http::response($this->feed(), 200),
            'bleeping.test/*' => Http::response(
                '<html><body><article>'
                .'<p>'.str_repeat('A ransomware group breached the payroll provider on Tuesday morning. ', 10).'</p>'
                .'</article></body></html>',
                200,
                ['content-type' => 'text/html'],
            ),
        ]);
    }

    /** @param  list<string>  $networks */
    private function modelWrites(array $networks): void
    {
        $written = [];

        foreach ($networks as $network) {
            $written[$network] = [
                'body' => 'گروه باج‌افزاری به سیستم حقوق و دستمزد Acme نفوذ کرد.',
                'hashtags' => ['SECURITY', 'RANSOMWARE'],
            ];
        }

        $this->driver->willReply(json_encode(
            ['skip' => false, 'networks' => $written],
            JSON_UNESCAPED_UNICODE,
        ));
    }

    private function feed(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0"><channel>
              <item>
                <title>Ransomware group breaches Acme Corporation payroll provider</title>
                <link>https://bleeping.test/acme-payroll</link>
                <guid>bleeping-acme-1</guid>
                <pubDate>Tue, 18 Aug 2026 01:00:00 +0000</pubDate>
                <description>A ransomware group breached the payroll provider used by several thousand employees, exposing records across four countries in the process.</description>
              </item>
            </channel></rss>
            XML;
    }
}
