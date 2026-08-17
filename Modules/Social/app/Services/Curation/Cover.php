<?php

namespace Modules\Social\Services\Curation;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Modules\Social\Services\Curation\Sources\HttpSource;

/**
 * A picture for the day's post, in a shape every network will take.
 *
 * **Instagram is the reason this class is not optional.** There is no text-only
 * post in its publishing API: a post without a picture is not a post. Its image
 * container also accepts JPEG and nothing else, and refuses a PNG with an error
 * naming neither the file nor the reason. So "find a picture" here means "find a
 * picture and turn it into something Instagram will accept", and the second half
 * is most of the work.
 *
 * ---
 *
 * ## The order candidates are tried in
 *
 * 1. Whatever the feed itself offered — free, already chosen by the publisher.
 * 2. `og:image` or `twitter:image` on the article page, which is the picture the
 *    publisher wants shown when the article is shared. Usually the best one there
 *    is.
 * 3. The images inside the article body.
 *
 * Each is measured before it is accepted, because a URL that 404s or resolves to
 * a 56-pixel logo costs the post its picture at send time rather than here.
 *
 * ## Padding, and why it is not the thing that was rejected before
 *
 * A previous session built a composited cover — blur, a translucent card, the
 * Persian headline drawn over the picture — and the owner asked for it to be
 * removed. It stays removed. This class draws nothing and writes no text.
 *
 * What it does do is add background either side of a photograph whose proportions
 * Instagram refuses outright. A 1080×2400 phone screenshot has a ratio of 0.45,
 * against a feed minimum of 0.8, and the alternatives to padding it are posting
 * nothing to Instagram that day or having Instagram crop it wherever it likes.
 * Nothing is drawn on top of the image and none of it is hidden.
 */
class Cover
{
    /**
     * Below this on the short side, a picture is a logo or an icon.
     *
     * 400 was arrived at by measurement: favicon services return 56×56 for a
     * request that asks for 256, and those render in a feed as a smeared square.
     * Falling back to no picture is tidier than publishing one.
     */
    public const MIN_SIDE = 400;

    /** Good enough to stop looking. Anything further is a wasted request per candidate. */
    private const GOOD_ENOUGH = 900;

    /** Instagram's feed accepts 4:5 through 1.91:1. Both are hard refusals, not crops. */
    public const MIN_RATIO = 0.8;

    public const MAX_RATIO = 1.91;

    /** Comfortably inside Instagram's 8 MB and every other network's ceiling. */
    private const MAX_BYTES = 5_000_000;

    /** How many candidates are worth an HTTP request each. */
    private const MAX_CANDIDATES = 6;

    /**
     * The neutral the padding is filled with.
     *
     * A fixed dark grey rather than white, because these covers sit in feeds that
     * are dark as often as light and a white band reads as a mistake against a
     * dark screenshot. Sampling the image's own edge was considered and dropped:
     * it looks clever on a photograph and produces a coloured smear on a chart.
     */
    private const PAD_RGB = [17, 19, 24];

    private const TIMEOUT = 15;

    /**
     * A picture for this story, already converted, or null.
     *
     * Null is an ordinary answer. Every network except Instagram publishes fine
     * without one, and the caller decides what that means — see `DailyCurator`,
     * which skips Instagram rather than failing the day.
     *
     * @return array{contents: string, name: string, mime: string, width: int, height: int}|null
     */
    public function forStory(Story $story, ?string $articleHtml = null): ?array
    {
        foreach ($this->candidates($story, $articleHtml) as $url) {
            $image = $this->load($url);

            if ($image === null) {
                continue;
            }

            [$resource, $width, $height] = $image;

            try {
                return $this->encode($resource, $story);
            } finally {
                imagedestroy($resource);
            }
        }

        return null;
    }

    /**
     * Candidate URLs, best first.
     *
     * @return list<string>
     */
    private function candidates(Story $story, ?string $html): array
    {
        $found = [];

        if ($story->imageUrl !== null) {
            $found[] = $story->imageUrl;
        }

        if ($html !== null) {
            foreach ($this->fromMeta($html) as $url) {
                $found[] = $this->absolute($url, $story->url);
            }

            foreach ($this->fromBody($html) as $url) {
                $found[] = $this->absolute($url, $story->url);
            }
        }

        $unique = [];

        foreach ($found as $url) {
            if ($url !== null && str_starts_with($url, 'http') && ! in_array($url, $unique, true)) {
                $unique[] = $url;
            }
        }

        return array_slice($unique, 0, self::MAX_CANDIDATES);
    }

