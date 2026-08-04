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
 * A Hashnode publication, through its GraphQL API and a personal access token.
 *
 * One endpoint — `https://gql.hashnode.com/` — for everything: publishing an
 * article, and asking who the token belongs to. There is no REST surface, no
 * per-resource URL and no HTTP verb that means anything; a GraphQL API is one
 * POST and the document decides what happened.
 *
 * **The token goes in `Authorization` raw, without `Bearer `.** That is
 * Hashnode's shape and it is the sort of detail that costs an afternoon: adding
 * the prefix produces an unauthenticated request, and an unauthenticated request
 * to a GraphQL endpoint is answered with **HTTP 200** and an error envelope, so
 * nothing about the failure looks like an auth failure until you read the body.
 *
 * ## 🔴 GraphQL answers HTTP 200 for an error, and this driver reads the envelope
 *
 * A successful status code from `gql.hashnode.com` is not evidence of anything.
 * A refused mutation comes back as `200 OK` with `data: null` and a top-level
 * `errors` array of `{message, extensions}` objects. `$response->successful()`
 * is true, `$response->failed()` is false, and an implementation that trusted
 * either would record the article as published and hand `post_targets` a null id.
 *
 * This is the fourth costume of a trap this codebase has already met three
 * times. Telegram wraps everything in `{ok: false}` behind a 200; Slack answers
 * `{ok: false, error: …}`; VK puts an `error` object in a 200; Meta puts an
 * `error` envelope in a 4xx that is sometimes a 2xx. The rule that falls out of
 * all four is the same one: **read the envelope, never the status.** So `ask()`
 * below looks at `errors` *first* — before `failed()`, before touching `data` —
 * and the first `message` in it is the sentence the person sees on the failed
 * target, because it is Hashnode's own words and it is usually exact ("Tag with
 * slug 'x' not found", "Invalid publication ID").
 *
 * ## The options bag
 *
 * Reached through `TakesTargetOptions::publishWithOptions()`. The keys this
 * driver understands, all optional:
 *
 * | key                      | type              | what it does                                        |
 * |--------------------------|-------------------|-----------------------------------------------------|
 * | `title`                  | string            | the post title; derived from the body without it     |
 * | `status`                 | string            | only `publish` is sendable — see below               |
 * | `tags`                   | list<string\|int> | each becomes `{slug, name}`; capped at five          |
 * | `canonical_url`          | string            | sent as `originalArticleURL`                         |
 * | `featured_attachment_id` | int               | which attachment becomes `coverImageOptions`         |
 *
 * `slug`, `excerpt`, `categories` and `create_missing_terms` are read and
 * ignored: the first two have no corroborated field on this input type and the
 * last two are WordPress taxonomy. See "What is deliberately not sent" below,
 * because on GraphQL that is a load-bearing decision rather than a gap.
 *
 * **Nothing validates this array before it arrives.** Treat every key as absent,
 * of the wrong type and a fortnight stale.
 *
 * ## Decisions that are not obvious
 *
 * 🔴 **What is deliberately not sent, and why that matters more here than
 * anywhere else in this module.** A REST API ignores a field it does not know.
 * **GraphQL refuses the entire document** — one unrecognised key on
 * `PublishPostInput` fails the whole mutation with a validation error and
 * publishes nothing. So the input is built from the smallest set of fields that
 * are corroborated, and every plausible extra is left out on purpose:
 * `slug`, `subtitle`, `metaTags`, `series` and `settings` are all fields this
 * input type is believed to accept, and not one of them is worth risking an
 * article over. If a later session confirms one from Hashnode's own schema, add
 * it and say where it was confirmed.
 *
 * **`publishedAt` is not sent either, and that one is an argument rather than
 * caution.** The input accepts it, and Kargah's own `social:publish-due` cron
 * already holds a post until its time and then sends it. Handing Hashnode a
 * future date as well would put two schedulers in charge of one article, and the
 * failure mode is an article that appears twice or not at all. This is exactly
 * why `WordPressPublisher` refuses WordPress's `future` status. Omitting it
 * means Hashnode stamps the article at the moment it receives it, which is the
 * moment Kargah decided to send it — the two agree by construction.
 *
 * 🔴 **A non-`publish` status is refused rather than published.** `publishPost`
 * is the only mutation here, and it publishes. Hashnode's drafts live behind a
 * different mutation (`createDraft`) with a different input type that this
 * driver does not implement, so there are exactly two honest options for an
 * article marked draft, pending or private: refuse it, or publish it anyway.
 * Publishing anyway is irreversible and public — it is the one mistake in this
 * whole module that cannot be taken back — whereas a refusal is a red row on the
 * posts page with a sentence explaining it, next to a WordPress target that got
 * its draft. So this refuses, before any request is made, and names `createDraft`
 * in the message so the reader knows the limitation is Kargah's rather than
 * theirs. The composer writes one status for every article destination on a
 * post, which is what makes this reachable at all.
 *
 * **Tags carry both a `slug` and a `name`, and the slug is derived here.**
 * Hashnode wants objects, not strings, and the slug is what it matches an
 * existing tag on. `Str::slug()` — lowercase, hyphenated, transliterated — is
 * the assumption, and it is the part of this file most likely to be wrong: if
 * Hashnode's own slug for a tag differs (its published tags use hyphenated
 * lowercase, which is what this produces), the mutation either creates a new tag
 * or is refused with "tag not found", and the message will say which. The name
 * is kept exactly as the person typed it, because that is the half a reader
 * sees. Capped at five, which is Hashnode's documented maximum and is
 * **uncorroborated from a first-party source in this session** — it is reported
 * as such.
 *
 * ## The cover image
 *
 * `coverImageOptions: {coverImageURL: …}` is a URL Hashnode fetches; its own
 * upload endpoint is not part of the public API. So the picture reaches it
 * exactly as it reaches DEV and Meta — through
 * `AttachmentService::publicUrl()`, a signed link on `data.file-share` that sits
 * outside `auth`.
 *
 * An install with no public address cannot supply one, and the judgement about
 * whether it has one — a loopback address, a private range, a development
 * TLD, or a bare hostname with no dot in it — comes from
 * `Modules\Social\Services\Publishers\FetchesOwnMedia`, which the five
 * destinations that need it now share rather than each keeping its own copy.
 * As with DEV and **unlike Instagram**, that is not fatal: an article is text
 * and the cover is a decoration, so the post goes out without one rather than
 * not at all. Instagram refuses, because Instagram has no text-only post and
 * there is no request that could succeed.
 *
 * 🔴 **The link handed to Hashnode lives for `COVER_URL_MINUTES`, and that
 * number is the same guess `DevToPublisher` makes, for the same reason — see
 * its class docblock for the argument in full.** Meta's thirty-minute default
 * is right because Meta ingests the bytes during the one call that is open;
 * nothing here corroborates that Hashnode does the same for `coverImageURL`
 * rather than rendering it live wherever the reader's browser is pointed. If
 * it renders live, thirty minutes means the cover on a public,
 * already-published post starts 403ing the moment the signature expires, with
 * `post_targets.status` still reading `published` and nothing in Kargah ever
 * noticing — so this asks for a year instead of the default, and deliberately
 * not "no expiry": `data.file-share` has no protection but the signature, and
 * a link that never expires is a permanent public URL for a private
 * attachment the day it leaks into a referrer log or a scraper. How to tell
 * which guess is true: open a published Hashnode post a day later and check
 * whether its cover still comes from this install's own host rather than
 * Hashnode's; a scheduled check for exactly that is named as future work in
 * `DevToPublisher`'s docblock rather than built here.
 */
