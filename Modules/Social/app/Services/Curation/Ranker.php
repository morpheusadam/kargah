<?php

namespace Modules\Social\Services\Curation;

use Illuminate\Support\Carbon;

/**
 * Which story is the one worth posting today.
 *
 * Two signals and a decay:
 *
 *     corroboration = authority × (1 + 1.5 × (sources − 1) ^ 0.9)
 *     pickup        = sources / (span_hours + 1)
 *     score         = corroboration × (1 + log10(1 + pickup)) / (age_hours + 2) ^ 1.8
 *
 * **corroboration** is how many independent outlets carried it, weighted by how
 * much they are trusted. This is what separates "important" from "published".
 *
 * **pickup** is how fast it spread — five outlets in two hours is a story
 * breaking, five outlets in two days is a subject being covered. Same
 * corroboration, very different urgency.
 *
 * **The decay is Hacker News's own**, `(age + 2) ^ 1.8`, with the exponent on
 * time deliberately larger than the growth of the numerator so that nothing stays
 * on top forever.
 *
 * ---
 *
 * ## What was removed from the pipeline this was ported from, and why
 *
 * The bot also computes `heat` — engagement, normalised against each source's own
 * median velocity, measured as growth between runs from stored snapshots. It
 * earns its keep there, where seventeen posts a day have to be ranked out of
 * forty-six candidates and freshness is nearly everything.
 *
 * At one post a day it does not. The question here is "what is the single most
 * important thing that happened", and the honest answer to that is how many
 * independent newsrooms thought so — not how many points a link got on one
 * forum in six hours. Keeping `heat` would also have meant keeping a snapshots
 * table, a per-source median, and a second daily run to measure growth against,
 * which is a great deal of machinery to slightly reorder a list whose top entry
 * is chosen by something else.
 *
 * `Story::$engagement` survives for the one job it still does well: breaking a tie
 * over which article represents a cluster. `Cluster::lead()` reads it. Nothing
 * here does.
 *
 * ## Two calibrations that came out of real bugs
 *
 * Both are recorded at length on the methods and constants below, and both are
 * the kind of thing that looks like an arbitrary choice and is not:
 *
 * 1. Decay is measured from the cluster's *latest* coverage, not from when the
 *    story broke — `Cluster::latest()`.
 * 2. The corroboration bonus applies to outlets *beyond the first*, not to the
 *    total — see `CORROBORATION_BONUS`.
 */
class Ranker
{
    /** Hacker News's own gravity constant, unchanged. */
    public const GRAVITY = 1.8;

    /**
     * How much each outlet beyond the first is worth.
     *
     * 🔴 **The bonus applies to `sources − 1`, not to `sources`.** An earlier form
     * multiplied the whole thing by `sources ^ 0.8`, which raised the floor as
     * well as the ceiling: a single-outlet story was boosted just as surely as a
     * six-outlet one, so the *gap* between them barely moved and corroboration
     * changed nothing about the ordering. Rewarding only the outlets that
     * corroborate is what makes the signal a signal.
     *
     *     1 outlet → 0.80    3 outlets → 3.05    6 outlets → 5.90   (authority 0.8)
     */
    public const CORROBORATION_BONUS = 1.5;

    /**
     * Sublinear, so the tenth outlet adds less than the third.
     *
     * 0.9 rather than the 0.5-ish an instinct for diminishing returns suggests: at
     * log2 — the first attempt — one outlet scored 0.8 and three scored 1.6, while
     * a single busy forum thread scored over 3 on its own. Multi-outlet clusters
     * could not win, which made the clustering ornamental.
     */
    public const CORROBORATION_EXPONENT = 0.9;

    /**
     * Score every cluster and return them best first.
     *
     * @param  list<Cluster>  $clusters
     * @return list<RankedStory>
     */
    public function rank(array $clusters, ?Carbon $now = null): array
    {
        $now = $now ?? Carbon::now('UTC');

        $ranked = [];

        foreach ($clusters as $cluster) {
            if ($cluster->stories === []) {
                continue;
            }

            $ranked[] = $this->score($cluster, $now);
        }

        usort($ranked, fn (RankedStory $a, RankedStory $b): int => $b->score <=> $a->score);

        return $ranked;
    }

    /** The arithmetic, kept in one readable place. */
    public function score(Cluster $cluster, Carbon $now): RankedStory
    {
        $sources = $cluster->sources();

        // 🔴 From the latest coverage, not from when it broke. See
        // `Cluster::latest()` — decaying from the break makes a well-corroborated
        // story unwinnable, because corroboration takes hours to accumulate and
        // the decay has already run for those hours.
        $age = max($now->getTimestamp() - $cluster->latest()->getTimestamp(), 0) / 3600;
        $age = max($age, 0.05);

        // First to latest coverage: how long the story took to spread.
        $span = max($cluster->latest()->getTimestamp() - $cluster->broke()->getTimestamp(), 0) / 3600;

        $extra = ($sources - 1) ** self::CORROBORATION_EXPONENT;
        $corroboration = $cluster->authority() * (1 + self::CORROBORATION_BONUS * $extra);

        $pickup = $sources / ($span + 1);

        $score = $corroboration * (1 + log10(1 + $pickup)) / ($age + 2) ** self::GRAVITY;

        return new RankedStory(
            story: $cluster->lead(),
            cluster: $cluster,
            score: $score,
            sources: $sources,
            ageHours: $age,
            spanHours: $span,
            corroboration: $corroboration,
            pickup: $pickup,
        );
    }
}
