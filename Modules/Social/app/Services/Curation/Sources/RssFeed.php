<?php

namespace Modules\Social\Services\Curation\Sources;

use Illuminate\Support\Carbon;
use Modules\Social\Services\Curation\Story;
use SimpleXMLElement;

/**
 * Any RSS or Atom feed, which is thirty-odd of the forty sources.
 *
 * One class rather than one per outlet: what differs between The Register and
 * Habr is a URL and an authority number, both of which come from the catalogue.
 * Adding an outlet is therefore a line of config, not a class — which is what
 * makes it reasonable to carry this many of them.
 *
 * **Both dialects, because the sources really are mixed.** RSS 2.0 puts items in
 * `channel/item` with an RFC 2822 `pubDate`; Atom puts them in `entry` with an
 * ISO 8601 `published`, and its link is an attribute rather than a text node.
 * Several feeds in the catalogue are Atom — The Register's `headlines.atom`, The
 * Verge, OONI — so supporting one dialect would silently lose them.
 *
 * **Entries are sorted here and truncated to twenty-five.** Feed order is not
 * reliably newest-first — OpenAI's and Hugging Face's are notoriously not — and
 * a feed that returns a thousand historical entries would otherwise dominate a
 * run purely by volume. Sorting before truncating is what makes the cut mean
 * "the newest twenty-five" rather than "whichever twenty-five were listed
 * first".
 */
class RssFeed extends HttpSource
{
    /**
     * How many of the newest entries one feed may contribute to a run.
     *
     * Twenty-five is comfortably more than any of these outlets publishes in a
     * day, so the limit never truncates a real day's news — it only stops an
     * archive-shaped feed from crowding out the other thirty-nine sources.
     */
    private const MAX_ENTRIES = 25;

    public function __construct(
        private readonly string $url,
        private readonly string $label,
        private readonly float $authority = 0.5,
        private readonly ?float $maxAgeHours = null,
    ) {}

    public function label(): string
    {
        return $this->label;
    }

    public function authority(): float
    {
        return $this->authority;
    }

    public function maxAgeHours(): ?float
    {
        return $this->maxAgeHours;
    }

