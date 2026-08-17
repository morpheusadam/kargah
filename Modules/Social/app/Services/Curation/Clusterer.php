<?php

namespace Modules\Social\Services\Curation;

/**
 * Grouping the day's articles into the stories they are about.
 *
 * The problem this solves: RSS feeds publish no engagement numbers, so the only
 * signal of importance an outlet gives is `authority`, which somebody typed by
 * hand and which is the same for every story that outlet ever runs. The curator
 * knew where a story came from and had no idea whether it mattered.
 *
 * The strongest free signal of importance is **simultaneous coverage by several
 * independent outlets**. A story seven sites run within three hours is important;
 * one that a single site ran is not, however good that site is. Techmeme and
 * Google News are built on exactly this observation.
 *
 * **The signature is Latin tokens only, and that is what makes it work across
 * languages.** A Russian article about the same story is written in Russian but
 * keeps the proper nouns in Latin — OpenAI, Cloudflare, GPT, a CVE number. So
 * Habr and The Verge covering one announcement land in one cluster, and the
 * Russian-language outlets in the catalogue become corroboration rather than
 * noise. It also means an all-Persian or all-Russian story with no Latin nouns in
 * it gets a weak signature and clusters alone, which is the right failure: alone
 * is merely unhelpful, whereas a weak signature that matched things would put
 * unrelated stories together.
 */
class Clusterer
{
    /**
     * How much two signatures must overlap to be one story.
     *
     * Below this, unrelated stories that merely share a subject stick together —
     * two different Apple stories on the same day both contain "apple", "iphone",
     * "ios". Above it, a short headline and a long standfirst about the same event
     * stay apart. 0.42 is the value the pipeline this is ported from settled on
     * against real runs.
     */
    public const SIMILARITY = 0.42;

    /**
     * Fewer tokens than this and the signature is too weak to match on.
     *
     * A two-token signature matches almost anything with a high ratio, because
     * the denominator is the *smaller* set. Such a story gets an empty signature
     * and clusters alone rather than dragging others in with it.
     */
    private const MIN_TOKENS = 3;

    /**
     * Words that carry no identity.
     *
     * English only, and deliberately so: the signature is Latin tokens, and the
     * Latin tokens in a Russian article are proper nouns rather than grammar.
     */
    private const STOP = [
        'the', 'and', 'for', 'with', 'from', 'that', 'this', 'have', 'has', 'was',
        'are', 'will', 'can', 'its', 'his', 'her', 'you', 'your', 'our', 'not',
        'but', 'all', 'new', 'now', 'how', 'why', 'what', 'who', 'when', 'after',
        'before', 'into', 'over', 'than', 'then', 'they', 'them', 'their', 'been',
        'more', 'most', 'some', 'such', 'only', 'just', 'also', 'make', 'makes',
        'made', 'says', 'said', 'say', 'get', 'gets', 'got', 'use', 'uses', 'used',
        'one', 'two', 'out', 'off', 'about', 'against', 'between', 'during',
    ];

    /**
     * Group articles into stories.
     *
     * Every article is compared against every cluster so far, which is quadratic —
     * and fine, because a run holds a few hundred articles and the comparison is a
     * set intersection. Anything cleverer would be optimising the cheapest step in
     * a pipeline whose other steps make HTTP requests.
     *
     * @param  list<Story>  $stories
     * @return list<Cluster>
     */
    public function build(array $stories): array
    {
        /** @var list<Cluster> $clusters */
        $clusters = [];

        /** @var array<string, Cluster> $byUrl */
        $byUrl = [];

        foreach ($stories as $story) {
            $key = $story->urlKey();

            // Two feeds carrying the identical URL are the identical article —
            // syndication, or one outlet listed twice under different sections.
            // No similarity test can be more certain than that, so it is checked
            // first and skips the comparison entirely.
            $cluster = $byUrl[$key] ?? null;

            if ($cluster === null) {
                $signature = $this->signature($story->signatureText());
                $cluster = $this->closest($signature, $clusters);

                if ($cluster === null) {
                    $cluster = new Cluster($signature);
                    $clusters[] = $cluster;
                } else {
                    // Widen the cluster with what this article added. A story
                    // gains vocabulary as more outlets cover it, and the fourth
                    // write-up is often phrased closer to the third than to the
                    // first.
                    $cluster->signature = array_values(array_unique(
                        [...$cluster->signature, ...$signature],
                    ));
                }
            }

            $cluster->add($story);
            $byUrl[$key] ??= $cluster;
        }

        return $clusters;
    }

    /**
     * The cluster this signature belongs to, or null for a new story.
     *
     * @param  list<string>  $signature
     * @param  list<Cluster>  $clusters
     */
    private function closest(array $signature, array $clusters): ?Cluster
    {
        $best = null;
        $bestScore = 0.0;

        foreach ($clusters as $cluster) {
            $score = $this->similarity($signature, $cluster->signature);

            if ($score > $bestScore) {
                $best = $cluster;
                $bestScore = $score;
            }
        }

        return $bestScore >= self::SIMILARITY ? $best : null;
    }

    /**
     * Overlap measured against the smaller set, not Jaccard.
     *
     * Jaccard divides by the union, so a five-word headline and a forty-word
     * standfirst about the same event score low however completely the headline is
     * contained in the standfirst — the denominator is dominated by words only one
     * of them has. What actually indicates one story is that *all* of the shorter
     * signature's key words appear in the longer one, which is what dividing by
     * the smaller set measures.
     *
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    public function similarity(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }

        $shared = count(array_intersect($left, $right));

        return $shared / min(count($left), count($right));
    }

    /**
     * The Latin and numeric tokens that identify a story.
     *
     * Three characters minimum, because two-letter fragments are noise; a token
     * that is only digits is dropped, because a bare year or figure identifies
     * nothing, while `cve-2026-1234` and `gpt-5` survive as whole tokens and are
     * among the most identifying things a signature can contain.
     *
     * @return list<string>
     */
    public function signature(string $text): array
    {
        $head = mb_strtolower(implode(' ', array_slice(preg_split('/\R/u', $text) ?: [], 0, 2)));

        preg_match_all('/[a-z0-9][a-z0-9\-.]{2,}/', $head, $matches);

        $tokens = [];

        foreach ($matches[0] as $token) {
            $token = rtrim($token, '.-');

            if (mb_strlen($token) < 3
                || in_array($token, self::STOP, true)
                || ctype_digit($token)
                || in_array($token, $tokens, true)) {
                continue;
            }

            $tokens[] = $token;
        }

        return count($tokens) >= self::MIN_TOKENS ? $tokens : [];
    }
}
