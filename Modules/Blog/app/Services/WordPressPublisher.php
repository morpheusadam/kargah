<?php

namespace Modules\Blog\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\Publishers\HttpPublisher;
use Modules\Social\Services\Publishers\MediaItem;
use Modules\Social\Services\Publishers\PublishedPost;
use Modules\Social\Services\Publishers\PublishFailed;
use Modules\Social\Services\Publishers\TakesTargetOptions;
use Modules\Social\Support\Networks;

/**
 * A WordPress site, through its own REST API and an application password.
 *
 * **No plugin.** Everything here is core WordPress: `/wp-json/wp/v2/…` has
 * shipped since 4.7, and application passwords since 5.6, one revocable row per
 * application under Users → Profile. Requiring Jetpack or a bespoke endpoint
 * would have meant asking the owner to install code on their own site before
 * Kargah could type into it, and the point of this module is that it cannot.
 *
 * ## Why this class is in `Modules\Blog` and not beside the other five drivers
 *
 * Because it needs an article. The five publishers in Social take a string and
 * some pictures; this one takes a title, a slug, an excerpt, a taxonomy and a
 * draft-or-publish decision, and the first import of Blog's idea of an article
 * into `Modules/Social` would have pointed the dependency the wrong way round.
 * `Publishing::extend()` is the extension point that makes the arrow one-way,
 * and `Modules\Blog\Providers\BlogServiceProvider` is where it is spent.
 *
 * ## The options bag
 *
 * Reached through `TakesTargetOptions::publishWithOptions()` — read that
 * interface's docblock for why it exists rather than a fourth parameter on
 * `publish()`. The keys this driver understands, all optional:
 *
 * | key                      | type              | what it does                                    |
 * |--------------------------|-------------------|-------------------------------------------------|
 * | `title`                  | string            | the post title; derived from the body without it |
 * | `slug`                   | string            | the permalink stub; WordPress makes one without it |
 * | `excerpt`                | string            | the manual excerpt                              |
 * | `status`                 | string            | `draft`, `publish`, `pending` or `private`      |
 * | `categories`             | list<string\|int> | names, resolved to term ids; ids pass straight through |
 * | `tags`                   | list<string\|int> | the same, on the `tags` taxonomy                |
 * | `featured_attachment_id` | int               | which of the post's images becomes `featured_media` |
 * | `canonical_url`          | string            | where the article really lives — see below      |
 * | `create_missing_terms`   | bool              | default true; false skips a category nobody made yet |
 *
 * **Nothing validates this array before it arrives.** It is a JSON column
 * written by one page and read by this class, so every key is treated as absent,
 * of the wrong type and a fortnight stale — a post scheduled last week is
 * publishing against options somebody typed before the site's categories
 * changed.
 *
 * ## Decisions that are not obvious
 *
 * **A missing title is derived from the body's first line, and the first line is
 * left where it is.** `publish()` — the plain, no-options path — is reached by a
 * WordPress target the ordinary social composer created, and by a post scheduled
 * before this module existed; both have to go out, and WordPress will not create
 * a post without a title worth the name. Deriving one is the friendly half. The
 * half worth arguing is that the line is *not* then removed from the content:
 * the article will read its first line twice, which is visible and fixable in
 * thirty seconds on the site, whereas silently deleting a sentence from
 * somebody's copy is data loss they cannot see from Kargah at all. Never edit
 * the body you were handed.
 *
 * **The default status is `publish`, not `draft`.** Whoever aimed a post at a
 * destination meant to publish it, and that is what every other driver here
 * does with the same intention. A silent draft would also make
 * `post_targets.status = published` a lie — Kargah would report the article as
 * delivered while it sat unlisted. The composer always writes an explicit
 * status, so this default only ever governs the fallback path.
 *
 * **`future` is deliberately not an accepted status.** Kargah's own
 * `social:publish-due` cron already holds a post until its time and then sends
 * it; handing WordPress a future date as well would put two schedulers in charge
 * of one article, and the failure mode is an article that appears twice or not
 * at all. Anything outside the allowlist falls back to the default.
 *
 * **Categories and tags arrive as names and WordPress wants term ids.** Each
 * name is searched for, and — unless `create_missing_terms` is false — created
 * when the site does not have it. Creating is the friendlier behaviour and it is
 * also a write to somebody else's site, which is why it is a named option rather
 * than an assumption: a typo in a tag becomes a permanent term, and an install
 * that would rather have a tag silently dropped than a tag silently invented can
 * say so. The lookups are cached for the length of one publish, and **only** for
 * that length — two accounts are two sites, and "news" is a different term id on
 * each of them.
 *
 * **The canonical link points away from WordPress, and it is a paragraph rather
 * than a `<link>`.** Kargah has no public web page for an article, so it cannot
 * be the canonical home of anything; the direction that means something is the
 * other one — the person says where the article was first published, and the
 * WordPress copy credits it. Two better-looking options were tried and rejected.
 * A `<link rel="canonical">` inside `content` is stripped by KSES for any user
 * without `unfiltered_html`, and survives for the users who have it only to
 * render inside `<body>`, where no crawler reads it. A `meta` field would be
 * right, but the REST API writes only meta registered with `show_in_rest`, which
 * is a plugin or a theme edit — exactly what this driver promised not to need.
 * So it is an attribution paragraph at the end of the post: honest, visible to a
 * reader, and not pretending to be an SEO directive. A site that wants the real
 * `rel=canonical` header wants an SEO plugin, and then this paragraph is
 * harmless beside it.
 *
 * **The images.** The first — or whichever `featured_attachment_id` names — is
 * uploaded to the media library and becomes `featured_media`; the rest are
 * uploaded too and appended to the content as figures, because an image sitting
 * in a media library that no post refers to is an image nobody will ever see.
 * The upload leg is `HttpPublisher::sendBytes()` with one header added:
 * WordPress takes the filename from `Content-Disposition`, there being no
 * multipart part name to take it from, and refuses the upload without it.
 */
