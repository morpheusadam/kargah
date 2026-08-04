<?php

namespace Modules\Blog\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Modules\Data\Contracts\AttachmentService;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\Publishers\FetchesOwnMedia;
use Modules\Social\Services\Publishers\HttpPublisher;
use Modules\Social\Services\Publishers\MediaItem;
use Modules\Social\Services\Publishers\PublishedPost;
use Modules\Social\Services\Publishers\PublishFailed;
use Modules\Social\Services\Publishers\TakesTargetOptions;
use Modules\Social\Support\Networks;

/**
 * DEV.to, through its own REST API and an API key.
 *
 * One POST, no OAuth, no application to register: a key generated under
 * Settings → Extensions → DEV Community API Keys goes in a header called
 * literally `api-key`, and `POST /api/articles` creates the article. That is the
 * whole integration, and it is the cheapest destination in this catalogue by a
 * wide margin.
 *
 * **The header is `api-key`, not `Authorization: Bearer …`.** It looks like an
 * oversight and it is not — DEV's own documentation names that header, and a
 * bearer token is refused with a 401 that says nothing about which of the two
 * shapes was wrong. Worth knowing before spending an afternoon on a key that is
 * perfectly valid.
 *
 * ## Why this class is in `Modules\Blog`
 *
 * The same reason `WordPressPublisher` is, and its class docblock makes the
 * argument in full: this driver needs an *article* — a title, a markdown body,
 * tags, a cover and a canonical link — and the first import of Blog's idea of an
 * article into `Modules/Social` would point the dependency the wrong way round.
 * `Publishing::extend()` is the extension point that keeps the arrow one-way and
 * `Modules\Blog\Providers\BlogServiceProvider` is where it is spent.
 *
 * ## The options bag
 *
 * Reached through `TakesTargetOptions::publishWithOptions()`. The keys this
 * driver understands, all optional:
 *
 * | key                      | type              | what it does                                       |
 * |--------------------------|-------------------|----------------------------------------------------|
 * | `title`                  | string            | the article title; derived from the body without it |
 * | `status`                 | string            | `publish` sends `published: true`; anything else false |
 * | `tags`                   | list<string\|int> | normalised, deduplicated, capped at four — see below |
 * | `canonical_url`          | string            | sent as `canonical_url`, so DEV credits the original |
 * | `excerpt`                | string            | becomes DEV's `description`, the summary in listings |
 * | `description`            | string            | the same field, named DEV's way; wins over `excerpt` |
 * | `featured_attachment_id` | int               | which attachment becomes the cover, when there is a choice |
 *
 * Everything the WordPress composer also writes — `slug`, `categories`,
 * `create_missing_terms` — is read and ignored, because DEV has no equivalent.
 * That is not a gap: the composer writes one bag per article and each driver
 * takes the part of it that means something on its own network.
 *
 * **Nothing validates this array before it arrives.** It is a JSON column written
 * by one page and read by this class, so every key is treated as absent, of the
 * wrong type and a fortnight stale.
 *
 * ## Decisions that are not obvious
 *
 * **DEV allows at most four tags, and each one must be lowercase alphanumeric.**
 * No spaces, no hyphens, no punctuation, no accents. A tag like `Build Log` is
 * either silently dropped or rejected outright depending on which endpoint sees
 * it, which is the worst of both worlds — you cannot tell from the response
 * whether it worked. So each tag is normalised here (`Str::slug` with no
 * separator, which lowercases, transliterates and strips everything else),
 * deduplicated, and the list is cut to four.
 *
 * 🔴 **Tags beyond the fourth are dropped rather than failing the post, and that
 * is a judgement call.** An article that goes out with four of its six tags is a
 * fixable annoyance visible on DEV in ten seconds; an article that does not go
 * out at all because somebody typed a fifth tag into a field shared with
 * WordPress — which has no such limit — is a publishing tool that refuses to
 * publish. The same argument covers a tag that normalises to nothing at all, as
 * a Persian or Arabic tag will: it is dropped, not fatal. If this is ever the
 * wrong trade, the fix is three lines here and a sentence in the composer.
 *
 * **`published` maps from the same `status` option WordPress uses.** WordPress
 * takes `draft`, `publish`, `pending` and `private`; DEV has one boolean.
 * `publish` becomes `true` and **everything else becomes `false`**, which means a
 * `private` article on WordPress arrives on DEV as an unpublished draft. That is
 * not the same thing — DEV has no private article, and a draft is visible to
 * anybody with the URL — but it is the closest honest equivalent, and the
 * alternative of publishing it publicly because there was no private option is
 * the one mistake here that cannot be undone. `pending` (submitted for review)
 * maps the same way and for the same reason: nobody who asked for a review meant
 * "publish it now".
 *
 * The default, when nothing said, is `published: true`. Whoever aimed a post at
 * a destination meant to publish it, and a silent draft would make
 * `post_targets.status = published` a lie — Kargah would report the article as
 * delivered while it sat unlisted. This matches `WordPressPublisher`'s default
 * exactly, and it only ever governs the fallback path, because the composer
 * always writes an explicit status.
 *
 * ## The cover image
 *
 * **`main_image` is a URL DEV fetches. There is no upload endpoint at all.** So
 * the picture reaches DEV the way it reaches Instagram and Threads: through
 * `AttachmentService::publicUrl()`, a signed link on the `data.file-share` route
 * that sits outside `auth`, and DEV's servers go and download it.
 *
 * That means an install with no public address cannot supply one, and the
 * judgement about whether it has one — a loopback address, a private range, a
 * development TLD, or a bare hostname with no dot in it — is
 * `installIsFetchable()`'s, from
 * `Modules\Social\Services\Publishers\FetchesOwnMedia`. `MetaGraph`,
 * `SlackPublisher` and this class each once carried a verbatim copy of the
 * same twelve-line test; the trait is where it lives now, and `use
 * FetchesOwnMedia` above is this class pointing at it rather than keeping its
 * own.
 *
 * 🔴 **What is different here is what happens next, and it is the opposite of
 * Instagram.** Instagram refuses the post outright when it cannot fetch a
 * picture, because Instagram has no text-only post — the API has no edge for
 * one, so there is nothing to try. A DEV article is text; the cover is a
 * decoration. So an unreachable install **degrades to no cover and publishes the
 * article**, rather than failing it. Refusing to publish an eight-hundred-word
 * post because a thumbnail could not be fetched would be Kargah inventing a
 * requirement DEV does not have.
 *
 * The same degradation covers an attachment row whose file has gone: no cover,
 * article published. The only image failures that are fatal are the ones
 * `acceptableMedia()` raises before anything moves — a type DEV does not take,
 * or more pictures than the catalogue allows — because those are the person's
 * own attachment being wrong rather than the install being small.
 *
 * 🔴 **The link handed to DEV lives for `COVER_URL_MINUTES`, not
 * `AttachmentService::publicUrl()`'s thirty-minute default, and that number is
 * a guess.** Thirty minutes is right for Instagram and Threads because Meta
 * fetches the bytes during the one container-create call that is open right
 * now — the link only has to survive a single round trip. **Nothing in this
 * session corroborates that DEV does the same for `main_image`.** If DEV
 * instead renders the URL live rather than downloading it once, a thirty-minute
 * link means the cover on a public, already-published article starts
 * answering 403 the moment the signature expires — and nothing here would
 * notice: `post_targets.status` stays `published`, no error is ever written,
 * and the first anyone hears of it is a reader mentioning a broken image, days
 * later. Assuming "DEV ingests like Meta" and leaving the default in place is
 * the cheap guess and the dangerous one to be wrong about, so this asks for a
 * year instead — long enough to survive DEV rendering it live, and
 * deliberately **not** "no expiry": the trade-off named at the top of
 * `AttachmentService::publicUrl()` still holds at this length, because
 * `data.file-share` has no protection but the signature, and a link that never
 * expires is a permanent public URL for a private attachment the day it leaks
 * into a referrer log or a scraper. If the guess is wrong the other way — DEV
 * does ingest immediately — the only cost is a larger blast radius on a link
 * nobody was ever going to use past the one request DEV makes with it. **How
 * to tell which is true**: open an already-published DEV article a day after
 * it went out and check whether its cover is served from a DEV-owned host
 * (ingested, and this guess was right) or still points back at this install's
 * own `data.file-share` (rendered live, and the real risk this comment is
 * about). Kargah does not check this itself; the cheap version would be a
 * scheduled job that re-fetches each published article's own page and flags
 * one whose cover still resolves to this install's own host — that is future
 * work, not built here.
 */
