<?php

namespace Modules\Social\Services\Curation;

use Modules\Social\Models\CurationFeed;
use Modules\Social\Models\CurationSetting;
use Modules\Social\Services\Curation\Sources\HackerNews;
use Modules\Social\Services\Curation\Sources\Lobsters;
use Modules\Social\Services\Curation\Sources\RssFeed;
use Modules\Social\Services\Curation\Sources\Source;

/**
 * The configured sources, built.
 *
 * One place turns rows into objects, so that everything downstream receives
 * `Source` instances and never has to know whether an outlet is a feed or an
 * aggregator, or where its settings are stored.
 *
 * 🔴 **Rows, not `config()`.** The settings page writes `curation_feeds` and
 * `curation_settings`, so those are the truth. `Modules/Social/config/curation.php`
 * holds the same values as the defaults a fresh install is seeded from and is
 * read only by `CurationSeeder` — a service that read config here would answer
 * differently from the page the operator is looking at, which is the least
 * debuggable kind of disagreement there is.
 *
 * **A malformed row is skipped, not fatal.** Forty hand-editable outlets will
 * eventually include one whose URL got mangled, and losing the day's post over it
 * would be the wrong trade: the run wants as many outlets as it can get, and
 * corroboration degrades gracefully when one is missing. `problems()` is what
 * keeps the skip visible rather than silent, and the command prints it.
 */
class Catalogue
{
    /** @var list<string> */
    private array $problems = [];

    /**
     * Every source this install is configured to read, aggregators first.
     *
     * Aggregators lead because they are the likeliest to be carrying something
     * the feeds have not caught up with, so a run cut short by a slow network
     * should already have read them.
     *
     * @return list<Source>
     */
    public function sources(): array
    {
        $this->problems = [];

        return [...$this->aggregators(), ...$this->feeds()];
    }

    /**
     * What was wrong with the configuration, from the last `sources()` call.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /** @return list<Source> */
    private function aggregators(): array
    {
        $settings = CurationSetting::current();
        $sources = [];

        if ($settings->hackernews_enabled) {
            $sources[] = new HackerNews(
                authority: $this->authority($settings->hackernews_authority, 'Hacker News'),
                minPoints: max(0, $settings->hackernews_min_points),
            );
        }

        if ($settings->lobsters_enabled) {
            $sources[] = new Lobsters(
                authority: $this->authority($settings->lobsters_authority, 'Lobsters'),
                minEngagement: max(0, $settings->lobsters_min_engagement),
            );
        }

        return $sources;
    }

    /** @return list<RssFeed> */
    private function feeds(): array
    {
        $sources = [];

        foreach (CurationFeed::query()->usable()->get() as $feed) {
            $url = trim((string) $feed->url);

            if (! str_starts_with($url, 'http')) {
                $this->problems[] = $feed->label.' has no usable URL and was skipped.';

                continue;
            }

            $sources[] = new RssFeed(
                url: $url,
                label: $feed->label,
                authority: $this->authority($feed->authority, $feed->label),
                maxAgeHours: $feed->max_age_hours === null ? null : (float) $feed->max_age_hours,
            );
        }

        if ($sources === [] && ! CurationFeed::query()->exists()) {
            $this->problems[] = 'No outlets are configured. Run the Social seeder, '
                .'or add them on the curation settings page.';
        }

        return $sources;
    }

    /**
     * An authority inside 0..1, complaining about it if it was not.
     *
     * Clamped rather than rejected. A nonsensical authority makes one outlet
     * weigh wrongly; dropping the outlet instead makes it weigh nothing, which is
     * a larger change to the day's ranking than the mistake itself was.
     */
    private function authority(float $value, string $label): float
    {
        if ($value > 0.0 && $value <= 1.0) {
            return $value;
        }

        $this->problems[] = $label.' has an authority of '.$value.', outside 0..1; 0.5 was used.';

        return 0.5;
    }
}