class WordPressPublisher extends HttpPublisher implements TakesTargetOptions
{
    /**
     * What a post becomes when nothing said otherwise.
     *
     * See the class docblock: the fallback path is reached by somebody who
     * pressed publish, so this is `publish`.
     */
    private const DEFAULT_STATUS = 'publish';

    /**
     * The statuses this driver will send.
     *
     * `future` is absent on purpose and is not an oversight — Kargah's cron owns
     * time. `trash` and `auto-draft` are absent because neither is something a
     * person means by pressing a button in a composer.
     *
     * @var list<string>
     */
    private const STATUSES = ['draft', 'publish', 'pending', 'private'];

    /**
     * Term name to id, for the length of one publish and no longer.
     *
     * Ten tags on one article would otherwise be ten searches plus ten more the
     * next time the same article is retried. Reset at the top of every publish
     * because the key says nothing about which site answered: two WordPress
     * accounts are two sites, and holding "release notes" across them would
     * attach one site's term id to the other site's post — which WordPress
     * accepts, silently, filing the article under whatever that id happens to be.
     *
     * @var array<string, int|null>
     */
    private array $terms = [];

    public function network(): string
    {
        return Networks::WORDPRESS;
    }

    /**
     * The path with no article behind it.
     *
     * Delegates rather than duplicating, so there is one implementation of
     * "send a post to WordPress" and the no-options case is simply the one where
     * every option is absent. See the class docblock for what that means for the
     * title and the status.
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
        // Per publish, never across publishes. See the property's docblock.
        $this->terms = [];

        $base = $this->baseUrl($account);
        $username = $this->require($account, 'username');

        $media = $this->acceptableMedia($media);
        $body = $this->bodyWithin($body, $media);

        $title = $this->titleFor($options, $body);

        $payload = [
            'title' => $title,
            'status' => $this->statusFor($options),
        ];

        foreach (['slug', 'excerpt'] as $key) {
            $value = $this->stringOption($options, $key);

            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        // Taxonomy before pictures, deliberately. Resolving a term is a cheap
        // GET and creating one is a small POST; uploading four images is the
        // expensive, slow part of this whole call. Doing the cheap work first
        // means a mistyped option fails before ten megabytes have moved,
        // rather than after.
        $createMissing = ($options['create_missing_terms'] ?? true) !== false;

        foreach (['categories', 'tags'] as $taxonomy) {
            $ids = $this->termIds($account, $base, $username, $taxonomy, $options[$taxonomy] ?? null, $createMissing);

            if ($ids !== []) {
                $payload[$taxonomy] = $ids;
            }
        }

        [$featured, $gallery] = $this->uploadImages($account, $base, $media, $options);

        if ($featured !== null) {
            $payload['featured_media'] = $featured;
        }

        $payload['content'] = $this->contentFor($body, $options, $gallery);

        $created = $this->ask(
            $this->authorised($account),
            'post',
            $this->endpoint($base, 'posts'),
            $payload,
            $base,
            $username,
            'create a post',
        );

        $id = $created['id'] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            throw PublishFailed::malformed($this->network(), 'the site accepted the post and returned no post id');
        }

        // `link` is the permalink WordPress will actually serve, which is not
        // something Kargah can build itself: it depends on the site's permalink
        // structure, its front page setting and whether the post is private.
        $link = $created['link'] ?? null;

        return new PublishedPost((string) $id, is_string($link) && $link !== '' ? $link : null);
    }

    /**
     * Who WordPress thinks this credential is, without publishing anything.
     *
     * `context=edit` rather than the default `view`, and the difference is the
     * whole value of the call: the view context answers for a *public* profile
     * and a site that allows anonymous user listing would answer it without
     * authenticating at all, so a wrong password could come back as a success.
     * The edit context is refused unless the credential is real.
     */
    public function verify(SocialAccount $account): string
    {
        $base = $this->baseUrl($account);
        $username = $this->require($account, 'username');

        $user = $this->ask(
            $this->authorised($account),
            'get',
            $this->endpoint($base, 'users/me'),
            ['context' => 'edit'],
            $base,
            $username,
            'read its own user',
        );

        $name = $user['name'] ?? null;

        if (! is_string($name) || trim($name) === '') {
            $name = $user['slug'] ?? null;
        }

        if (! is_string($name) || trim($name) === '') {
            throw PublishFailed::malformed($this->network(), 'the credential was accepted but no user came back');
        }

        return $name;
    }

