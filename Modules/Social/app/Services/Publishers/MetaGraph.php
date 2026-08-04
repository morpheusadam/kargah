<?php

namespace Modules\Social\Services\Publishers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Modules\Data\Contracts\AttachmentService;
use Modules\Social\Support\Networks;

/**
 * The part of Meta's Graph API that Facebook Pages, Instagram and Threads
 * genuinely have in common — and nothing beyond it.
 *
 * Three networks in one API family still disagree about almost everything. They
 * do not share a host (Threads is on `graph.threads.net`), they do not share a
 * token (a Page token is refused by Threads), they do not share a publishing
 * model (a Page post is one call and an Instagram post is three), and only one
 * of the three can carry image bytes at all. What they *do* share is smaller and
 * duller than the family name suggests, which is exactly why it is a trait
 * holding four or five short methods rather than an abstract `MetaPublisher`
 * with the differences pushed into `if`s. `Publisher::publish()`'s docblock
 * makes the same argument about media uploads across the other five drivers, and
 * it is the same argument here: an abstraction that pretended these were one
 * network would have three special cases inside it.
 *
 * What is actually shared:
 *
 * - **the version segment**, because a Graph URL cannot be built without one;
 * - **the error envelope**, because all three answer with `error.message`,
 *   `error.code` and sometimes `error.error_user_msg`, and — the part that costs
 *   a debugging cycle if you miss it — all three will happily answer **HTTP 200
 *   with an `error` key**, so a successful status code is not evidence that
 *   anything was published. `TelegramPublisher` has the same problem with
 *   `ok: false` and reads its body for the same reason;
 * - **the container-then-publish dance**, which Instagram and Threads both do and
 *   Facebook does not;
 * - **the signed public URL**, which Instagram and Threads both need because
 *   neither will accept image bytes.
 *
 * **`HttpPublisher::detailFrom()` is deliberately not used by anything here.**
 * It looks for a string at `error`, and Graph's `error` is an object; it would
 * fall through to the raw JSON body and put a serialised envelope in the error
 * cell instead of Meta's sentence. Every Graph call in these three drivers reads
 * the envelope itself, which is also what makes the 200-with-an-error case work.
 *
 * **Requests are form-encoded.** `HttpPublisher::request()` sends JSON by
 * default and Graph does accept a JSON body, but every example Meta publishes,
 * every curl line in its documentation and every one of its own SDKs sends
 * `application/x-www-form-urlencoded`. When a Graph call is refused, the first
 * thing anyone does is reproduce it with curl, and a driver that sent a
 * different content type than the reproduction would make that comparison lie.
 *
 * **Booleans travel as the literal strings `true` and `false`.** A PHP `false`
 * encodes to `0` in a form body and to an empty string in a multipart part, and
 * Graph reads an empty part as absent — which would silently publish a photo
 * that was meant to stay unpublished. One rule, no per-transport thinking.
 *
 * **Messages name the network by its catalogue label rather than its key.**
 * `PublishFailed` runs `ucfirst()` over whatever it is handed, which is right for
 * `mastodon` and produces “Facebook_page refused the post” for this family. The
 * label is “Facebook Page”, so that is what these drivers pass. It is the one
 * place they deviate from the other five, and it is worth it: the string ends up
 * in `post_targets.error`, on the page, in front of a person.
 */
trait MetaGraph
{
    use FetchesOwnMedia {
        unreachableInstallReason as private fetchGuardReason;
    }

