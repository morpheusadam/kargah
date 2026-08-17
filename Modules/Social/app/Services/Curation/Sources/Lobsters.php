<?php

namespace Modules\Social\Services\Curation\Sources;

use Modules\Social\Services\Curation\Story;

/**
 * Lobsters, from its hottest page.
 *
 * A much smaller community than Hacker News, kept because its signal on security
 * and systems work is cleaner — a story with thirty points here is frequently
 * more substantial than one with three hundred there.
 *
 * 🔴 **The engagement floor is what makes that smallness safe.** Scores here are
 * one and two digits where Hacker News is three. The pipeline this is ported from
 * normalised each source against its own median, and the effect was that a
 * three-point Lobsters story read as "three times normal for Lobsters" and beat a
 * two-hundred-point Hacker News discussion outright. The floor is the fix that
 * survived: below it, a story is not returned at all.
 *
 * The corroboration ranker this feeds does not use the number, so the floor is
 * now doing a simpler job than it was — keeping a quiet Tuesday's noise out of
 * the candidate pool — but removing it would put that noise back.
 */
class Lobsters extends HttpSource
{
    private const ENDPOINT = 'https://lobste.rs/hottest.json';

    /** As on Hacker News: a story being argued about beats one merely upvoted. */
    private const COMMENT_WEIGHT = 2;

    public function __construct(
        private readonly string $label = 'Lobsters',
        private readonly float $authority = 0.7,
        private readonly int $minEngagement = 25,
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
        $rows = $this->getJson(self::ENDPOINT);

        $stories = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $title = $this->clean((string) ($row['title'] ?? ''));
            $id = trim((string) ($row['short_id'] ?? ''));
            $at = $this->parseTime($row['created_at'] ?? null);

            if ($title === '' || $id === '' || $at === null) {
                continue;
            }

            $engagement = (int) ($row['score'] ?? 0)
                + self::COMMENT_WEIGHT * (int) ($row['comment_count'] ?? 0);

            if ($engagement < $this->minEngagement) {
                continue;
            }

            $comments = trim((string) ($row['comments_url'] ?? ''));
            $article = trim((string) ($row['url'] ?? '')) ?: $comments;

            if ($article === '') {
                continue;
            }

            // The tags are the only prose Lobsters supplies — there is no
            // standfirst — and they are genuinely informative here: `ssl`,
            // `privacy`, `rust` are the outlet telling you what the story is
            // about. Enough to seed a cluster signature, and the full text comes
            // from the article page.
            $tags = array_filter(array_map('strval', (array) ($row['tags'] ?? [])));

            $stories[] = new Story(
                uid: 'lobsters:'.$id,
                label: $this->label,
                authority: $this->authority,
                title: $title,
                summary: implode(', ', $tags),
                url: $article,
                publishedAt: $at,
                discussionUrl: $comments !== '' ? $comments : null,
                publisher: $article === $comments ? null : $this->publisherOf($article),
                engagement: $engagement,
            );
        }

        return $stories;
    }
}
