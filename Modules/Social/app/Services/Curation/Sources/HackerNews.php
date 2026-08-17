<?php

namespace Modules\Social\Services\Curation\Sources;

use Modules\Social\Services\Curation\Story;

/**
 * Hacker News, through Algolia's search index.
 *
 * **`search_by_date` rather than the front page, and that is the whole point of
 * using Algolia at all.** The front page is what has already arrived; this
 * pipeline wants what is arriving, because a story that reaches the front page
 * has by definition already been read by the people who were going to read it.
 * Ordering by date and gating on points gives "new, and already above a
 * threshold", which is the closest free approximation of a story on its way up.
 *
 * The gate is applied by Algolia rather than here — `numericFilters` is part of
 * the query, so a run costs one request whatever the threshold is, instead of
 * fetching fifty stories to discard forty of them.
 *
 * `hnrss` was the other option and gives titles and links with no body text at
 * all, which leaves a summariser nothing but a headline to rewrite.
 */
class HackerNews extends HttpSource
{
    private const ENDPOINT = 'https://hn.algolia.com/api/v1/search_by_date';

    private const ITEM_URL = 'https://news.ycombinator.com/item?id=';

    /**
     * Comments count double, points count single.
     *
     * Only ever a tiebreak between two articles about the same story — see the
     * note on `Story::$engagement` — but a story with sixty comments on forty
     * points is a story people are arguing about, and that is the one worth
     * representing a cluster. Keeping the weighting is free and keeps the number
     * meaning what it means on the pipeline this was ported from.
     */
    private const COMMENT_WEIGHT = 2;

    public function __construct(
        private readonly string $label = 'Hacker News',
        private readonly float $authority = 0.75,
        private readonly int $minPoints = 50,
        private readonly int $hitsPerPage = 50,
    ) {}

    public function label(): string
    {
        return $this->label;
    }

    public function authority(): float
    {
        return $this->authority;
    }

    public function fetch(): array
    {
        $body = $this->getJson(self::ENDPOINT, [
            'tags' => 'story',
            'numericFilters' => 'points>'.$this->minPoints,
            'hitsPerPage' => $this->hitsPerPage,
        ]);

        $hits = $body['hits'] ?? null;

        if (! is_array($hits)) {
            throw SourceFailed::malformed($this->label, 'the response carried no hits');
        }

        $stories = [];

        foreach ($hits as $hit) {
            if (! is_array($hit)) {
                continue;
            }

            $title = $this->clean((string) ($hit['title'] ?? ''));
            $id = trim((string) ($hit['objectID'] ?? ''));
            $at = $this->parseTime($hit['created_at'] ?? null);

            if ($title === '' || $id === '' || $at === null) {
                continue;
            }

            $discussion = self::ITEM_URL.$id;

            // An Ask HN or a text post has no link of its own, and its article
            // *is* the discussion page. Pointing `url` there keeps the source
            // button on the published post working rather than empty.
            $article = trim((string) ($hit['url'] ?? '')) ?: $discussion;

            $stories[] = new Story(
                uid: 'hn:'.$id,
                label: $this->label,
                authority: $this->authority,
                title: $title,
                summary: $this->clean((string) ($hit['story_text'] ?? '')),
                url: $article,
                publishedAt: $at,
                discussionUrl: $discussion,
                publisher: $article === $discussion ? null : $this->publisherOf($article),
                engagement: (int) ($hit['points'] ?? 0)
                    + self::COMMENT_WEIGHT * (int) ($hit['num_comments'] ?? 0),
            );
        }

        return $stories;
    }
}
