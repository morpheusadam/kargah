<?php

namespace Modules\Social\Services\Curation;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Contracts\TextGenerationFailed;
use Modules\Data\Contracts\AttachmentService;
use Modules\Social\Models\CuratedStory;
use Modules\Social\Models\CurationSetting;
use Modules\Social\Models\Post;
use Modules\Social\Models\PostTarget;
use Modules\Social\Models\SocialAccount;

/**
 * One story a day, written for each network and scheduled at its own hour.
 *
 * This is the orchestrator; every part it uses is tested on its own. What lives
 * here is the sequence and the decisions between the steps.
 *
 * ```
 * every source            → stories          (a dead feed is reported, not fatal)
 * minus what we have seen → candidates       (published and refused both count)
 * clustered and ranked    → the day's story
 * the article page        → full text and a cover
 * one model request       → copy per network
 * one Post per network    → scheduled at a random minute in that network's window
 * ```
 *
 * ## Why one Post per network rather than one post with several targets
 *
 * `scheduled_for` lives on the post, so a single post is a single instant, and the
 * research says the good instants are at opposite ends of the day: Instagram in
 * Iran peaks 19:00–23:00 and LinkedIn is read on weekday mornings. One shared slot
 * would mean deliberately posting to LinkedIn at its worst hour every day, and
 * LinkedIn is the network the owner named as most important. Splitting also gives
 * each network its own copy, which is what keeps the same text off four feeds at
 * once.
 *
 * The cost is that `/social/posts` shows several rows a day rather than one, which
 * is what `curated_story_posts` and the curated log page are for.
 *
 * ## Idempotence
 *
 * Running twice on one day writes nothing the second time. The unique index on
 * `curated_story_posts (curated_story_id, network)` is the guarantee rather than a
 * flag somebody has to remember to check, and the story's `url_key` is unique, so
 * even a story arriving under a second guid cannot be chosen twice.
 */
class DailyCurator
{
    public function __construct(
        private readonly Catalogue $catalogue,
        private readonly Clusterer $clusterer,
        private readonly Ranker $ranker,
        private readonly Copywriter $copywriter,
        private readonly ArticleText $article,
        private readonly Cover $cover,
        private readonly AttachmentService $attachments,
    ) {}

    /**
     * Choose, write and schedule the day's post.
     *
     * `$dryRun` runs everything up to and including the model request and then
     * writes nothing — including no record that the story was seen, so a real run
     * afterwards makes exactly the same choice. That is what makes it useful for
     * tuning the windows and reading the copy before any of it is live.
     */
    public function run(bool $dryRun = false, ?Carbon $now = null): CurationReport
    {
        $report = new CurationReport;
        $settings = CurationSetting::current();
        $now = $now ?? Carbon::now('UTC');

        $stories = $this->collect($report);
        $report->storiesRead = count($stories);

        $fresh = $this->fresh($stories, $settings, $now);

        if ($fresh === []) {
            $report->stoppedBecause = 'Nothing new is inside the age window.';

            return $report;
        }

        $clusters = $this->clusterer->build($fresh);
        $report->clustersFound = count($clusters);
        $report->considered = $this->ranker->rank($clusters, $now);

        $networks = $this->networksToPostTo();

        if ($networks === []) {
            $report->stoppedBecause = 'No active social account has a posting window switched on.';

            return $report;
        }

        return $this->write($report, $settings, $networks, $dryRun, $now);
    }

    /**
     * Read every source, letting each fail on its own.
     *
     * A dead feed is a warning and the run continues. Forty outlets exist so that
     * the day does not depend on any of them.
     *
     * @return list<Story>
     */
    private function collect(CurationReport $report): array
    {
        $stories = [];

        foreach ($this->catalogue->sources() as $source) {
            $reason = $source->unavailableReason();

            if ($reason !== null) {
                $report->problem($source->label().' was skipped: '.$reason);

                continue;
            }

            try {
                $stories = [...$stories, ...$source->fetch()];
            } catch (\Throwable $e) {
                $report->problem($source->label().' failed: '.$e->getMessage());
            }
        }

        foreach ($this->catalogue->problems() as $problem) {
            $report->problem($problem);
        }

        return $stories;
    }

