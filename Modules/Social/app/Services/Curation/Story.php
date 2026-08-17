<?php

namespace Modules\Social\Services\Curation;

use Illuminate\Support\Carbon;

/**
 * One candidate story, as a source handed it over.
 *
 * Every source normalises to this shape, so nothing downstream — the clusterer,
 * the ranker, the copywriter — has to know whether a story arrived as RSS, as a
 * Hacker News hit or as a Lobsters row. That is the whole job of this class, and
 * it is why the fields are the union of what those three can supply rather than
 * the union of everything a feed might contain.
 *
 * **`uid` is the dedupe key and it must be stable across runs.** A story whose
 * uid changes between two days is a story that gets published twice. So it comes
 * from whatever the source's own permanent identifier is — the Hacker News
 * object id, the Lobsters short id, the feed entry's `<guid>` — and falls back
 * to the link only when there is nothing better, because a link is stable in
 * practice and a title is not.
 *
 * **`engagement` is a tiebreak and nothing else.** The bot this pipeline is
 * ported from ranks by engagement velocity measured against each source's own
 * median, which needs a snapshot of every item on every run. At one post a day
 * that machinery earns nothing: the signal that decides the winner is how many
 * independent outlets carry the story. The number survives here for one real
 * use — choosing which article represents a cluster, where "the one people are
 * actually discussing" is a better answer than "whichever sorted first" — and
 * `Ranker` never reads it. Sources that publish no number leave it at zero.
 */
final readonly class Story
{
    /**
     * @param  string  $uid  the source's own permanent id for this story
     * @param  string  $label  the source's name, as the catalogue spells it
     * @param  float  $authority  0..1, how much to trust this outlet absent other signal
     * @param  string  $title  the headline, plain text
     * @param  string  $summary  the standfirst or body extract, plain text, possibly empty
     * @param  string  $url  where the article itself lives
     * @param  Carbon  $publishedAt  always UTC
     * @param  string|null  $imageUrl  a picture the feed itself offered, unvalidated
     * @param  string|null  $discussionUrl  the comment page, when the source has one separate from the article
     * @param  string|null  $publisher  the host that actually published it, when it differs from the source
     * @param  int  $engagement  points, likes or comments; a tiebreak only — see the class docblock
     * @param  float|null  $maxAgeHours  this source's own age window, overriding the general one
     */
    public function __construct(
        public string $uid,
        public string $label,
        public float $authority,
        public string $title,
        public string $summary,
        public string $url,
        public Carbon $publishedAt,
        public ?string $imageUrl = null,
        public ?string $discussionUrl = null,
        public ?string $publisher = null,
        public int $engagement = 0,
        public ?float $maxAgeHours = null,
    ) {}

    /**
     * How old this story is, in hours, floored well above zero.
     *
     * The floor exists because the ranker divides by `(age + 2) ^ 1.8` and a
     * feed that reports a publication time a few seconds in the future — which
     * happens, clocks differ — would otherwise produce a negative age and a
     * score no other story could reach.
     */
    public function ageHours(?Carbon $now = null): float
    {
        $seconds = ($now ?? Carbon::now('UTC'))->getTimestamp() - $this->publishedAt->getTimestamp();

        return max($seconds / 3600, 0.05);
    }

    /**
     * The text a cluster signature is built from.
     *
     * The headline plus the opening of the standfirst, and no more. Further down
     * an article the vocabulary stops being about this story and starts being
     * about the section it sits in — related links, boilerplate, the outlet's own
     * name — and those tokens are what make unrelated stories from the same site
     * look similar to each other.
     */
    public function signatureText(): string
    {
        $firstLine = trim(explode("\n", trim($this->summary))[0] ?? '');

        return trim($this->title."\n".$firstLine);
    }

    /**
     * Title and standfirst as one block, for the summariser.
     *
     * Separate from `signatureText()` because they answer different questions:
     * this one wants everything the feed said, that one wants only the part that
     * identifies the story.
     */
    public function fullText(): string
    {
        return trim($this->summary) === ''
            ? $this->title
            : $this->title."\n\n".trim($this->summary);
    }

    /**
     * The same story on two sites has two URLs; this is what makes them one key.
     *
     * Host without `www.`, path without a trailing slash and without an `/amp`
     * suffix, query string dropped entirely. The query is where campaign
     * parameters live, and `?utm_source=` differing between two feeds carrying
     * the identical article is the single most common way a duplicate gets past
     * a URL comparison.
     */
    public function urlKey(): string
    {
        $parts = parse_url($this->url);

        $host = strtolower($parts['host'] ?? '');
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        $path = strtolower(rtrim($parts['path'] ?? '', '/'));

        if (str_ends_with($path, '/amp')) {
            $path = substr($path, 0, -4);
        }

        return $host.$path;
    }
}