    /* The site ------------------------------------------------------------- */

    /**
     * The site's root, with everything a person might have pasted taken back off.
     *
     * Three things are normalised and each has been typed by somebody. A
     * trailing slash, which would produce `//wp-json` and a redirect that drops
     * the `Authorization` header on some hosts. A pasted `/wp-json`, because the
     * field sits next to documentation that names that path — and appending
     * ours to theirs gives `/wp-json/wp-json/wp/v2`, a 404 that reads like the
     * REST API is disabled. And a bare hostname, which is refused rather than
     * guessed at: Kargah is about to send an application password to whatever
     * this resolves to, and inventing `http://` for it would send that password
     * in clear.
     *
     * @throws PublishFailed
     */
    private function baseUrl(SocialAccount $account): string
    {
        $raw = rtrim(trim($this->require($account, 'site_url')), '/');

        $raw = rtrim((string) preg_replace('#/wp-json(/wp/v\d+)?/?$#i', '', $raw), '/');

        if (! preg_match('#^https?://[^/\s]+$#i', $raw)) {
            throw PublishFailed::rejected(
                $this->network(),
                'the site URL “'.$raw.'” is not a site address with a scheme — write it as https://example.com, '
                .'because Kargah will not guess how to reach a site it is about to send a password to',
            );
        }

        return $raw;
    }

    private function endpoint(string $base, string $path): string
    {
        return $base.'/wp-json/wp/v2/'.ltrim($path, '/');
    }

    /**
     * A request carrying the application password.
     *
     * The header is built here rather than through `withBasicAuth()`, which
     * produces the identical bytes by a longer route. Two reasons, and the first
     * is the honest one: WordPress's own documentation describes this header, so
     * the code reads like the thing it is implementing. The second is that a
     * header set on the request is a header a test can assert on without
     * depending on where in Guzzle's option pipeline `auth` is expanded.
     *
     * The spaces WordPress shows in an application password are kept exactly as
     * pasted. WordPress strips every non-alphanumeric character before comparing,
     * so both forms work, and re-typing somebody's credential on the way past is
     * not this driver's business.
     *
     * Worth knowing when a correct password is refused: Apache running PHP as
     * CGI or FastCGI drops the `Authorization` header unless the site's
     * `.htaccess` passes it through. That is a fault on the far side and nothing
     * here can fix it, but it is the usual explanation for a 401 that survives
     * regenerating the password.
     */
    private function authorised(SocialAccount $account, bool $upload = false): PendingRequest
    {
        $credential = $this->require($account, 'username').':'.$this->require($account, 'application_password');

        return ($upload ? $this->uploadRequest() : $this->request())
            ->withHeaders(['Authorization' => 'Basic '.base64_encode($credential)]);
    }

    /* The article ---------------------------------------------------------- */