    /**
     * Candidates: unseen, inside the window, and with enough text to write from.
     *
     * The age window is per source where one is set, because the digital-rights
     * outlets publish every few days and a general window would mean they never
     * once got a turn.
     *
     * @param  list<Story>  $stories
     * @return list<Story>
     */
    private function fresh(array $stories, CurationSetting $settings, Carbon $now): array
    {
        $fresh = [];

        foreach ($stories as $story) {
            $window = $story->maxAgeHours ?? (float) $settings->max_age_hours;

            if ($story->ageHours($now) > $window) {
                continue;
            }

            // Aggregator entries are exempt from the length floor: a Hacker News
            // headline with four hundred points behind it has already been
            // vouched for, and the article text comes from the page anyway.
            if ($story->engagement === 0
                && mb_strlen($story->summary) < $settings->min_summary_length) {
                continue;
            }

            if (CuratedStory::alreadySeen($story)) {
                continue;
            }

            $fresh[] = $story;
        }

        return $fresh;
    }

    /**
     * Try candidates in order until one is publishable.
     *
     * The model refuses stories as off-topic, and one refusal must not cost the
     * day — which is what `spare_candidates` is for. Every refusal is recorded, so
     * tomorrow's run does not buy the same verdict again from a daily quota.
     *
     * @param  array<string, list<SocialAccount>>  $networks
     */
    private function write(
        CurationReport $report,
        CurationSetting $settings,
        array $networks,
        bool $dryRun,
        Carbon $now,
    ): CurationReport {
        $attempts = max(1, $settings->spare_candidates + 1);

        foreach (array_slice($report->considered, 0, $attempts) as $ranked) {
            $story = $ranked->story;

            $html = $this->articleHtml($story);
            $text = $html === null ? null : $this->article->extract($html);
            $cover = $this->cover->forStory($story, $html);

            $briefs = [];

            foreach (array_keys($networks) as $network) {
                $briefs[] = NetworkBrief::for($network, $this->windowFor($network), $cover !== null);
            }

            try {
                $copy = $this->copywriter->write($story, $text, $briefs);
            } catch (TextGenerationFailed $e) {
                // No provider, or the provider refused. Neither is this story's
                // fault, so the next candidate would fail the same way.
                $report->stoppedBecause = $e->getMessage();

                return $report;
            }

            if ($copy === null) {
                $report->refused[] = ['title' => $story->title, 'reason' => 'the model judged it not worth posting'];

                if (! $dryRun) {
                    $this->recordSkip($story, $ranked, $now);
                }

                continue;
            }

            $report->chosen = $ranked;
            $report->copy = $copy;
            $report->hasCover = $cover !== null;

            foreach (array_keys($copy) as $network) {
                $report->slots[$network] = Windows::make()->slotFor($network, $now->copy()->setTimezone(Windows::make()->timezone()));
            }

            if (! $dryRun) {
                $this->schedule($report, $story, $ranked, $networks, $cover, $now);
            }

            return $report;
        }

        $report->stoppedBecause = $report->refused === []
            ? 'No candidate story was left after filtering.'
            : 'Every candidate was judged not worth posting.';

        return $report;
    }

