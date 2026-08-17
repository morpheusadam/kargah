<?php

namespace Modules\Social\Services\Curation;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Social\Services\Curation\Sources\HttpSource;

/**
 * The article's own words, pulled out of the page it lives on.
 *
 * **This is the single biggest lever on the quality of what gets published**, and
 * it was measured that way on the pipeline this is ported from. Feeds give a
 * headline and a truncated standfirst; Hacker News and Lobsters give a headline
 * and a link and nothing else. Asked to summarise that, a model has no choice but
 * to rewrite the headline in longer words, and the result reads exactly like what
 * it is. Given the article, it writes about what happened.
 *
 * ---
 *
 * ## Why this is hand-rolled rather than a library
 *
 * The Python pipeline uses `trafilatura`, which is very good and has no
 * maintained PHP equivalent. `fivefilters/readability.php` is the closest, and
 * pulling in a Composer package is not something to do without asking — so this
 * is a deliberate, smaller thing: strip what is definitely not article text, then
 * take the densest run of paragraphs. It will do worse than trafilatura on a
 * hostile layout and about as well on an ordinary news page, which is what the
 * catalogue is made of.
 *
 * If the summaries ever read as though they were written from navigation menus,
 * this class is the suspect and a real extraction library is the fix.
 *
 * ## What it deliberately does not do
 *
 * No JavaScript. A page behind a rendering challenge returns markup with no
 * article in it, `MIN_USEFUL` rejects it, and the caller falls back to the feed's
 * own standfirst. That is the correct outcome — a summary from a shorter source is
 * a worse post, and a summary from a paywall notice is a wrong one.
 */
class ArticleText
{
    /**
     * Shorter than this and what came back is not an article.
     *
     * A paywall interstitial, a cookie wall or a JavaScript challenge all produce
     * a few hundred characters of real text, so this is the line between "the page
     * was read" and "something was read".
     */
    private const MIN_USEFUL = 400;

    /** What is worth sending on. The opening carries the story; the rest is background. */
    private const MAX_CHARS = 8000;

    private const TIMEOUT = 15;

    /**
     * Elements that are never article text, removed before anything is measured.
     *
     * Order matters only in that these go first: a `<nav>` full of headlines would
     * otherwise be a very dense run of links and could win the density test
     * outright.
     */
    private const STRIP_TAGS = [
        'script', 'style', 'nav', 'header', 'footer', 'aside', 'form',
        'noscript', 'iframe', 'figure', 'figcaption', 'button', 'svg',
    ];

    /**
     * The article at a URL, or null when nothing usable came back.
     *
     * Null rather than an exception: a page that cannot be read costs the post a
     * better summary, not the post itself, and every caller here has the feed's
     * standfirst to fall back on.
     */
    public function fetch(string $url): ?string
    {
        $html = $this->fetchHtml($url);

        return $html === null ? null : $this->extract($html);
    }

    /**
     * The page itself, undecoded.
     *
     * Public because `Cover` wants the identical page this does — the article's
     * `og:image` is on it — and fetching it twice would double what this puts on a
     * publisher's server for no gain. `DailyCurator` gets it once and hands it to
     * both.
     *
     * 🔴 A browser user agent, for the reason `HttpSource` gives at length: with a
     * bot-shaped one a large share of publishers answer 403, and the summary
     * quietly degrades to a rewritten headline with nothing in the logs.
     */
    public function fetchHtml(string $url): ?string
    {
        if (! str_starts_with($url, 'http')) {
            return null;
        }

        try {
            $response = Http::withHeaders(HttpSource::BROWSER)
                ->timeout(self::TIMEOUT)
                ->connectTimeout(5)
                ->get($url);
        } catch (ConnectionException) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        return str_contains((string) $response->header('content-type'), 'html')
            ? $response->body()
            : null;
    }

    /**
     * The densest run of prose in the document.
     *
     * "Densest" is total paragraph text length rather than paragraph count: a
     * sidebar of twelve one-line teasers has more `<p>` elements than a five-
     * paragraph article and a fraction of the words, and counting elements would
     * hand it the page.
     */
    public function extract(string $html): ?string
    {
        $document = $this->parse($html);

        if ($document === null) {
            return null;
        }

        $best = null;
        $bestLength = 0;

        foreach ($document->getElementsByTagName('*') as $element) {
            // Only containers that could hold an article. Walking every element
            // and scoring it would score each paragraph's own parents repeatedly
            // and arrive at `<body>` every time.
            if (! in_array(strtolower($element->nodeName), ['article', 'main', 'div', 'section'], true)) {
                continue;
            }

            $text = $this->prose($element);
            $length = mb_strlen($text);

            // Strictly greater, so the outermost container only wins when it
            // genuinely holds more prose than its child — which is how the
            // article's own wrapper beats `<body>` rather than tying with it.
            if ($length > $bestLength) {
                $best = $text;
                $bestLength = $length;
            }
        }

        if ($best === null || $bestLength < self::MIN_USEFUL) {
            return null;
        }

        return mb_substr($best, 0, self::MAX_CHARS);
    }

    /**
     * Paragraph text inside one element, with the furniture already gone.
     *
     * Paragraphs shorter than forty characters are dropped: bylines, timestamps,
     * "Share this", photo credits and cookie notices are all short, and every one
     * of them would otherwise be handed to the summariser as part of the story.
     */
    private function prose(\DOMElement $element): string
    {
        $parts = [];

        foreach ($element->getElementsByTagName('p') as $paragraph) {
            $text = trim(preg_replace('/\s+/u', ' ', $paragraph->textContent) ?? '');

            if (mb_strlen($text) >= 40) {
                $parts[] = $text;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * The document, with everything that is not prose removed first.
     *
     * `libxml` complains about essentially every real-world page, so its errors
     * are suppressed and restored — the flag is global to the process, and a
     * Livewire render later in the same request must not inherit it.
     */
    private function parse(string $html): ?\DOMDocument
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new \DOMDocument;

            // The meta charset makes libxml read the bytes as UTF-8 rather than
            // guessing Latin-1, which is what turns a Turkish or Russian headline
            // into mojibake before anything else has run.
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );

            if ($loaded === false) {
                return null;
            }

            $this->stripFurniture($document);

            return $document;
        } catch (\Throwable) {
            return null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** Remove the elements that are never article text. */
    private function stripFurniture(\DOMDocument $document): void
    {
        foreach (self::STRIP_TAGS as $tag) {
            $nodes = $document->getElementsByTagName($tag);

            // Backwards, because the list is live: removing a node while walking
            // it forwards shortens it underneath the cursor and skips the next.
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                $node?->parentNode?->removeChild($node);
            }
        }
    }
}
