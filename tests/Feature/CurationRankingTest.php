<?php

namespace Tests\Feature;

use Illuminate\Support\Carbon;
use Modules\Social\Services\Curation\Cluster;
use Modules\Social\Services\Curation\Clusterer;
use Modules\Social\Services\Curation\Ranker;
use Modules\Social\Services\Curation\Story;
use Tests\TestCase;

/**
 * Choosing the one story a day that gets published.
 *
 * Two things are being tested and they are not the same thing. **Clustering** is
 * "are these seven articles about one event", which is the only way the curator
 * can know that something matters — RSS feeds publish no engagement numbers, so
 * simultaneous coverage by independent outlets is the entire importance signal.
 * **Ranking** is "of the stories we grouped, which one today".
 *
 * The ranking tests are mostly regression pins on two calibrations that each came
 * out of a real bug in the pipeline this was ported from. Both look like arbitrary
 * formula choices and neither is, and both are the sort of thing a later reader
 * would happily "simplify" — so each has a test that fails loudly if it is undone.
 */
class CurationRankingTest extends TestCase
{
    private Clusterer $clusterer;

    private Ranker $ranker;

    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clusterer = new Clusterer;
        $this->ranker = new Ranker;
        $this->now = Carbon::parse('2026-08-18 06:00:00', 'UTC');
    }

    // ──────────────────────────────────────────────────────────── Clustering

    public function test_a_russian_and_an_english_article_about_one_story_are_one_story(): void
    {
        $clusters = $this->clusterer->build([
            $this->story('The Verge', 'https://theverge.test/openai-gpt6', 'OpenAI ships GPT-6 with a Cloudflare partnership'),
            $this->story('Habr', 'https://habr.test/openai', 'OpenAI выпустила GPT-6 в партнёрстве с Cloudflare'),
        ]);

        // This is why the signature is Latin tokens and nothing else: a Russian
        // outlet writes in Russian and keeps the proper nouns — OpenAI, GPT-6,
        // Cloudflare — in Latin. Without it the six Russian-language outlets in
        // the catalogue would be noise instead of corroboration.
        $this->assertCount(1, $clusters);
        $this->assertSame(2, $clusters[0]->sources());
    }

    public function test_two_different_stories_about_the_same_company_stay_apart(): void
    {
        $clusters = $this->clusterer->build([
            $this->story('The Verge', 'https://theverge.test/a', 'Apple releases iOS 27 with sideloading in Europe'),
            $this->story('9to5Mac', 'https://9to5mac.test/b', 'Apple discontinues the HomePod after four quiet years'),
        ]);

        // The failure mode a lower threshold gives: everything Apple did today in
        // one bucket, posted as though it were one announcement.
        $this->assertCount(2, $clusters);
    }

    public function test_the_same_article_syndicated_under_two_urls_is_one_story(): void
    {
        $clusters = $this->clusterer->build([
            $this->story('Wire A', 'https://news.test/story?utm_source=rss', 'A CVE nobody patched'),
            $this->story('Wire B', 'https://www.news.test/story/', 'A CVE nobody patched'),
        ]);

        // Certain rather than inferred, so it is checked before any similarity
        // test runs.
        $this->assertCount(1, $clusters);
    }

    public function test_a_headline_too_thin_to_identify_anything_clusters_alone(): void
    {
        $clusters = $this->clusterer->build([
            $this->story('Wire A', 'https://a.test/1', 'It is here'),
            $this->story('Wire B', 'https://b.test/2', 'And so'),
        ]);

        // A two-token signature matches almost anything, because the denominator
        // is the smaller set. Alone is merely unhelpful; matching wrongly would
        // publish one story under another story's headline.
        $this->assertCount(2, $clusters);
    }

    public function test_one_outlet_running_a_story_three_times_is_still_one_outlet(): void
    {
        $clusters = $this->clusterer->build([
            $this->story('BleepingComputer', 'https://bc.test/1', 'Ransomware group breaches Acme Corporation payroll'),
            $this->story('BleepingComputer', 'https://bc.test/2', 'Ransomware group breaches Acme Corporation payroll systems'),
            $this->story('BleepingComputer', 'https://bc.test/3', 'Ransomware group breaches Acme Corporation payroll data'),
        ]);

        // Otherwise one publisher's busy morning outranks a genuine consensus.
        $this->assertCount(1, $clusters);
        $this->assertSame(1, $clusters[0]->sources());
    }

    public function test_the_article_that_represents_a_cluster_has_a_picture(): void
    {
        $withPicture = $this->story('Low Authority', 'https://a.test/1', 'Cloudflare outage takes a national network offline', image: 'https://a.test/pic.jpg');
        $withPicture = $this->withAuthority($withPicture, 0.5);

        $withoutPicture = $this->withAuthority(
            $this->story('High Authority', 'https://b.test/2', 'Cloudflare outage takes a national network offline'),
            0.95,
        );

        $cluster = $this->clusterer->build([$withoutPicture, $withPicture])[0];

        // A picture outranks authority because every network reads better with
        // one and Instagram cannot be posted to without one at all.
        $this->assertSame('Low Authority', $cluster->lead()->label);
    }

    // ────────────────────────────────────────────────────────────── Ranking

    public function test_a_story_four_outlets_carried_beats_one_outlet_of_the_same_age(): void
    {
        $corroborated = $this->cluster([
            ['Krebs on Security', 2.0], ['The Record', 1.5], ['BleepingComputer', 1.2], ['Ars Technica', 1.0],
        ]);

        $alone = $this->cluster([['Wired', 1.0]]);

        $ranked = $this->ranker->rank([$alone, $corroborated], $this->now);

        // The whole reason clustering exists. If this fails, the ranker is
        // choosing on freshness alone and the corroboration signal is ornamental.
        $this->assertSame(4, $ranked[0]->sources);
    }

    public function test_decay_is_measured_from_the_latest_coverage_and_not_from_when_the_story_broke(): void
    {
        // Broke yesterday, and outlets were still writing about it an hour ago.
        $stillRunning = $this->cluster([
            ['Krebs on Security', 20.0], ['The Record', 6.0], ['BleepingComputer', 2.0], ['Ars Technica', 1.0],
        ]);

        // Broke an hour ago and nobody else has touched it.
        $freshButAlone = $this->cluster([['Wired', 1.0]]);

        $ranked = $this->ranker->rank([$freshButAlone, $stillRunning], $this->now);

        // 🔴 Measured from `broke()` this cluster is 20 hours old, `(20 + 2) ^ 1.8`
        // divides its score by roughly 270, and the best-corroborated story of the
        // day loses to a single write-up. That is the bug this pins: on the
        // pipeline this was ported from, a cluster with corroboration 2.53 came
        // 186th out of 450.
        $this->assertSame(4, $ranked[0]->sources);
        $this->assertEqualsWithDelta(1.0, $ranked[0]->ageHours, 0.01);
        $this->assertEqualsWithDelta(19.0, $ranked[0]->spanHours, 0.01);
    }

    public function test_the_first_outlet_earns_no_corroboration_bonus_at_all(): void
    {
        $ranked = $this->ranker->score($this->cluster([['Wired', 1.0]]), $this->now);

        // 🔴 The bonus is on `sources − 1`. An earlier form multiplied by
        // `sources ^ 0.8`, which lifted single-outlet stories by exactly as much
        // as it lifted six-outlet ones — so the gap did not move and the signal
        // did nothing. A single outlet must score precisely its own authority.
        $this->assertEqualsWithDelta(0.8, $ranked->corroboration, 0.0001);
    }

    public function test_five_outlets_in_two_hours_beats_five_outlets_over_two_days(): void
    {
        $breaking = $this->cluster([
            ['A', 3.0], ['B', 2.5], ['C', 2.0], ['D', 1.5], ['E', 1.0],
        ]);

        // Same five outlets, same latest coverage, spread across two days.
        $slowBurn = $this->cluster([
            ['A', 48.0], ['B', 36.0], ['C', 24.0], ['D', 12.0], ['E', 1.0],
        ]);

        $ranked = $this->ranker->rank([$slowBurn, $breaking], $this->now);

        // Identical corroboration and identical age; only `pickup` separates
        // them, which is exactly the "is this breaking" question it exists to ask.
        $this->assertEqualsWithDelta(2.0, $ranked[0]->spanHours, 0.01);
    }

    public function test_nothing_stays_at_the_top_forever(): void
    {
        $cluster = $this->cluster([['Krebs on Security', 1.0], ['The Record', 1.0]]);

        $fresh = $this->ranker->score($cluster, $this->now)->score;
        $aDayLater = $this->ranker->score($cluster, $this->now->copy()->addDay())->score;

        $this->assertLessThan($fresh * 0.05, $aDayLater);
    }

    // ─────────────────────────────────────────────────────────────── Helpers

    /**
     * A cluster of one story per outlet, each published a given number of hours ago.
     *
     * @param  list<array{0: string, 1: float}>  $outlets  [label, hours ago]
     */
    private function cluster(array $outlets): Cluster
    {
        $stories = [];

        foreach ($outlets as $index => [$label, $hoursAgo]) {
            $stories[] = new Story(
                uid: $label.$index,
                label: $label,
                authority: 0.8,
                title: 'A ransomware group breached Acme Corporation payroll',
                summary: 'Details of the intrusion.',
                url: 'https://'.strtolower(str_replace(' ', '', $label)).'.test/'.$index,
                publishedAt: $this->now->copy()->subMinutes((int) round($hoursAgo * 60)),
            );
        }

        return new Cluster(signature: ['ransomware', 'acme', 'payroll'], stories: $stories);
    }

    private function story(
        string $label,
        string $url,
        string $title,
        ?string $image = null,
    ): Story {
        return new Story(
            uid: $url,
            label: $label,
            authority: 0.8,
            title: $title,
            summary: '',
            url: $url,
            publishedAt: $this->now->copy()->subHour(),
            imageUrl: $image,
        );
    }

    private function withAuthority(Story $story, float $authority): Story
    {
        return new Story(
            uid: $story->uid,
            label: $story->label,
            authority: $authority,
            title: $story->title,
            summary: $story->summary,
            url: $story->url,
            publishedAt: $story->publishedAt,
            imageUrl: $story->imageUrl,
        );
    }
}