    /**
     * The title, typed or derived. See the class docblock for the argument.
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
                'a WordPress post needs a title and this one has neither a title nor a first line to take one from',
            );
        }

        $limit = max(20, (int) config('blog.derived_title_characters', 120));

        return mb_strlen($first) <= $limit ? $first : rtrim(mb_substr($first, 0, $limit - 1)).'…';
    }

    /** @param array<string, mixed> $options */
    private function statusFor(array $options): string
    {
        $status = $this->stringOption($options, 'status');

        return $status !== null && in_array($status, self::STATUSES, true) ? $status : self::DEFAULT_STATUS;
    }

    /**
     * The post body, plus whatever has to ride inside it.
     *
     * The copy itself is passed through untouched. WordPress runs `wpautop` over
     * `the_content` on the way out, so a blank line between two paragraphs is
     * already a paragraph break by the time a reader sees it, and converting the
     * text to `<p>` here would only mean two things doing the same job — and one
     * of them getting a Markdown-ish body wrong.
     *
     * Everything appended is block-level and separated by blank lines, which is
     * exactly what `wpautop` leaves alone.
     *
     * @param  array<string, mixed>  $options
     * @param  list<array{url: string, alt: string}>  $gallery
     */
    private function contentFor(string $body, array $options, array $gallery): string
    {
        $parts = [trim($body)];

        foreach ($gallery as $image) {
            $parts[] = '<figure class="wp-block-image"><img src="'.e($image['url']).'" alt="'.e($image['alt']).'" /></figure>';
        }

        $canonical = $this->stringOption($options, 'canonical_url');

        if ($canonical !== null && preg_match('#^https?://#i', $canonical)) {
            // An attribution paragraph, not an SEO directive, and the docblock
            // above says why the two options that would have been one are not
            // available without a plugin.
            $parts[] = '<p class="kargah-canonical">Originally published at '
                .'<a href="'.e($canonical).'" rel="canonical">'.e($canonical).'</a>.</p>';
        }

        return implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /* Pictures -------------------------------------------------------------- */

    /**
     * Upload every image, and say which one is the featured one.
     *
     * `featured_attachment_id` names one of the post's own attachments rather
     * than a position, because a person who reorders the images on the composer
     * has not changed their mind about which one is the cover. An id that
     * matches nothing falls back to the first image, which is what the catalogue
     * entry for this network promises.
     *
     * @param  list<MediaItem>  $media
     * @param  array<string, mixed>  $options
     * @return array{0: int|null, 1: list<array{url: string, alt: string}>}
     *
     * @throws PublishFailed
     */
    private function uploadImages(SocialAccount $account, string $base, array $media, array $options): array
    {
        if ($media === []) {
            return [null, []];
        }

        $wanted = $options['featured_attachment_id'] ?? null;
        $wanted = is_numeric($wanted) ? (int) $wanted : null;

        $cover = 0;

        foreach ($media as $index => $item) {
            if ($wanted !== null && $item->id === $wanted) {
                $cover = $index;

                break;
            }
        }

        $featured = null;
        $gallery = [];

        foreach ($media as $index => $item) {
            $uploaded = $this->uploadOne($account, $base, $item);

            if ($index === $cover) {
                $featured = $uploaded['id'];

                continue;
            }

            if ($uploaded['url'] !== '') {
                $gallery[] = ['url' => $uploaded['url'], 'alt' => $item->name];
            }
        }

        return [$featured, $gallery];
    }

    /**
     * One image into the media library.
     *
     * Raw bytes as the whole body — `HttpPublisher::sendBytes()`, the transport
     * Bluesky's blob upload already needed — with `Content-Disposition` added,
     * because there is no multipart part to carry a filename and WordPress
     * refuses an upload it cannot name.
     *
     * A 401 here is reported in `HttpPublisher`'s own words rather than this
     * driver's fuller ones. That is deliberate: re-implementing the transport to
     * change an error string would duplicate the upload timeout and retry policy
     * for a sentence, and by the time a post is being published the credential
     * has already been through `verify()`, which is where a wrong password is
     * actually diagnosed.
     *
     * @return array{id: int, url: string}
     *
     * @throws PublishFailed
     */
    private function uploadOne(SocialAccount $account, string $base, MediaItem $item): array
    {
        $request = $this->authorised($account, upload: true)
            ->withHeaders(['Content-Disposition' => 'attachment; filename="'.$item->filename().'"']);

        $uploaded = $this->sendBytes($request, $this->endpoint($base, 'media'), $item->contents(), $item->mime);

        $id = $uploaded['id'] ?? null;

        if (! is_numeric($id)) {
            throw PublishFailed::malformed(
                $this->network(),
                'the media library took “'.$item->name.'” and returned no attachment id',
            );
        }

        $url = $uploaded['source_url'] ?? null;

        return ['id' => (int) $id, 'url' => is_string($url) ? $url : ''];
    }

    /* Taxonomy -------------------------------------------------------------- */

    /**
     * Names in, term ids out.
     *
     * Anything already numeric passes straight through: an options bag written
     * by a future page — or edited by hand — may well carry ids, and looking up
     * "12" as a category name would create a category called 12.
     *
     * Deduplicated, because a person typing tags into a text field types the
     * same one twice more often than you would think, and WordPress files the
     * post under it twice.
     *
     * @return list<int>
     *
     * @throws PublishFailed
     */
    private function termIds(
        SocialAccount $account,
        string $base,
        string $username,
        string $taxonomy,
        mixed $names,
        bool $createMissing,
    ): array {
        if (! is_array($names) || $names === []) {
            return [];
        }

        $ids = [];

        foreach ($names as $name) {
            if (is_int($name) || (is_string($name) && ctype_digit(trim($name)) && trim($name) !== '')) {
                $ids[(int) $name] = true;

                continue;
            }

            if (! is_string($name)) {
                continue;
            }

            $name = trim($name);

            if ($name === '') {
                continue;
            }

            $id = $this->termId($account, $base, $username, $taxonomy, $name, $createMissing);

            if ($id !== null) {
                $ids[$id] = true;
            }
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * One term's id: found, created, or given up on.
     *
     * A null is cached as well as an id. A site that does not have "changelog"
     * and an install that has asked not to create terms would otherwise search
     * for it once per article per run, and answer the same nothing every time.
     *
     * @throws PublishFailed
     */
    private function termId(
        SocialAccount $account,
        string $base,
        string $username,
        string $taxonomy,
        string $name,
        bool $createMissing,
    ): ?int {
        $key = $taxonomy.':'.mb_strtolower($name);

        if (array_key_exists($key, $this->terms)) {
            return $this->terms[$key];
        }

        $found = $this->searchTerm($account, $base, $username, $taxonomy, $name);

        if ($found === null && $createMissing) {
            $found = $this->createTerm($account, $base, $username, $taxonomy, $name);
        }

        return $this->terms[$key] = $found;
    }

    /**
     * WordPress's own search, matched properly on this side.
     *
     * `?search=` is a substring match across the name *and* the description, so
     * asking for "release" comes back with "Release notes", "Pre-release" and
     * anything whose description mentions the word. Taking the first result
     * would file articles under a category the person did not name, so the match
     * is made here, case-insensitively, against the `name` field only.
     *
     * `per_page=100` because the alternative is paginating a lookup that will
     * return three rows on any real site.
     *
     * @throws PublishFailed
     */
    private function searchTerm(
        SocialAccount $account,
        string $base,
        string $username,
        string $taxonomy,
        string $name,
    ): ?int {
        $results = $this->ask(
            $this->authorised($account),
            'get',
            $this->endpoint($base, $taxonomy),
            ['search' => $name, 'per_page' => 100],
            $base,
            $username,
            'read its '.$taxonomy,
        );

        foreach ($results as $term) {
            if (! is_array($term)) {
                continue;
            }

            $termName = $term['name'] ?? null;

            if (is_string($termName) && mb_strtolower(html_entity_decode($termName)) === mb_strtolower($name)) {
                return is_numeric($term['id'] ?? null) ? (int) $term['id'] : null;
            }
        }

        return null;
    }

    /**
     * Create the term the site did not have.
     *
     * Handled by hand rather than through `ask()` for one case: WordPress
     * answers **400 `term_exists`** when a term matches one the search did not
     * find — most often an accent, an ampersand or an entity that made the two
     * strings differ on this side and not on theirs — and it puts the existing
     * id in `data.term_id`. Treating that as a failure would refuse an article
     * over a category the site already has.
     *
     * @throws PublishFailed
     */
    private function createTerm(
        SocialAccount $account,
        string $base,
        string $username,
        string $taxonomy,
        string $name,
    ): ?int {
        $url = $this->endpoint($base, $taxonomy);

        try {
            /** @var Response $response */
            $response = $this->authorised($account)->post($url, ['name' => $name]);
        } catch (ConnectionException $e) {
            // Not `$e->getMessage()`: `$base` is a URL the operator typed into the
            // connect page, so it can carry `https://user:pass@site/` — and Guzzle
            // appends the whole effective URI to a connection failure, which then
            // lands in `post_targets.error` and on a page. `cannotReach()` takes
            // the host structurally with `parse_url()`, which drops the userinfo
            // along with everything else. Basic auth in a header is safe; a
            // credential somebody pasted into the *address* is not.
            throw $this->cannotReach($url, $e);
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->failed()) {
            $existing = $body['data']['term_id'] ?? null;

            if (is_numeric($existing)) {
                return (int) $existing;
            }

            throw $this->explain($response, $base, $username, 'create a '.rtrim($taxonomy, 's'));
        }

        return is_numeric($body['id'] ?? null) ? (int) $body['id'] : null;
    }

    /* Transport ------------------------------------------------------------- */

    /**
     * One request, decoded, with WordPress's own failures named.
     *
     * Structurally `HttpPublisher::send()`, and it exists beside it for one
     * line: `explain()`. Two of the statuses a WordPress site answers with mean
     * something specific enough that a generic 'answered HTTP 401' sends the
     * reader to the wrong place — 401 is the application password and 404 is
     * usually the site URL. Everything else falls through to the shared
     * wording.
     *
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     *
     * @throws PublishFailed
     */
    private function ask(
        PendingRequest $request,
        string $method,
        string $url,
        array $payload,
        string $base,
        string $username,
        string $what,
    ): array {
        try {
            /** @var Response $response */
            $response = $method === 'get'
                ? $request->get($url, $payload)
                : $request->post($url, $payload);
        } catch (ConnectionException $e) {
            // Not `$e->getMessage()`: `$base` is a URL the operator typed into the
            // connect page, so it can carry `https://user:pass@site/` — and Guzzle
            // appends the whole effective URI to a connection failure, which then
            // lands in `post_targets.error` and on a page. `cannotReach()` takes
            // the host structurally with `parse_url()`, which drops the userinfo
            // along with everything else. Basic auth in a header is safe; a
            // credential somebody pasted into the *address* is not.
            throw $this->cannotReach($url, $e);
        }

        if ($response->failed()) {
            throw $this->explain($response, $base, $username, $what);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw PublishFailed::malformed($this->network(), 'the body did not decode as JSON');
        }

        return $body;
    }

    /**
     * The four refusals worth their own sentence.
     *
     * A status code on its own is not a thing anybody can act on, and these four
     * have completely different fixes: regenerate a password, change a role,
     * correct the URL, shrink a file. The remaining ones go through
     * `PublishFailed::status()`, which quotes whatever the site said.
     */
    private function explain(Response $response, string $base, string $username, string $what): PublishFailed
    {
        $detail = $this->detailFrom($response);
        $said = $detail === '' ? '' : ' The site said: '.$detail;

        return match ($response->status()) {
            401 => new PublishFailed(
                'WordPress refused the application password for “'.$username.'” at '.$base.', so nothing was published. '
                .'Check it has not been revoked under Users → Profile → Application Passwords, and that the whole '
                .'password was pasted — an ordinary login password is not accepted here.'.$said,
            ),
            403 => new PublishFailed(
                'WordPress accepted the application password for “'.$username.'” at '.$base.' but will not let that '
                .'user '.$what.'. It needs a role that can publish posts and upload files — Author or above.'.$said,
            ),
            404 => new PublishFailed(
                'WordPress answered 404 for the REST API at '.$base.'/wp-json, so nothing was published. Either the '
                .'site URL is wrong or the REST API is switched off on that site; opening '.$base.'/wp-json in a '
                .'browser says which of the two it is.',
            ),
            413 => new PublishFailed(
                'WordPress refused an upload as too large at '.$base.'. The site’s own upload_max_filesize is smaller '
                .'than the image, and that is a setting on the site rather than anything Kargah can send around.',
            ),
            default => PublishFailed::status($this->network(), $response->status(), $detail),
        };
    }

    /**
     * One option as a non-empty string, or null.
     *
     * Empty strings are null here on purpose: a composer that renders a blank
     * text field writes `''`, and sending `slug: ''` to WordPress is not the
     * same as not sending it — the first asks for an empty permalink.
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
}