    /**
     * The Graph version every URL built here carries.
     *
     * **Pinned, not floating, and that is the whole point.** Meta runs a
     * two-year deprecation clock on each version: an unversioned or
     * always-latest URL means a Kargah install that has worked for a year
     * starts behaving differently on the morning Meta promotes a new default —
     * new required parameters, changed error codes, edges that have moved. A
     * pinned version fails on *Kargah's* schedule instead: the version stops
     * working, every Meta target says so on the same day, and somebody changes
     * one line here having read the changelog. A silent behaviour change on
     * Meta's schedule is the one that costs a weekend.
     *
     * The number is Meta's, not Kargah's. When it is retired the symptom is
     * every Graph call answering with an error about an unsupported version;
     * the fix is this constant and nothing else, which is why it is a constant
     * and not nine string literals.
     *
     * **Measured against the live host on 5 August 2026**, because it had been
     * written down as a choice nobody had checked. Graph answers an unknown
     * version by reading it as a node name — `/v99.0/me` and `/vXYZ/me` both
     * come back *"Unknown path components: /me"*, while a real version comes
     * back *"An active access token must be used…"*. By that test `v23.0` is
     * live, and `v26.0` is the newest Graph recognises; `v27.0` is not a
     * version yet. So the pin is three behind and inside Meta's two-year
     * window — deliberately left where it is, because moving it changes
     * required parameters and error codes on nine calls that have never once
     * been made against a real account, and a bump nobody can test is a change
     * that only shows up as a failure on the owner's first real post.
     */
    protected const GRAPH_VERSION = 'v23.0';

    /** Facebook and Instagram. Threads is somewhere else entirely — see `ThreadsPublisher`. */
    private const GRAPH_HOST = 'https://graph.facebook.com';

    /**
     * The token is expired or invalid.
     *
     * By a distance the most likely failure on this family, because a Page token
     * copied straight out of Graph API Explorer lives for about an hour and
     * looks identical to one that lives for ever. It gets its own sentence.
     */
    private const TOKEN_INVALID = 190;

    /**
     * Throttling, in every shape Graph reports it.
     *
     * 4 is the application-level rate limit, 17 the user-level one, 32 the Page
     * one, 613 a per-edge custom limit, and the 80000 range is the newer
     * publishing-specific set. Worth naming as one thing because the reaction is
     * the same for all of them and it is not the same as “your post was wrong”.
     *
     * @var list<int>
     */
    private const RATE_LIMITED = [4, 17, 32, 613];

    /**
     * The container exists but Meta has not finished fetching or processing the
     * image behind it.
     *
     * Transient by definition, and the only Graph error on this family worth a
     * second attempt.
     */
    private const MEDIA_NOT_READY = 9007;

    /**
     * How long to wait before that second attempt, once.
     *
     * **Deliberately not a poll loop.** A publish runs inside one PHP request's
     * `max_execution_time` on shared hosting, and a loop that waits for a
     * container to become ready is a loop that can wait for ever — with three
     * other targets in the same job queued behind it. One wait, one retry, then
     * a clear failure the person can press retry on from the posts page, which
     * is the same budget `HttpPublisher` spends on everything else.
     */
    private const CONTAINER_RETRY_SECONDS = 2;

    /** `https://graph.facebook.com/v23.0/<path>`. */
    protected function graphUrl(string $path): string
    {
        return self::GRAPH_HOST.'/'.self::GRAPH_VERSION.'/'.ltrim($path, '/');
    }

    /**
     * The network's name as a person reads it, for every message these drivers
     * put in front of one. See the note on the class docblock.
     */
    protected function graphName(): string
    {
        return Networks::label($this->network());
    }