class DevToPublisher extends HttpPublisher implements TakesTargetOptions
{
    use FetchesOwnMedia;

    /** The API root. Versionless, because DEV's own docs are. */
    private const API = 'https://dev.to/api';

    /**
     * DEV's number, not Kargah's.
     *
     * Send a fifth and the behaviour is undefined in the useful sense: some
     * endpoints drop it, some answer 422. See the class docblock for why the
     * fifth is dropped here instead of failing the post.
     */
    private const MAX_TAGS = 4;

    /** The one status that means `published: true`. See the class docblock. */
    private const PUBLISHED_STATUS = 'publish';

    /**
     * How long the signature on the cover link stays valid, in minutes.
     *
     * Not `AttachmentService::publicUrl()`'s own thirty-minute default — see
     * the class docblock's cover-image section for the guess this number rests
     * on and what breaks if it is wrong.
     */
    private const COVER_URL_MINUTES = 60 * 24 * 365;

    public function network(): string
    {
        return Networks::DEVTO;
    }

    /**
     * The path with no article behind it.
     *
     * Delegates rather than duplicating, exactly as `WordPressPublisher` does,
     * so there is one implementation of "send an article to DEV" and the
     * no-options case is simply the one where every option is absent. It is
     * reached by a DEV target the ordinary social composer created, and by a
     * post scheduled before this driver existed; both have to go out.
     *
     * @param  list<MediaItem>  $media
     */
    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        return $this->publishWithOptions($account, $body, $media, []);
    }

    /**
     * @param  list<MediaItem>  $media
     * @param  array<string, mixed>  $options
     */
    public function publishWithOptions(
        SocialAccount $account,
        string $body,
        array $media = [],
        array $options = [],
    ): PublishedPost {
        $media = $this->acceptableMedia($media);
        $body = $this->bodyWithin($body, $media);

        $article = [
            'title' => $this->titleFor($options, $body),
            // Untouched. DEV renders markdown itself and the body is the
            // person's copy — see `titleFor()` on why not even the first line
            // is taken out of it.
            'body_markdown' => $body,
            'published' => $this->publishedFor($options),
        ];

        $tags = $this->tagsFor($options);

        if ($tags !== []) {
            $article['tags'] = $tags;
        }

        $canonical = $this->urlOption($options, 'canonical_url');

        if ($canonical !== null) {
            // A real field rather than WordPress's attribution paragraph: DEV
            // writes `rel=canonical` into the page head from this, which is the
            // whole reason cross-posting to DEV is safe for search at all.
            $article['canonical_url'] = $canonical;
        }

        $description = $this->stringOption($options, 'description') ?? $this->stringOption($options, 'excerpt');

        if ($description !== null) {
            $article['description'] = $description;
        }

        $cover = $this->coverUrl($media, $options);

        if ($cover !== null) {
            $article['main_image'] = $cover;
        }

        $created = $this->ask(
            $this->authorised($account),
            'post',
            self::API.'/articles',
            ['article' => $article],
            'create an article',
        );

        $id = $created['id'] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            throw PublishFailed::malformed($this->network(), 'DEV accepted the article and returned no article id');
        }

        // `url` is the permalink DEV will serve. It contains the slug DEV
        // derived from the title, which is not something Kargah can build
        // itself — a duplicate title gets a random suffix.
        $url = $created['url'] ?? null;

        return new PublishedPost((string) $id, is_string($url) && $url !== '' ? $url : null);
    }

    /**
     * Who DEV thinks this key is, without publishing anything.
     *
     * `GET /api/users/me` is the whole check: it is refused without a valid key,
     * it creates nothing, and it answers with the username — which is the point.
     * A connect page that only said "the key works" would not have proved the
     * key belongs to the account the person thinks it does, and pasting the
     * wrong key from a second DEV account is a mistake nobody notices until an
     * article appears on the wrong profile.
     */
    public function verify(SocialAccount $account): string
    {
        $user = $this->ask(
            $this->authorised($account),
            'get',
            self::API.'/users/me',
            [],
            'read its own user',
        );

        $username = $user['username'] ?? null;

        if (! is_string($username) || trim($username) === '') {
            throw PublishFailed::malformed($this->network(), 'the key was accepted but no username came back');
        }

        return '@'.trim($username);
    }

    /* The request ----------------------------------------------------------- */

    /**
     * A request carrying the API key.
     *
     * The header name is DEV's and it is lowercase and hyphenated: `api-key`.
     * Written as a literal rather than through any of Laravel's auth helpers
     * because none of them produce this shape, and because a header set on the
     * request is a header a test can assert on.
     */
    private function authorised(SocialAccount $account): PendingRequest
    {
        return $this->request()->withHeaders(['api-key' => $this->require($account, 'api_key')]);
    }

    /**
     * One request, decoded, with DEV's own failures named.
     *
     * Structurally `HttpPublisher::send()`, and it exists beside it for
     * `explain()`: three of the statuses DEV answers with mean something
     * specific enough that 'answered HTTP 401' sends the reader to the wrong
     * place entirely.
     *
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     *
     * @throws PublishFailed
     */
    private function ask(PendingRequest $request, string $method, string $url, array $payload, string $what): array
    {
        try {
            /** @var Response $response */
            $response = $method === 'get'
                ? $request->get($url, $payload)
                : $request->post($url, $payload);
        } catch (ConnectionException $e) {
            // The central redaction rather than the raw message. This driver
            // authenticates in a header, so nothing secret is in the URL today —
            // but a connection failure's message is written to
            // `post_targets.error` and rendered on a page, and the next endpoint
            // added here should not have to remember that. See
            // `HttpPublisher::cannotReach()` for why it is not a `str_replace`.
            throw $this->cannotReach($url, $e);
        }

        if ($response->failed()) {
            throw $this->explain($response, $what);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw PublishFailed::malformed($this->network(), 'the body did not decode as JSON');
        }

        return $body;
    }

    /**
     * The three refusals worth their own sentence.
     *
     * Each has a completely different fix — regenerate a key, correct a tag,
     * wait — and a status code on its own says none of that. Everything else
     * goes through `PublishFailed::status()`, which quotes whatever DEV said;
     * DEV puts its reason in `error`, which `detailFrom()` already reads.
     */
    private function explain(Response $response, string $what): PublishFailed
    {
        $detail = $this->detailFrom($response);
        $said = $detail === '' ? '' : ' DEV said: '.$detail;

        return match ($response->status()) {
            401 => new PublishFailed(
                'DEV refused the API key, so nothing was published. Check it has not been revoked under Settings → '
                .'Extensions → DEV Community API Keys, and that it was pasted whole — DEV sends the key in a header '
                .'called api-key rather than as a bearer token, so a key that works elsewhere is not the issue.'.$said,
            ),
            422 => new PublishFailed(
                'DEV would not '.$what.' because it did not accept the article as written. The usual cause is a tag '
                .'DEV will not take — they must be lowercase and alphanumeric — or an article with this exact title '
                .'already on the account.'.$said,
            ),
            429 => new PublishFailed(
                'DEV is rate limiting this account, so the article was not published. It allows only a handful of '
                .'articles in quick succession; press retry in a minute and it will very likely go out.'.$said,
            ),
            default => PublishFailed::status($this->network(), $response->status(), $detail),
        };
    }

    /* The article ------------------------------------------------------------ */

    /**
     * The title, typed or derived.
     *
     * `WordPressPublisher::titleFor()` makes the argument in full and this is
     * the same behaviour, deliberately: a missing title is taken from the body's
     * first line, and **the first line is not then stripped**. The article reads
     * its first line twice, which is visible and fixable in thirty seconds on
     * DEV, whereas silently deleting a sentence from somebody's copy is data
     * loss they cannot see from Kargah at all. Never edit the body you were
     * handed.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws PublishFailed
     */
    private function titleFor(array $options, string $body): string
    {
        $title = $this->stringOption($options, 'title');

        if ($title !== null) {
            return $title;
        }

        $first = (string) (preg_split('/\R/u', trim($body), 2)[0] ?? '');
        $first = trim((string) preg_replace('/\s+/u', ' ', $first));

        if ($first === '') {
            throw PublishFailed::rejected(
                $this->network(),
                'a DEV article needs a title and this one has neither a title nor a first line to take one from',
            );
        }

        $limit = max(20, (int) config('blog.derived_title_characters', 120));

        return mb_strlen($first) <= $limit ? $first : rtrim(mb_substr($first, 0, $limit - 1)).'…';
    }

    /**
     * WordPress's four-valued status as DEV's one boolean.
     *
     * See the class docblock: `publish` is true, everything else is false, and
     * absent is true because the fallback path is reached by somebody who
     * pressed publish.
     *
     * @param  array<string, mixed>  $options
     */
    private function publishedFor(array $options): bool
    {
        $status = $this->stringOption($options, 'status');

        return $status === null || $status === self::PUBLISHED_STATUS;
    }

    /**
     * Tag names as DEV will take them: lowercase, alphanumeric, at most four.
     *
     * `Str::slug($tag, '')` does the whole normalisation — it transliterates
     * (`Café` becomes `cafe`), lowercases, and removes everything that is not a
     * letter or a digit including the separator itself. Deduplicated afterwards,
     * because `Build log` and `build-log` normalise to the same tag and DEV
     * would otherwise be sent it twice.
     *
     * Numeric entries are accepted as strings: an options bag written by a
     * future page, or edited by hand, may carry them, and dropping a tag called
     * `2026` would be surprising.
     *
     * @param  array<string, mixed>  $options
     * @return list<string>
     */
    private function tagsFor(array $options): array
    {
        $raw = $options['tags'] ?? null;

        if (! is_array($raw)) {
            return [];
        }

        $tags = [];

        foreach ($raw as $tag) {
            if (! is_string($tag) && ! is_int($tag)) {
                continue;
            }

            $normalised = Str::slug((string) $tag, '');

            // 🔴 Dropped, not transliterated. `Str::slug()` runs `Str::ascii()`
            // first, so a tag that is not already Latin does **not** normalise to
            // nothing — `برنامه‌نویسی` becomes `brnamhnoysy`, a non-word Kargah
            // invented, which DEV would then publish on a public article under
            // somebody's name. A missing tag is a fixable annoyance the author can
            // see; an invented one is copy nobody wrote. The second slug with
            // transliteration disabled is how "was already Latin" is told apart
            // from "was made Latin".
            if ($normalised === '' || $normalised !== Str::slug((string) $tag, '', null)) {
                continue;
            }

            $tags[$normalised] = true;

            // The cap is applied here rather than with `array_slice` so that
            // duplicates and dropped tags do not eat one of the four places.
            if (count($tags) === self::MAX_TAGS) {
                break;
            }
        }

        return array_keys($tags);
    }

    /* The cover -------------------------------------------------------------- */

    /**
     * A URL DEV can fetch the cover from, or null to publish without one.
     *
     * Null three times over, and each of them is a deliberate degradation rather
     * than a failure — see the class docblock for why this is the opposite of
     * Instagram's answer to the same question.
     *
     * @param  list<MediaItem>  $media
     * @param  array<string, mixed>  $options
     */
    private function coverUrl(array $media, array $options): ?string
    {
        if ($media === []) {
            return null;
        }

        if (! $this->installIsFetchable()) {
            return null;
        }

        return app(AttachmentService::class)->publicUrl(
            $this->coverItem($media, $options)->id,
            self::COVER_URL_MINUTES,
        );
    }

    /**
     * Which attachment is the cover.
     *
     * Named by attachment id rather than by position, for the reason
     * `WordPressPublisher` names it that way: somebody who reorders the images
     * in the composer has not changed their mind about which one is the cover.
     * An id matching nothing falls back to the first.
     *
     * The catalogue currently allows DEV one image, so this almost always picks
     * `$media[0]` — it is written properly anyway because the alternative is a
     * silent wrong answer the day that number changes.
     *
     * @param  non-empty-list<MediaItem>  $media
     * @param  array<string, mixed>  $options
     */
    private function coverItem(array $media, array $options): MediaItem
    {
        $wanted = $options['featured_attachment_id'] ?? null;
        $wanted = is_numeric($wanted) ? (int) $wanted : null;

        if ($wanted !== null) {
            foreach ($media as $item) {
                if ($item->id === $wanted) {
                    return $item;
                }
            }
        }

        return $media[0];
    }

    /* Options ----------------------------------------------------------------- */

    /**
     * One option as a non-empty string, or null.
     *
     * Empty strings are null on purpose: a composer that renders a blank text
     * field writes `''`, and sending `description: ''` is not the same as not
     * sending it.
     *
     * @param  array<string, mixed>  $options
     */
    private function stringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * One option as an http or https URL, or null.
     *
     * Checked rather than passed through, because DEV answers 422 for a
     * `canonical_url` it cannot parse and that failure would be reported as
     * "DEV would not create an article" for something the person typed into a
     * field labelled Canonical link.
     *
     * @param  array<string, mixed>  $options
     */
    private function urlOption(array $options, string $key): ?string
    {
        $value = $this->stringOption($options, $key);

        return $value !== null && preg_match('#^https?://#i', $value) === 1 ? $value : null;
    }
}