    public function fetch(): array
    {
        $entries = $this->entriesOf($this->parse($this->getBody($this->url)));

        // Date first, then truncate. See the class docblock: the other order
        // gives "the first twenty-five listed", which is not the same thing.
        usort($entries, fn (array $a, array $b): int => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        $stories = [];

        foreach (array_slice($entries, 0, self::MAX_ENTRIES) as $entry) {
            $stories[] = new Story(
                uid: $entry['uid'],
                label: $this->label,
                authority: $this->authority,
                title: $entry['title'],
                summary: $entry['summary'],
                url: $entry['url'],
                publishedAt: $entry['at'],
                imageUrl: $entry['image'],
                publisher: $this->publisherOf($entry['url']),
                maxAgeHours: $this->maxAgeHours,
            );
        }

        return $stories;
    }

    /**
     * Parse the document, turning libxml's own complaints into ours.
     *
     * `LIBXML_NONET` is passed explicitly. Modern libxml will not resolve an
     * external entity by default, so this is belt to that braces — but the input
     * is a document from a third party that this application fetches on a
     * schedule and never shows to anybody, which is precisely the shape of thing
     * that should not be able to make outbound requests of its own.
     *
     * The internal-error flag is global to the process and is restored rather
     * than set, because a Livewire page rendering later in the same request would
     * otherwise inherit it.
     *
     * @throws SourceFailed
     */
    private function parse(string $body): SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($body, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

            if ($document === false) {
                $first = libxml_get_errors()[0] ?? null;

                throw SourceFailed::malformed(
                    $this->label,
                    'the XML did not parse'.($first ? ': '.trim($first->message) : ''),
                );
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Every usable entry, in either dialect, as plain arrays.
     *
     * An entry with no link or no readable date is dropped rather than failing
     * the feed — see `HttpSource::parseTime()` for why one bad date must not cost
     * the other twenty items.
     *
     * @return list<array{uid: string, title: string, summary: string, url: string, at: Carbon, image: string|null}>
     */
    private function entriesOf(SimpleXMLElement $document): array
    {
        $nodes = isset($document->channel->item)
            ? $document->channel->item          // RSS 2.0
            : ($document->item ?? $document->entry);   // RDF, then Atom

        $entries = [];

        foreach ($nodes ?? [] as $node) {
            $url = $this->linkOf($node);
            $at = $this->parseTime($this->dateOf($node));

            if ($url === null || $at === null) {
                continue;
            }

            $title = $this->clean((string) ($node->title ?? ''));
            $summary = $this->summaryOf($node);

            if ($title === '' && $summary === '') {
                continue;
            }

            // A `<guid>` or Atom `<id>` is the outlet's own permanent handle for
            // the story and survives a headline being edited, which a link does
            // not always do. The link is the fallback because it is stable enough
            // in practice and a title is not stable at all.
            $uid = trim((string) ($node->guid ?? $node->id ?? '')) ?: $url;

            $entries[] = [
                'uid' => $uid,
                'title' => $title,
                'summary' => $summary,
                'url' => $url,
                'at' => $at,
                'image' => $this->imageOf($node),
            ];
        }

        return $entries;
    }

    /**
     * The article's own address.
     *
     * RSS puts it in the text of `<link>`. Atom puts it in an attribute, and may
     * list several — `rel="alternate"` is the article and `rel="replies"` or
     * `rel="enclosure"` are not, so the first link with any other `rel` must not
     * be taken. An Atom link with no `rel` at all defaults to `alternate` per the
     * specification, which is why the empty case counts as a match.
     */
    private function linkOf(SimpleXMLElement $node): ?string
    {
        $text = trim((string) ($node->link ?? ''));

        if ($text !== '' && str_starts_with($text, 'http')) {
            return $text;
        }

        foreach ($node->link ?? [] as $link) {
            $rel = (string) ($link['rel'] ?? '');
            $href = trim((string) ($link['href'] ?? ''));

            if (($rel === '' || $rel === 'alternate') && str_starts_with($href, 'http')) {
                return $href;
            }
        }

        return null;
    }

    /** Whichever of the four date fields the outlet chose to use. */
    private function dateOf(SimpleXMLElement $node): ?string
    {
        foreach (['pubDate', 'published', 'updated', 'date'] as $field) {
            $value = trim((string) ($node->{$field} ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        // Dublin Core, which is where RDF feeds and a few RSS ones put it.
        $dc = trim((string) ($node->children('http://purl.org/dc/elements/1.1/')->date ?? ''));

        return $dc !== '' ? $dc : null;
    }

    /**
     * The standfirst, preferring the shorter field.
     *
     * `content:encoded` and Atom's `<content>` hold the whole article on the
     * feeds that syndicate in full, and `<description>` holds the intended
     * extract. The extract is what a standfirst is, and taking the full body here
     * would put an outlet's entire article into the cluster signature — where
     * every token below the first paragraph is noise. The full text, when it is
     * wanted, is fetched from the page by `ArticleText`.
     */
    private function summaryOf(SimpleXMLElement $node): string
    {
        foreach (['description', 'summary', 'subtitle'] as $field) {
            $value = $this->clean((string) ($node->{$field} ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return $this->clean((string) ($node->content ?? ''));
    }

    /**
     * A picture the feed offered, in the order the tags are worth trusting.
     *
     * Unvalidated on purpose. Whether these bytes are big enough, are a format
     * the network takes, and are a photograph rather than a tracking pixel is
     * `Cover`'s question, and answering it needs an HTTP request per candidate —
     * which must not happen forty times a run for stories that will not be
     * chosen.
     */
    private function imageOf(SimpleXMLElement $node): ?string
    {
        $media = $node->children('http://search.yahoo.com/mrss/');

        // 🔴 `attributes()`, not `$content['url']`. On an element reached through
        // `children($namespace)`, SimpleXML scopes attribute lookup to that same
        // namespace — and `url` and `medium` are in no namespace at all, so the
        // subscript form returns an empty string with no error. `attributes()`
        // with no argument reads the unnamespaced set, which is what these are.
        // Every media-namespace read below needs this; the `enclosure` and `link`
        // reads further down do not, because those elements are themselves
        // unnamespaced.
        foreach ($media->content ?? [] as $content) {
            $attributes = $content->attributes();
            $medium = (string) ($attributes['medium'] ?? 'image');
            $url = trim((string) ($attributes['url'] ?? ''));

            if ($url !== '' && $medium === 'image') {
                return $url;
            }
        }

        foreach ($media->thumbnail ?? [] as $thumbnail) {
            $url = trim((string) ($thumbnail->attributes()['url'] ?? ''));

            if ($url !== '') {
                return $url;
            }
        }

        // RSS `<enclosure>` is an attribute node; Atom spells the same idea as a
        // `<link rel="enclosure">`. Both are checked, and both only count when
        // the declared type is an image — an enclosure is just as often a podcast.
        foreach ($node->enclosure ?? [] as $enclosure) {
            if (str_starts_with((string) ($enclosure['type'] ?? ''), 'image/')) {
                $url = trim((string) ($enclosure['url'] ?? ''));

                if ($url !== '') {
                    return $url;
                }
            }
        }

        foreach ($node->link ?? [] as $link) {
            if ((string) ($link['rel'] ?? '') === 'enclosure'
                && str_starts_with((string) ($link['type'] ?? ''), 'image/')) {
                $url = trim((string) ($link['href'] ?? ''));

                if ($url !== '') {
                    return $url;
                }
            }
        }

        // Some outlets put the only picture inside the HTML of the description.
        $html = (string) ($node->description ?? '').(string) ($node->content ?? '');

        return preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches) === 1
            ? $matches[1]
            : null;
    }
}