    /**
     * One Graph call, with the envelope read before the status code.
     *
     * `$fields` becomes a form body on a POST and a query string on a GET, which
     * is where the access token goes in both cases. A GET has nowhere else to
     * put it; a POST deliberately keeps it out of the URL so it does not end up
     * in a web server's access log.
     *
     * 🔴 **An access token in a GET's query string is not only an access-log
     * problem, and this paragraph used to say it was.** The three `verify()`
     * calls in this family — Facebook Pages, Instagram and Threads — each send
     * the token as a `$fields` entry on a GET. When one of those times out,
     * Guzzle builds its `ConnectionException` message by appending the whole
     * effective URI, token and all, and `⚡account-connect` catches
     * `PublishFailed` and renders `getMessage()` straight into the page and a
     * toast. One slow credential check would have printed a working Page or
     * Instagram token on screen. That is why `graphBody()` goes through
     * `HttpPublisher::cannotReach()` rather than through the exception message —
     * see the long note there. The publish paths were always safe, because they
     * are POSTs and the token is in the body.
     *
     * @param  'get'|'post'  $method
     * @param  array<string, string>  $fields
     * @return array<array-key, mixed>
     *
     * @throws PublishFailed
     */
    protected function graphSend(string $method, string $url, array $fields = []): array
    {
        $request = $method === 'post' ? $this->request()->asForm() : $this->request();

        return $this->refuseGraphError($this->graphBody($request, $method, $url, $fields));
    }

    /**
     * A multipart Graph call — the only one that carries bytes.
     *
     * Facebook's `/photos` edge is the single place in this family where an
     * image is uploaded rather than fetched, so this exists for one caller. It
     * is here rather than there because the response still has to go through the
     * same envelope reading, and `HttpPublisher::sendMultipart()` would hand it
     * to `detailFrom()`.
     *
     * @param  array<string, array{0: string, 1: string, 2: string}>  $files  part name => [filename, bytes, mime]
     * @param  array<string, string>  $fields
     * @return array<array-key, mixed>
     *
     * @throws PublishFailed
     */
    protected function graphUpload(string $url, array $files, array $fields = []): array
    {
        $request = $this->uploadRequest();

        foreach ($files as $part => [$filename, $bytes, $mime]) {
            $request = $request->attach($part, $bytes, $filename, ['Content-Type' => $mime]);
        }

        return $this->refuseGraphError($this->graphBody($request, 'post', $url, $fields));
    }

    /**
     * The `id` Graph answers with, or a failure that says what was expected.
     *
     * @param  array<array-key, mixed>  $body
     * @param  string  $what  what the id was meant to be, for the message
     *
     * @throws PublishFailed
     */
    protected function graphId(array $body, string $what): string
    {
        $id = $body['id'] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            throw PublishFailed::malformed($this->graphName(), 'the response carried no '.$what);
        }

