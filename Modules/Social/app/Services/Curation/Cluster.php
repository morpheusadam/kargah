<?php

namespace Modules\Social\Services\Curation;

use Illuminate\Support\Carbon;

/**
 * One story, and every outlet that wrote about it.
 *
 * The unit the ranker scores, rather than the article. Seven sites covering one
 * announcement are one thing that happened, and posting it seven times would be
 * seven posts about one thing — but more importantly, *that seven sites covered
 * it* is the strongest free signal of importance available here, and it only
 * exists once the articles are grouped.
 */
class Cluster
{
    /** @param  list<Story>  $stories */
    public function __construct(
        public array $signature,
        public array $stories = [],
    ) {}

    public function add(Story $story): void
    {
        $this->stories[] = $story;
    }

    /**
     * How many *independent* outlets carried it.
     *
     * Distinct labels, not article count. One outlet running a story, a follow-up
     * and a live blog is one outlet with an opinion, not three sources agreeing —
     * and treating it as three is how a single publisher's busy morning would win
     * the day.
     */
    public function sources(): int
    {
        return count(array_unique(array_map(fn (Story $s): string => $s->label, $this->stories)));
    }

    /** The mean authority of the outlets carrying it. */
    public function authority(): float
    {
        if ($this->stories === []) {
            return 0.5;
        }

        return array_sum(array_map(fn (Story $s): float => $s->authority, $this->stories))
            / count($this->stories);
    }

    /** When the story broke: the earliest thing written about it. */
    public function broke(): Carbon
    {
        return collect($this->stories)->min(fn (Story $s): Carbon => $s->publishedAt);
    }

    /**
     * The last time anybody wrote about it.
     *
     * 🔴 **Time decay is measured from this, not from `broke()`, and that is the
     * single calibration this ranker cannot lose.** Multi-outlet coverage takes
     * hours to accumulate by its nature. Decaying from the moment a story broke
     * means that by the time the third outlet publishes, `(age + 2) ^ 1.8` has
     * already divided the score by a large number — so the clusters with the most
     * corroboration, the ones this whole design exists to find, could never win.
     * Measured on the pipeline this is ported from: a cluster with corroboration
     * 2.53 came 186th of 450. A story outlets are still writing about is live,
     * even if it started yesterday.
     */
    public function latest(): Carbon
    {
        return collect($this->stories)->max(fn (Story $s): Carbon => $s->publishedAt);
    }

    /**
     * The article that represents the cluster — the one actually published.
     *
     * A picture first, because every network reads better with one and Instagram
     * cannot be posted to without one at all. Then authority, because between two
     * outlets carrying the same facts the more reliable one is the better link to
     * send a reader to. Engagement only breaks a remaining tie, which is the only
     * job that number has here.
     */
    public function lead(): Story
    {
        $best = $this->stories[0];

        foreach ($this->stories as $story) {
            if ($this->rank($story) > $this->rank($best)) {
                $best = $story;
            }
        }

        return $best;
    }

    /** @return array{0: int, 1: float, 2: int} */
    private function rank(Story $story): array
    {
        return [$story->imageUrl !== null ? 1 : 0, $story->authority, $story->engagement];
    }
}