class HashnodePublisher extends HttpPublisher implements TakesTargetOptions
{
    use FetchesOwnMedia;

    /** The whole API. One URL, one verb. */
    private const ENDPOINT = 'https://gql.hashnode.com/';

    /**
     * The publish mutation, named so Hashnode's logs and any error it returns
     * can say which operation failed.
     *
     * `post { id slug url }` is the smallest selection that gives
     * `PublishedPost` both halves of what it records. `slug` is asked for and
     * not used: it costs nothing, and it is the field a future "open on
     * Hashnode" link would want if `url` ever stops being returned.
     */
    private const PUBLISH_MUTATION = 'mutation PublishPost($input: PublishPostInput!) '
        .'{ publishPost(input: $input) { post { id slug url } } }';

    /** Who the token belongs to. Reads, creates nothing — see `verify()`. */
    private const ME_QUERY = 'query Me { me { username } }';

    /** The one status that can be sent. See the class docblock. */
    private const PUBLISHED_STATUS = 'publish';

    /**
     * Hashnode's number, not Kargah's, and uncorroborated in this session.
     *
     * Five is the documented maximum. If it is wrong in the generous direction
     * the cap costs a tag; if it is wrong in the strict direction Hashnode
     * refuses the mutation and says so, which is recoverable. Erring towards the
     * documented number is the cheaper of the two.
     */
    private const MAX_TAGS = 5;

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
        return Networks::HASHNODE;
    }

    /**
     * The path with no article behind it.
     *
     * Delegates rather than duplicating, exactly as `WordPressPublisher` does.
     * Reached by a Hashnode target the ordinary social composer created, and by
     * a post scheduled before this driver existed; both have to go out, and with
     * no options at all the status defaults to publish, which is what somebody
     * who aimed a post at a destination meant.
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
        $publication = $this->require($account, 'publication_id');

        // Before anything is sent, because there is no request that could
        // honour it. See the class docblock: publishing something marked draft
        // is the one mistake here that cannot be undone.
        $this->refuseUnpublishable($options);

        $media = $this->acceptableMedia($media);
        $body = $this->bodyWithin($body, $media);

        $input = [
            'publicationId' => $publication,
            'title' => $this->titleFor($options, $body),
            // Untouched. Hashnode renders markdown itself, and not even the
            // first line is taken out — see `titleFor()`.
            'contentMarkdown' => $body,
        ];

        $tags = $this->tagsFor($options);

        if ($tags !== []) {
            $input['tags'] = $tags;
        }

        $cover = $this->coverUrl($media, $options);

        if ($cover !== null) {
            $input['coverImageOptions'] = ['coverImageURL' => $cover];
        }

        $canonical = $this->urlOption($options, 'canonical_url');

        if ($canonical !== null) {
            // Hashnode's own name for the canonical link, and a real one: it
            // writes `rel=canonical` from this, which is what makes
            // cross-posting an article here safe for search.
            $input['originalArticleURL'] = $canonical;
        }

        $data = $this->ask($account, self::PUBLISH_MUTATION, ['input' => $input], 'publish the post');

        $post = $data['publishPost']['post'] ?? null;

        if (! is_array($post)) {
            throw PublishFailed::malformed($this->network(), 'the mutation succeeded and returned no post');
        }

        $id = $post['id'] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            throw PublishFailed::malformed($this->network(), 'Hashnode accepted the post and returned no post id');
        }

        $url = $post['url'] ?? null;

        return new PublishedPost((string) $id, is_string($url) && $url !== '' ? $url : null);
    }

    /**
     * Who Hashnode thinks this token is, without publishing anything.
     *
     * A query rather than a mutation, which on GraphQL is the whole guarantee:
     * `me { username }` reads and cannot create. The connect page needs an
     * answer before an article is riding on it, and 'publish a test post' is not
     * that answer — a Hashnode publication is a public blog and the API has no
     * delete.
     *
     * It also proves more than that the token parses. A token from a second
     * account is perfectly valid and would publish to somebody else's
     * publication; echoing the username back is what lets the person see that
     * before it matters.
     */
    public function verify(SocialAccount $account): string
    {
        $data = $this->ask($account, self::ME_QUERY, [], 'read its own user');

        $username = $data['me']['username'] ?? null;

        if (! is_string($username) || trim($username) === '') {
            throw PublishFailed::malformed($this->network(), 'the token was accepted but no username came back');
        }

        return '@'.trim($username);
    }

    /* Transport --------------------------------------------------------------- */

    /**
     * A request carrying the personal access token.
     *
     * Raw, with no `Bearer ` prefix — see the class docblock, and note that
     * getting this wrong produces an HTTP 200 rather than a 401.
     */
    private function authorised(SocialAccount $account): PendingRequest
    {
        return $this->request()->withHeaders([
            'Authorization' => $this->require($account, 'api_key'),
            // Set explicitly rather than relying on the client's default body
            // format. Hashnode answers 400 to a form-encoded body, and a test
            // can assert on a header where it cannot assert on Guzzle's
            // internal option pipeline.
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * One GraphQL document, sent, with the envelope read before the status.
     *
     * 🔴 The order of the checks in here is the whole point of the method and it
     * is not arbitrary:
     *
     * 1. **`errors` first.** A refusal arrives as HTTP 200 and this is the only
     *    thing that sees it. Hashnode's own message is more useful than any
     *    sentence written here, so it is passed through.
     * 2. **Then the status.** A 5xx, a 502 from a proxy or an HTML error page
     *    has no envelope to read, so it falls through to the shared wording.
     * 3. **Then `data`.** A body with neither an error nor data is malformed,
     *    and is reported as such rather than dereferenced into a null id.
     *
     * @param  array<string, mixed>  $variables
     * @return array<array-key, mixed> the `data` object
     *
     * @throws PublishFailed
     */
    private function ask(SocialAccount $account, string $query, array $variables, string $what): array
    {
        try {
            /** @var Response $response */
            $response = $this->authorised($account)->post(self::ENDPOINT, [
                'query' => $query,
                'variables' => $variables,
            ]);
        } catch (ConnectionException $e) {
            // The central redaction rather than the raw message. This driver
            // authenticates in a header, so nothing secret is in the URL today —
            // but a connection failure's message is written to
            // `post_targets.error` and rendered on a page, and the next endpoint
            // added here should not have to remember that. See
            // `HttpPublisher::cannotReach()` for why it is not a `str_replace`.
            throw $this->cannotReach(self::ENDPOINT, $e);
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        $error = $this->firstError($body);

        if ($error !== null) {
            throw PublishFailed::rejected($this->network(), 'it would not '.$what.' — '.$error);
        }

        if ($response->failed()) {
            throw $this->explain($response, $what);
        }

        $data = $body['data'] ?? null;

        if (! is_array($data)) {
            throw PublishFailed::malformed(
                $this->network(),
                'the response carried neither an error nor any data, which a GraphQL answer cannot do',
            );
        }

        return $data;
    }

    /**
     * The first usable sentence out of a GraphQL error envelope.
     *
     * The array is walked rather than indexed at zero: an envelope whose first
     * entry has no `message` is malformed, and answering "an error with no
     * message" while the second entry says "Invalid publication ID" would be
     * this driver hiding the only useful thing in the response.
     *
     * `extensions.code` is appended when it is there. It is short, stable, and
     * it is what a search finds — `UNAUTHENTICATED` beside a sentence about a
     * publication is what tells somebody the token is the problem rather than
     * the id.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function firstError(array $body): ?string
    {
        $errors = $body['errors'] ?? null;

        if (! is_array($errors) || $errors === []) {
            return null;
        }

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $message = $error['message'] ?? null;

            if (! is_string($message) || trim($message) === '') {
                continue;
            }

            $code = $error['extensions']['code'] ?? null;

            return is_string($code) && trim($code) !== ''
                ? trim($message).' ('.trim($code).')'
                : trim($message);
        }

        return 'the API returned an error it did not describe';
    }

    /**
     * The refusals with no envelope behind them.
     *
     * There are fewer of these than on a REST API precisely because GraphQL
     * puts almost everything in a 200, so anything reaching here is the
     * transport rather than the API: a proxy, a gateway, or Hashnode being down.
     */
    private function explain(Response $response, string $what): PublishFailed
    {
        $detail = $this->detailFrom($response);
        $said = $detail === '' ? '' : ' Hashnode said: '.$detail;

        return match (true) {
            $response->status() === 401 || $response->status() === 403 => new PublishFailed(
                'Hashnode refused the personal access token, so nothing was published. Check it has not been revoked '
                .'at hashnode.com/settings/developer, and that it was pasted on its own — Hashnode takes the token '
                .'raw in the Authorization header rather than after the word Bearer.'.$said,
            ),
            $response->status() >= 500 => new PublishFailed(
                'Hashnode answered HTTP '.$response->status().' while Kargah was trying to '.$what.', which is a '
                .'fault on their side rather than anything about this article. Press retry in a few minutes.'.$said,
            ),
            default => PublishFailed::status($this->network(), $response->status(), $detail),
        };
    }

    /* The article -------------------------------------------------------------- */

    /**
     * Refuse an article this driver cannot honour, before anything is sent.
     *
     * See the class docblock for the argument. The message names `createDraft`
     * on purpose: the reader should be able to tell that Hashnode can do this
     * and Kargah cannot, rather than concluding their token is wrong.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws PublishFailed
     */
    private function refuseUnpublishable(array $options): void
    {
        $status = $this->stringOption($options, 'status');

        if ($status === null || $status === self::PUBLISHED_STATUS) {
            return;
        }

        throw PublishFailed::rejected(
            $this->network(),
            'this article is marked “'.$status.'” and Kargah can only publish to Hashnode outright — its drafts go '
            .'through a different mutation (createDraft) that is not implemented here, and publishing a draft to a '
            .'public blog anyway is not something that can be undone. Set it to publish, or take Hashnode off this '
            .'article',
        );
    }

    /**
     * The title, typed or derived.
     *
     * `WordPressPublisher::titleFor()` makes the argument in full and this is
     * the same behaviour: a missing title is taken from the body's first line,
     * and **the first line is not then stripped**. The post reads its first line
     * twice, which is visible and fixable in thirty seconds, whereas silently
     * deleting a sentence from somebody's copy is data loss they cannot see from
     * Kargah at all. Never edit the body you were handed.
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
                'a Hashnode post needs a title and this one has neither a title nor a first line to take one from',
            );
        }

        $limit = max(20, (int) config('blog.derived_title_characters', 120));

        return mb_strlen($first) <= $limit ? $first : rtrim(mb_substr($first, 0, $limit - 1)).'…';
    }

    /**
     * Tag names as Hashnode wants them: a list of `{slug, name}` objects.
     *
     * The slug is derived with `Str::slug()` — lowercase, hyphenated,
     * transliterated — and the name is kept exactly as typed. See the class
     * docblock: the derivation is the assumption most likely to be wrong here,
     * and it is written in one place so that correcting it is one line.
     *
     * Deduplicated **on the slug**, because that is what Hashnode matches on:
     * `Build log` and `build-log` are one tag there and would be two entries in
     * a list built naively. The first spelling wins, so the name a reader sees
     * is the one typed first rather than the last.
     *
     * @param  array<string, mixed>  $options
     * @return list<array{slug: string, name: string}>
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

            $name = trim((string) $tag);
            $slug = Str::slug($name);

            // 🔴 Dropped, not transliterated — the same rule `DevToPublisher`
            // applies and for the same reason, with one extra edge here: this
            // driver sends `{slug, name}` as a pair, so a transliterated slug
            // produces `{slug: 'brnamhnoysy', name: 'برنامه‌نویسی'}` — two halves
            // that describe different things, one of which Kargah made up.
            if ($slug === '' || $slug !== Str::slug($name, '-', null) || isset($tags[$slug])) {
                continue;
            }

            $tags[$slug] = ['slug' => $slug, 'name' => $name];

            if (count($tags) === self::MAX_TAGS) {
                break;
            }
        }

        return array_values($tags);
    }

    /* The cover ---------------------------------------------------------------- */

    /**
     * A URL Hashnode can fetch the cover from, or null to publish without one.
     *
     * Null three times over — no pictures, an install Hashnode cannot reach, or
     * an attachment row whose file has gone — and every one of them degrades to
     * an article with no cover rather than to a failure. See the class docblock
     * for why that is right here and wrong for Instagram.
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
     * `WordPressPublisher` names it that way: reordering the images in the
     * composer is not a change of mind about the cover. An id matching nothing
     * falls back to the first.
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

    /* Options ------------------------------------------------------------------- */

    /**
     * One option as a non-empty string, or null.
     *
     * Empty strings are null on purpose: a composer that renders a blank text
     * field writes `''`, and an empty `originalArticleURL` is not the same as
     * not sending one.
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
     * Checked rather than passed through: `originalArticleURL` is typed by a
     * person into a field shared with three destinations, and a GraphQL
     * validation error over it would fail the whole article.
     *
     * @param  array<string, mixed>  $options
     */
    private function urlOption(array $options, string $key): ?string
    {
        $value = $this->stringOption($options, $key);

        return $value !== null && preg_match('#^https?://#i', $value) === 1 ? $value : null;
    }
}