    /**
     * Create one post per network, each at its own hour, and record what was made.
     *
     * In one transaction: a run that created two of four posts and then failed
     * would publish half a day and leave the other half to be created again by the
     * next run, at which point the story is in `curated_stories` and cannot be.
     *
     * @param  array<string, list<SocialAccount>>  $networks
     * @param  array{contents: string, name: string, mime: string, width: int, height: int}|null  $cover
     */
    private function schedule(
        CurationReport $report,
        Story $story,
        RankedStory $ranked,
        array $networks,
        ?array $cover,
        Carbon $now,
    ): void {
        DB::transaction(function () use ($report, $story, $ranked, $networks, $cover, $now): void {
            $curated = CuratedStory::query()->create([
                'uid' => $story->uid,
                'url_key' => $story->urlKey(),
                'title' => mb_substr($story->title, 0, 500),
                'url' => $story->url,
                'source_label' => $story->label,
                'publisher' => $story->publisher,
                'score' => round($ranked->score, 6),
                'sources_count' => $ranked->sources,
                'chosen_on' => $this->curatorDay($now),
                'was_skipped' => false,
            ]);

            foreach ($report->copy as $network => $copy) {
                $accounts = $networks[$network] ?? [];

                if ($accounts === []) {
                    continue;
                }

                $post = Post::query()->create([
                    'body' => $copy->text(),
                    'status' => Post::SCHEDULED,
                    'scheduled_for' => $report->slots[$network],
                    'created_by' => $accounts[0]->created_by,
                ]);

                foreach ($accounts as $account) {
                    PostTarget::query()->create([
                        'post_id' => $post->id,
                        'social_account_id' => $account->id,
                        'status' => PostTarget::PENDING,
                    ]);
                }

                if ($cover !== null) {
                    // Through Data's contract, which is the one path in Social
                    // that writes bytes to a disk — `PostMedia` reads the same
                    // attachment rows back when the post is published.
                    $this->attachments->attachContents(
                        $post,
                        $cover['contents'],
                        $cover['name'],
                        $cover['mime'],
                        $accounts[0]->created_by,
                    );
                }

                $curated->posts()->attach($post->id, ['network' => $network]);

                $report->posts[$network] = (int) $post->id;
            }
        });
    }

    /** Record a refusal so the same verdict is not bought twice. */
    private function recordSkip(Story $story, RankedStory $ranked, Carbon $now): void
    {
        CuratedStory::query()->create([
            'uid' => $story->uid,
            'url_key' => $story->urlKey(),
            'title' => mb_substr($story->title, 0, 500),
            'url' => $story->url,
            'source_label' => $story->label,
            'publisher' => $story->publisher,
            'score' => round($ranked->score, 6),
            'sources_count' => $ranked->sources,
            'chosen_on' => $this->curatorDay($now),
            'was_skipped' => true,
            'skip_reason' => 'The model judged it outside the channel’s subjects.',
        ]);
    }

    /**
     * Which networks have somewhere to post to, with their accounts.
     *
     * Driven by `social_accounts`, so connecting an account is what enrols it and
     * switching one off is what removes it — no code change either way. A network
     * whose window row is explicitly inactive is excluded even when an account
     * exists, which is how the operator turns one network off without
     * disconnecting it.
     *
     * @return array<string, list<SocialAccount>>
     */
    private function networksToPostTo(): array
    {
        $windows = Windows::make();
        $networks = [];

        foreach (SocialAccount::query()->active()->get() as $account) {
            if (! $account->isConnected() || ! $windows->isActive($account->network)) {
                continue;
            }

            $networks[$account->network][] = $account;
        }

        return $networks;
    }

    private function windowFor(string $network): ?\Modules\Social\Models\CurationWindow
    {
        return \Modules\Social\Models\CurationWindow::query()->where('network', $network)->first();
    }

    /** The article page, fetched once and used for both the text and the cover. */
    private function articleHtml(Story $story): ?string
    {
        // Deliberately one request rather than two: `ArticleText` and `Cover` both
        // want the same page, and fetching it twice doubles the load this puts on
        // a publisher for no gain.
        return $this->article->fetchHtml($story->url);
    }

    /** The curator's own day, which is the reader's day and not UTC's. */
    private function curatorDay(Carbon $now): string
    {
        return $now->copy()->setTimezone(Windows::make()->timezone())->toDateString();
    }
}