    /**
     * `og:image` and `twitter:image`, which is the picture the publisher chose.
     *
     * @return list<string>
     */
    private function fromMeta(string $html): array
    {
        preg_match_all(
            '/<meta[^>]+(?:property|name)=["\'](?:og:image(?::url)?|twitter:image(?::src)?)["\'][^>]*>/i',
            $html,
            $tags,
        );

        $urls = [];

        foreach ($tags[0] as $tag) {
            if (preg_match('/content=["\']([^"\']+)["\']/i', $tag, $content) === 1) {
                // Entities, because an og:image with query parameters arrives
                // with `&amp;` in it and fetches as a 404 exactly as written.
                $urls[] = html_entity_decode($content[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $urls;
    }

    /**
     * Pictures inside the article, minus the ones that are never photographs.
     *
     * @return list<string>
     */
    private function fromBody(string $html): array
    {
        preg_match_all('/<img\b[^>]*>/i', $html, $tags);

        $urls = [];

        foreach ($tags[0] as $tag) {
            if (preg_match('/\b(?:data-src|data-original|srcset|src)=["\']([^"\']+)["\']/i', $tag, $src) !== 1) {
                continue;
            }

            // `srcset` lists sizes smallest first, comma separated, each with a
            // width descriptor. The last is the largest — taking the first is a
            // real bug this pipeline has had, and it silently published thumbnails.
            $candidate = trim(explode(' ', trim(explode(',', $src[1])[count(explode(',', $src[1])) - 1]))[0]);

            if ($candidate === '' || preg_match('/(sprite|icon|logo|avatar|favicon|placeholder|pixel|spacer|blank|1x1|badge|button|emoji|gravatar|advert|banner)/i', $candidate) === 1) {
                continue;
            }

            $urls[] = html_entity_decode($candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $urls;
    }

    /** Resolve a possibly-relative URL against the page it was found on. */
    private function absolute(string $url, string $base): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, 'http')) {
            return $url;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';

        if ($host === '') {
            return null;
        }

        if (str_starts_with($url, '//')) {
            return $scheme.':'.$url;
        }

        if (str_starts_with($url, '/')) {
            return $scheme.'://'.$host.$url;
        }

        $path = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $scheme.'://'.$host.$path.'/'.$url;
    }

    /**
     * Fetch and decode one candidate, or null if it is not a usable picture.
     *
     * 🔴 The dimensions are read from the decoded bytes rather than trusted from
     * the content type, because a great many sites serve a 56-pixel icon as
     * `image/png` quite correctly, and that is exactly the thing that must not be
     * published.
     *
     * @return array{0: \GdImage, 1: int, 2: int}|null
     */
    private function load(string $url): ?array
    {
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

        $type = (string) $response->header('content-type');

        // SVG decodes to nothing useful here and is refused by every network in
        // the catalogue, so it is dropped before GD is asked to try.
        if (! str_starts_with($type, 'image/') || str_starts_with($type, 'image/svg')) {
            return null;
        }

        $bytes = $response->body();

        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if (min($width, $height) < self::MIN_SIDE) {
            imagedestroy($image);

            return null;
        }

        return [$image, $width, $height];
    }

    /**
     * Pad if the proportions need it, then encode as JPEG.
     *
     * JPEG unconditionally, even for a picture that arrived as a perfectly good
     * PNG: Instagram's container takes nothing else, and one format for every
     * network means one thing that can be wrong instead of five.
     *
     * @return array{contents: string, name: string, mime: string, width: int, height: int}
     */
    private function encode(\GdImage $image, Story $story): array
    {
        $canvas = $this->padded($image);

        ob_start();
        // 88 rather than 100: the difference is invisible at feed size and the
        // file is a third of the weight, which matters on the upload rather than
        // in the render.
        imagejpeg($canvas, null, 88);
        $contents = (string) ob_get_clean();

        $width = imagesx($canvas);
        $height = imagesy($canvas);

        if ($canvas !== $image) {
            imagedestroy($canvas);
        }

        return [
            'contents' => $contents,
            'name' => $this->filename($story),
            'mime' => 'image/jpeg',
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * The image inside a canvas Instagram will accept, or the image itself.
     *
     * Returns the original resource untouched when the proportions are already
     * inside the range, which is the common case and worth not copying for.
     */
    public function padded(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $ratio = $width / $height;

        if ($ratio >= self::MIN_RATIO && $ratio <= self::MAX_RATIO) {
            return $image;
        }

        // Too tall: widen the canvas to the narrowest ratio allowed. Too wide:
        // heighten it to the widest. Either way the picture keeps every pixel it
        // had and gains a band on two sides.
        if ($ratio < self::MIN_RATIO) {
            $canvasWidth = (int) ceil($height * self::MIN_RATIO);
            $canvasHeight = $height;
        } else {
            $canvasWidth = $width;
            $canvasHeight = (int) ceil($width / self::MAX_RATIO);
        }

        $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);

        imagefill(
            $canvas,
            0,
            0,
            imagecolorallocate($canvas, self::PAD_RGB[0], self::PAD_RGB[1], self::PAD_RGB[2]),
        );

        imagecopy(
            $canvas,
            $image,
            (int) (($canvasWidth - $width) / 2),
            (int) (($canvasHeight - $height) / 2),
            0,
            0,
            $width,
            $height,
        );

        return $canvas;
    }

    /**
     * A filename that says where the picture came from.
     *
     * It lands in the Files page as an attachment on the post, and "cover.jpg"
     * forty times over is a list nobody can read.
     */
    private function filename(Story $story): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', $story->publisher ?? $story->label) ?? 'cover';

        return trim(strtolower($slug), '-').'-'.$story->publishedAt->format('Ymd').'.jpg';
    }
}
