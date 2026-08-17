<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Modules\Social\Database\Seeders\CurationSeeder;
use Modules\Social\Models\CurationFeed;
use Modules\Social\Models\CurationSetting;
use Modules\Social\Models\CurationWindow;
use Modules\Social\Services\Curation\Catalogue;
use Modules\Social\Services\Curation\Sources\HackerNews;
use Modules\Social\Services\Curation\Sources\Lobsters;
use Modules\Social\Services\Curation\Sources\RssFeed;
use Modules\Social\Services\Curation\Sources\SourceFailed;
use Modules\Social\Services\Curation\Story;
use Tests\TestCase;

/**
 * The fetch layer of the daily curator: forty outlets, three shapes.
 *
 * These tests are mostly about **not losing stories to somebody else's markup**.
 * The pipeline reads feeds nobody here controls, in two dialects, with dates in
 * two formats and images in five different tags, and every one of those variations
 * is a real outlet in the shipped catalogue rather than a hypothetical. A parser
 * that quietly drops half a feed looks identical from the outside to a quiet news
 * day, which is why the dropping cases are asserted explicitly.
 *
 * The other half is the boundary between a source that has nothing and a source
 * that is broken. `[]` and `SourceFailed` must not be interchangeable: forty
 * sources returning nothing is a working pipeline on a slow Sunday, and forty
 * sources throwing is a pipeline that has stopped.
 */
class CurationSourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-18 06:00:00');

        // Nothing in this file may reach a real host. The catalogue it exercises
        // is forty live news sites.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ───────────────────────────────────────────────────────────── RSS and Atom

    public function test_an_rss_two_feed_is_read_whole(): void
    {
        Http::fake(['news.test/*' => Http::response($this->rss(), 200)]);

        $stories = (new RssFeed('https://news.test/feed', 'Test Wire', 0.8))->fetch();

        $this->assertCount(2, $stories);

        $first = $stories[0];
        $this->assertSame('Cloudflare turns off a country', $first->title);
        $this->assertStringContainsString('routing change', $first->summary);
        $this->assertSame('https://news.test/cloudflare-outage', $first->url);
        $this->assertSame('Test Wire', $first->label);
        $this->assertSame(0.8, $first->authority);
        $this->assertSame('news.test', $first->publisher);
        // The `<guid>` rather than the link: it survives the headline being
        // edited, which is the whole reason it is preferred.
        $this->assertSame('guid-outage-1', $first->uid);
        $this->assertSame('https://news.test/img/outage.jpg', $first->imageUrl);
    }

    public function test_an_atom_feed_is_read_whole(): void
    {
        Http::fake(['register.test/*' => Http::response($this->atom(), 200)]);

        $stories = (new RssFeed('https://register.test/headlines.atom', 'The Register Test', 0.8))->fetch();

        $this->assertCount(1, $stories);
        $this->assertSame('A CVE nobody patched', $stories[0]->title);
        // Atom puts the address in an attribute, and `rel="replies"` in the same
        // entry must not win it.
        $this->assertSame('https://register.test/cve-2026-1234', $stories[0]->url);
        $this->assertSame('atom-id-9', $stories[0]->uid);
        $this->assertSame('2026-08-18 04:30:00', $stories[0]->publishedAt->format('Y-m-d H:i:s'));
    }

    public function test_entries_come_back_newest_first_however_the_feed_ordered_them(): void
    {
        Http::fake(['news.test/*' => Http::response($this->rss(), 200)]);

        $stories = (new RssFeed('https://news.test/feed', 'Test Wire'))->fetch();

        // The fixture lists the older item first on purpose; OpenAI's and
        // Hugging Face's real feeds are not reliably ordered either.
        $this->assertTrue($stories[0]->publishedAt->greaterThan($stories[1]->publishedAt));
    }

    public function test_an_entry_with_no_link_or_no_readable_date_is_dropped_and_the_rest_survive(): void
    {
        $feed = <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0"><channel>
              <item><title>No link at all</title><pubDate>Mon, 18 Aug 2026 05:00:00 +0000</pubDate></item>
              <item><title>Unreadable date</title><link>https://news.test/b</link><pubDate>whenever</pubDate></item>
              <item><title>Perfectly fine</title><link>https://news.test/c</link><pubDate>Mon, 18 Aug 2026 05:30:00 +0000</pubDate></item>
            </channel></rss>
            XML;

        Http::fake(['news.test/*' => Http::response($feed, 200)]);

        $stories = (new RssFeed('https://news.test/feed', 'Test Wire'))->fetch();

        // One bad date must not cost the twenty good items around it.
        $this->assertCount(1, $stories);
        $this->assertSame('Perfectly fine', $stories[0]->title);
    }

    public function test_markup_and_feed_boilerplate_are_stripped_from_the_text(): void
    {
        $feed = <<<'XML'
            <?xml version="1.0"?>
            <rss version="2.0"><channel>
              <item>
                <title>Ampersands &amp; angle brackets</title>
                <link>https://news.test/a</link>
                <pubDate>Mon, 18 Aug 2026 05:00:00 +0000</pubDate>
                <description><![CDATA[<p>Real prose here.</p>
            Article URL: https://news.test/a
            Points: 41
            # Comments: 12]]></description>
              </item>
            </channel></rss>
            XML;

        Http::fake(['news.test/*' => Http::response($feed, 200)]);

        $story = (new RssFeed('https://news.test/feed', 'Test Wire'))->fetch()[0];

        $this->assertSame('Ampersands & angle brackets', $story->title);
        $this->assertStringContainsString('Real prose here.', $story->summary);
        // These lines are identical on every item the generator produces, so they
        // are noise to the model and poison to a cluster signature.
        $this->assertStringNotContainsString('Article URL', $story->summary);
        $this->assertStringNotContainsString('Points:', $story->summary);
        $this->assertStringNotContainsString('<p>', $story->summary);
    }

    public function test_a_feed_that_does_not_parse_fails_rather_than_reading_as_empty(): void
    {
        Http::fake(['news.test/*' => Http::response('<rss><channel><item>unclosed', 200)]);

        $this->expectException(SourceFailed::class);
        $this->expectExceptionMessage('Test Wire');

        (new RssFeed('https://news.test/feed', 'Test Wire'))->fetch();
    }

    public function test_an_http_error_names_the_source_and_the_status(): void
    {
        Http::fake(['news.test/*' => Http::response('nope', 503)]);

        $this->expectException(SourceFailed::class);
        $this->expectExceptionMessage('answered HTTP 503');

        (new RssFeed('https://news.test/feed', 'Test Wire'))->fetch();
    }

    public function test_a_feed_with_no_items_is_empty_rather_than_broken(): void
    {
        Http::fake(['news.test/*' => Http::response('<?xml version="1.0"?><rss version="2.0"><channel><title>Quiet</title></channel></rss>', 200)]);

        // The distinction this asserts is the one the whole run depends on: a
        // quiet Sunday and a dead parser must not look the same.
        $this->assertSame([], (new RssFeed('https://news.test/feed', 'Test Wire'))->fetch());
    }

    // ─────────────────────────────────────────────────────────────── Aggregators

    public function test_hacker_news_reads_hits_and_asks_algolia_to_apply_the_threshold(): void
    {
        Http::fake(['hn.algolia.com/*' => Http::response([
            'hits' => [
                [
                    'objectID' => '41234567',
                    'title' => 'A compiler written in a weekend',
                    'url' => 'https://blog.example.com/compiler',
                    'created_at' => '2026-08-18T04:00:00.000Z',
                    'points' => 180,
                    'num_comments' => 60,
                ],
            ],
        ], 200)]);

        $stories = (new HackerNews(minPoints: 75))->fetch();

        $this->assertCount(1, $stories);
        $this->assertSame('hn:41234567', $stories[0]->uid);
        // The article, not the discussion — a post crediting the aggregator reads
        // as though the aggregator wrote it.
        $this->assertSame('https://blog.example.com/compiler', $stories[0]->url);
        $this->assertSame('https://news.ycombinator.com/item?id=41234567', $stories[0]->discussionUrl);
        $this->assertSame('blog.example.com', $stories[0]->publisher);
        // 180 points + 2 × 60 comments: a story being argued about beats one
        // merely upvoted, which is what makes this a usable cluster tiebreak.
        $this->assertSame(300, $stories[0]->engagement);

        // The gate is in the query, so a run costs one request whatever it is set to.
        Http::assertSent(fn ($request) => $request['numericFilters'] === 'points>75');
    }

    public function test_an_ask_hn_with_no_link_of_its_own_points_at_the_discussion(): void
    {
        Http::fake(['hn.algolia.com/*' => Http::response([
            'hits' => [[
                'objectID' => '41234999',
                'title' => 'Ask HN: how do you test cron?',
                'url' => null,
                'story_text' => 'A question about scheduling.',
                'created_at' => '2026-08-18T05:00:00.000Z',
                'points' => 90,
                'num_comments' => 30,
            ]],
        ], 200)]);

        $story = (new HackerNews)->fetch()[0];

        // Otherwise the source button on the published post goes nowhere.
        $this->assertSame('https://news.ycombinator.com/item?id=41234999', $story->url);
        $this->assertNull($story->publisher);
    }

    public function test_lobsters_drops_anything_under_its_engagement_floor(): void
    {
        Http::fake(['lobste.rs/*' => Http::response([
            [
                'short_id' => 'aaaaaa',
                'title' => 'A three point story',
                'url' => 'https://small.example.com/a',
                'comments_url' => 'https://lobste.rs/s/aaaaaa',
                'created_at' => '2026-08-18T05:00:00.000Z',
                'score' => 3,
                'comment_count' => 0,
                'tags' => ['rust'],
            ],
            [
                'short_id' => 'bbbbbb',
                'title' => 'A real discussion',
                'url' => 'https://good.example.com/b',
                'comments_url' => 'https://lobste.rs/s/bbbbbb',
                'created_at' => '2026-08-18T05:00:00.000Z',
                'score' => 40,
                'comment_count' => 12,
                'tags' => ['security', 'privacy'],
            ],
        ], 200)]);

        $stories = (new Lobsters(minEngagement: 25))->fetch();

        // Without the floor, the three-point story reached the channel because it
        // was "three times normal for Lobsters". This is that bug, pinned.
        $this->assertCount(1, $stories);
        $this->assertSame('lobsters:bbbbbb', $stories[0]->uid);
        $this->assertSame('security, privacy', $stories[0]->summary);
    }

    // ─────────────────────────────────────────────────────────────────── Story

    public function test_the_url_key_collapses_the_ways_one_article_is_written_twice(): void
    {
        $keys = array_map(
            fn (string $url): string => $this->story($url)->urlKey(),
            [
                'https://www.example.com/a-story/',
                'https://example.com/a-story',
                'https://example.com/a-story/amp',
                'https://example.com/a-story?utm_source=rss&utm_medium=feed',
            ],
        );

        // All four are the same article, and `?utm_source=` differing between two
        // feeds is the commonest way a duplicate gets past a URL comparison.
        $this->assertSame(['example.com/a-story'], array_unique($keys));
    }

    public function test_a_publication_time_in_the_future_cannot_win_by_having_a_negative_age(): void
    {
        $story = $this->story('https://example.com/a', Carbon::now()->addHour());

        // Clocks differ, and the ranker divides by (age + 2) ^ 1.8.
        $this->assertGreaterThan(0, $story->ageHours());
    }

    // ─────────────────────────────────────────────────────────────── Catalogue

    public function test_the_catalogue_is_built_from_rows_rather_than_config(): void
    {

        CurationFeed::query()->create([
            'label' => 'Only Outlet',
            'url' => 'https://only.test/feed',
            'authority' => 0.9,
            'sort_order' => 10,
        ]);

        $labels = array_map(
            fn ($source): string => $source->label(),
            (new Catalogue)->sources(),
        );

        // Aggregators first: they carry what the feeds have not caught up with.
        $this->assertSame(['Hacker News', 'Lobsters', 'Only Outlet'], $labels);
    }

    public function test_switching_a_feed_off_on_the_settings_page_takes_it_out_of_the_run(): void
    {

        CurationFeed::query()->create(['label' => 'Kept', 'url' => 'https://a.test/feed']);
        CurationFeed::query()->create(['label' => 'Dropped', 'url' => 'https://b.test/feed', 'is_active' => false]);

        $labels = array_map(fn ($source): string => $source->label(), (new Catalogue)->sources());

        $this->assertContains('Kept', $labels);
        $this->assertNotContains('Dropped', $labels);
    }

    public function test_an_aggregator_switched_off_is_not_built_at_all(): void
    {

        CurationSetting::current()->update(['hackernews_enabled' => false]);

        $labels = array_map(fn ($source): string => $source->label(), (new Catalogue)->sources());

        $this->assertNotContains('Hacker News', $labels);
        $this->assertContains('Lobsters', $labels);
    }

    public function test_a_broken_row_is_reported_rather_than_losing_the_day(): void
    {

        CurationFeed::query()->create(['label' => 'Fine', 'url' => 'https://fine.test/feed']);
        CurationFeed::query()->create(['label' => 'Mangled', 'url' => 'not-a-url']);
        CurationFeed::query()->create(['label' => 'Odd authority', 'url' => 'https://odd.test/feed', 'authority' => 4.0]);

        $catalogue = new Catalogue;
        $labels = array_map(fn ($source): string => $source->label(), $catalogue->sources());

        // The mangled one goes; the odd authority is clamped and kept, because
        // dropping the outlet changes the ranking more than the mistake did.
        $this->assertNotContains('Mangled', $labels);
        $this->assertContains('Odd authority', $labels);

        $problems = implode(' ', $catalogue->problems());
        $this->assertStringContainsString('Mangled', $problems);
        $this->assertStringContainsString('Odd authority', $problems);
    }

    // ───────────────────────────────────────────────────────────────── Seeding

    public function test_the_seeder_fills_the_catalogue_and_the_windows(): void
    {

        $this->seed(CurationSeeder::class);

        $this->assertGreaterThan(30, CurationFeed::query()->count());
        $this->assertTrue(CurationFeed::query()->where('label', 'Krebs on Security')->exists());

        // LinkedIn's window is the one the whole per-network design exists for:
        // a weekday morning, where Instagram's is an Iranian evening.
        $linkedin = CurationWindow::query()->where('network', 'linkedin')->firstOrFail();
        $instagram = CurationWindow::query()->where('network', 'instagram')->firstOrFail();

        $this->assertSame('08:00', $linkedin->starts_at);
        $this->assertSame('19:00', $instagram->starts_at);

        // 🔴 The hashtag budgets are the resolution of a real conflict: dense
        // tagging is wanted, and on LinkedIn ten or more hashtags costs reach.
        $this->assertLessThanOrEqual(3, $linkedin->hashtags_max);
        $this->assertGreaterThanOrEqual(18, $instagram->hashtags_max);
    }

    public function test_the_curator_is_off_until_somebody_turns_it_on(): void
    {

        $this->seed(CurationSeeder::class);

        // A migration and a deploy must not be what starts posting to live
        // accounts with nobody present.
        $this->assertFalse(CurationSetting::current()->is_enabled);
    }

    public function test_reseeding_does_not_undo_an_operators_edits(): void
    {

        $this->seed(CurationSeeder::class);

        CurationFeed::query()->where('label', 'TechRadar')->update(['is_active' => false, 'authority' => 0.2]);
        CurationSetting::current()->update(['is_enabled' => true, 'max_age_hours' => 24]);

        // A deploy re-runs the seeders. An `updateOrCreate` here would silently
        // switch TechRadar back on and put the window back to 72 hours, and the
        // operator would have no way to notice.
        $this->seed(CurationSeeder::class);

        $techRadar = CurationFeed::query()->where('label', 'TechRadar')->firstOrFail();
        $this->assertFalse($techRadar->is_active);
        $this->assertSame(0.2, $techRadar->authority);

        $settings = CurationSetting::current();
        $this->assertTrue($settings->is_enabled);
        $this->assertSame(24, $settings->max_age_hours);
    }

    public function test_a_feed_shipped_later_arrives_on_the_next_deploy(): void
    {

        $this->seed(CurationSeeder::class);
        $before = CurationFeed::query()->count();

        CurationFeed::query()->where('label', 'Phoronix')->delete();
        $this->seed(CurationSeeder::class);

        // The useful half of re-running, and the only half worth having.
        $this->assertSame($before, CurationFeed::query()->count());
    }

    // ───────────────────────────────────────────────────────────────── Helpers

    private function story(string $url, ?Carbon $at = null): Story
    {
        return new Story(
            uid: $url,
            label: 'Test Wire',
            authority: 0.8,
            title: 'A story',
            summary: 'Something happened.',
            url: $url,
            publishedAt: $at ?? Carbon::now()->subHour(),
        );
    }

    /** RSS 2.0, deliberately with the older item first. */
    private function rss(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
              <channel>
                <title>Test Wire</title>
                <item>
                  <title>An older story</title>
                  <link>https://news.test/older</link>
                  <guid>guid-older-0</guid>
                  <pubDate>Sun, 17 Aug 2026 09:00:00 +0000</pubDate>
                  <description>Something from yesterday.</description>
                </item>
                <item>
                  <title>Cloudflare turns off a country</title>
                  <link>https://news.test/cloudflare-outage</link>
                  <guid>guid-outage-1</guid>
                  <pubDate>Mon, 18 Aug 2026 05:00:00 +0000</pubDate>
                  <description>A routing change took a national network offline for six hours.</description>
                  <media:content url="https://news.test/img/outage.jpg" medium="image"/>
                </item>
              </channel>
            </rss>
            XML;
    }

    /** Atom, with a `rel="replies"` link that must not be mistaken for the article. */
    private function atom(): string
    {
        return <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <feed xmlns="http://www.w3.org/2005/Atom">
              <title>The Register Test</title>
              <entry>
                <title>A CVE nobody patched</title>
                <id>atom-id-9</id>
                <link rel="replies" href="https://register.test/cve-2026-1234/comments"/>
                <link rel="alternate" href="https://register.test/cve-2026-1234"/>
                <published>2026-08-18T04:30:00Z</published>
                <summary>Six months after disclosure, the patch is still not applied anywhere.</summary>
              </entry>
            </feed>
            XML;
    }
}