        return (string) $id;
    }

    /**
     * Step one of the two-step publish Instagram and Threads share.
     *
     * A container is a draft that exists on Meta's side and has not been posted
     * anywhere. Creating one is cheap and reversible — an unpublished container
     * simply expires — which is what makes the second step the only place a post
     * can become visible.
     *
     * @param  array<string, string>  $fields
     * @return string the creation id
     *
     * @throws PublishFailed
     */
    protected function createContainer(string $url, array $fields): string
    {
        return $this->graphId($this->graphSend('post', $url, $fields), 'container id');
    }

    /**
     * Step two: turn a container into a post.
     *
     * The one place these drivers retry a *refusal* rather than a transport
     * failure, and only for `MEDIA_NOT_READY`. Meta fetches the image during the
     * container call and occasionally has not finished with it by the time the
     * publish arrives a few hundred milliseconds later; that is a race, not a
     * bad post, and failing it would send somebody to the posts page to press a
     * button that would have worked. Everything else — a bad token, an aspect
     * ratio Instagram will not take, a rate limit — is answered the first time
     * and is not improved by asking again two seconds later.
     *
     * Bounded at exactly one extra attempt. See `CONTAINER_RETRY_SECONDS`.
     *
     * @param  array<string, string>  $fields
     * @return string the published media id
     *
     * @throws PublishFailed
     */
    protected function publishContainer(string $url, array $fields): string
    {
        $body = $this->graphBody($this->request()->asForm(), 'post', $url, $fields);
        $error = $this->graphError($body);

        if ($error !== null && $error['code'] === self::MEDIA_NOT_READY) {
            sleep(self::CONTAINER_RETRY_SECONDS);

            $body = $this->graphBody($this->request()->asForm(), 'post', $url, $fields);
            $error = $this->graphError($body);

            if ($error !== null && $error['code'] === self::MEDIA_NOT_READY) {
                throw PublishFailed::rejected(
                    $this->graphName(),
                    'Meta was still processing the image '.(self::CONTAINER_RETRY_SECONDS + 1)
                    .' seconds after it accepted it, so the post was not published — press retry and it will very likely go out',
                );
            }
        }

        if ($error !== null) {
            throw $this->graphRefusal($error);
        }

        return $this->graphId($body, 'media id');
    }

    /**
     * A URL for one image that Meta's own servers can fetch.
     *
     * 🔴 **This is the requirement that decides whether Instagram and Threads
     * work on a given install at all.** Neither network accepts image bytes:
     * the container call takes an `image_url` and Meta goes and downloads it. A
     * picture sitting behind Kargah's `auth` middleware, on a machine with no
     * public address, cannot be published to either network by any means — not
     * because Kargah is missing a feature, but because the API has no other
     * shape.
     *
     * `AttachmentService::publicUrl()` answers with a signed, expiring link on
     * `data.file-share`, a route that has always sat outside `auth` behind
     * `signed` middleware. The default window is left alone: Meta fetches during
     * the call that is being made right now, so half an hour is generous by
     * orders of magnitude, and the whole value of an expiry is that a link which
     * leaks stops working.
     *
     * The host is checked before the URL is handed over, because the failure it
     * prevents is the confusing one. A `localhost` URL sent to Meta produces a
     * Graph error about a media download failing, which reads as “Instagram is
     * broken” rather than “this machine is not on the internet”.
     *
     * @throws PublishFailed
     */
    protected function fetchableUrl(MediaItem $item): string
    {
        if (($reason = $this->unreachableInstallReason()) !== null) {
            throw PublishFailed::rejected($this->graphName(), $reason);
        }

        $url = app(AttachmentService::class)->publicUrl($item->id);

        if ($url === null) {
            throw PublishFailed::rejected(
                $this->graphName(),
                'the image “'.$item->name.'” is recorded against this post but its attachment row is gone, '
                .'and Meta fetches the picture itself rather than being sent it',
            );
        }

        return $url;
    }

    /**
     * Why Meta could not fetch anything from this install, or null if it could.
     *
     * Asked against `url('/')` rather than against `config('app.url')` on
     * purpose: the signed link is built by the same URL generator, so this
     * checks the host Meta will actually be given rather than a second value
     * that is meant to agree with it.
     *
     * The judgement is deliberately crude — a loopback address, a private range,
     * a development TLD, or a bare hostname with no dot in it. It cannot prove
     * an install *is* reachable (a public DNS name behind a firewall would pass
     * and then fail at Meta), and it does not try to.
     *
     * The host test itself now lives in `FetchesOwnMedia`, because Slack, DEV.to
     * and Hashnode had each grown a verbatim copy of it — four copies of one
     * rule is four chances for it to drift, and only the name in the sentence
     * was ever Meta's.
     */
    protected function unreachableInstallReason(string $who = 'Meta'): ?string
    {
        return $this->fetchGuardReason($who);
    }

    /**
     * The request, sent, decoded, and checked for everything except a Graph
     * refusal.
     *
     * Split from `graphSend()` because `publishContainer()` needs to look at a
     * refusal and decide whether to try once more, and an exception is the wrong
     * shape to make that decision from.
     *
     * A `4xx` with an envelope is left alone and handed back, because Graph puts
     * the useful sentence in the envelope and the status code adds nothing to
     * it. A `4xx` *without* one is not Graph talking — it is a proxy, a WAF or
     * an outage page — and that gets the status treatment.
     *
     * @param  'get'|'post'  $method
     * @param  array<string, mixed>  $fields
     * @return array<array-key, mixed>
     *
     * @throws PublishFailed
     */
    private function graphBody(PendingRequest $request, string $method, string $url, array $fields): array
    {
        try {
            /** @var Response $response */
            $response = $method === 'get'
                ? $request->get($url, $fields)
                : $request->post($url, $fields);
        } catch (ConnectionException $e) {
            // Not `$e->getMessage()`: on a GET the access token is in that URI,
            // and this message is rendered on the connect page. See `graphSend()`
            // and `HttpPublisher::cannotReach()`. Passing the network **key**
            // rather than `graphName()` is deliberate — `PublishFailed` labels it
            // itself, so the old call was labelling an already-labelled name and
            // only worked because `Networks::label()` falls back to `ucfirst()`.
            throw $this->cannotReach($url, $e);
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw $response->failed()
                ? PublishFailed::status($this->graphName(), $response->status(), $this->trimmed($response->body()))
                : PublishFailed::malformed($this->graphName(), 'the body did not decode as JSON');
        }

        if ($response->failed() && $this->graphError($body) === null) {
            throw PublishFailed::status($this->graphName(), $response->status(), $this->trimmed($response->body()));
        }

        return $body;
    }

    /**
     * @param  array<array-key, mixed>  $body
     * @return array<array-key, mixed>
     *
     * @throws PublishFailed
     */
    private function refuseGraphError(array $body): array
    {
        $error = $this->graphError($body);

        if ($error !== null) {
            throw $this->graphRefusal($error);
        }

        return $body;
    }

    /**
     * Graph's error envelope, read the way Meta writes it.
     *
     * `error_user_msg` is the friendlier of the two when it is there — it is the
     * sentence Meta wrote for an end user rather than for a developer — so it
     * wins, and `message` is the fallback that is always present.
     *
     * @param  array<array-key, mixed>  $body
     * @return array{code: int|null, message: string}|null
     */
    private function graphError(array $body): ?array
    {
        $error = $body['error'] ?? null;

        if (! is_array($error)) {
            return null;
        }

        $message = $error['error_user_msg'] ?? $error['message'] ?? null;
        $code = $error['code'] ?? null;

        return [
            'code' => is_numeric($code) ? (int) $code : null,
            'message' => is_string($message) && trim($message) !== ''
                ? $this->trimmed($message)
                : 'Meta refused it without saying why',
        ];
    }

    /**
     * A refusal, with the two cases that deserve their own sentence given one.
     *
     * The token case matters more than any other error on this family put
     * together, because the mistake that causes it is invisible: a short-lived
     * token and a long-lived one are the same opaque string, and the short one
     * publishes perfectly for an hour before it stops. Told only “the session
     * has been invalidated”, a person reasonably concludes something changed at
     * Meta's end. Told to exchange it for a long-lived token, they fix it once.
     *
     * @param  array{code: int|null, message: string}  $error
     */
    private function graphRefusal(array $error): PublishFailed
    {
        if ($error['code'] === self::TOKEN_INVALID) {
            return PublishFailed::rejected(
                $this->graphName(),
                'the access token has expired or been invalidated ('.$error['message'].') — a token copied straight '
                .'out of Graph API Explorer lasts about an hour, so exchange it for a long-lived one and paste the '
                .'replacement on this account’s connect page',
            );
        }

        if ($error['code'] !== null
            && (in_array($error['code'], self::RATE_LIMITED, true)
                || ($error['code'] >= 80000 && $error['code'] < 90000))) {
            return PublishFailed::rejected(
                $this->graphName(),
                'Meta is rate limiting this app ('.$error['message'].'), so nothing was published — '
                .'the post can be retried once the limit resets, usually within the hour',
            );
        }

        return PublishFailed::rejected($this->graphName(), $error['message']);
    }

    /** Whatever Meta said, cut to something that fits an error cell. */
    private function trimmed(string $detail): string
    {
        return mb_substr(trim($detail), 0, 300);
    }
}
