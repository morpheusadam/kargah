<?php

namespace Modules\Social\Services\Publishers;

use Illuminate\Http\Client\PendingRequest;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * Reddit, submitting a text post to one subreddit through a script app.
 *
 * ## The password, which is not an oversight
 *
 * This driver signs in with the account's real password. Reddit's only OAuth
 * flow that needs no registered callback URL is a script app's password grant,
 * and Kargah has nowhere to receive a callback on a shared host that may not
 * even have a public address. `Networks::REDDIT`'s docblock argues the whole
 * decision and the connect page says so in as many words: use a dedicated
 * posting account. That is a worse bargain than every other credential in the
 * catalogue offers, and the person deserves to be told rather than protected
 * from the choice.
 *
 * 🔴 **The consequence for this file is that the password must never leave it.**
 * It is not put in an exception message, not logged, and not handed to a
 * Livewire property — and because a refusal's detail is whatever Reddit's own
 * body said, which this driver does not control, every failure that could carry
 * it is passed through `redact()` before it becomes a `PublishFailed`. That is
 * belt and braces on purpose: the string ends up in `post_targets.error`, which
 * is rendered on a page, and a leak there is permanent.
 *
 * ## Two calls, and the first one is not stored
 *
 * `POST /api/v1/access_token` with the app's id and secret as HTTP basic auth
 * and the account's credentials as a password grant, then `POST /api/submit`
 * with the bearer token that comes back. **A fresh token on every publish, never
 * saved.** Reddit's tokens last an hour, and an hour-long token in a row that a
 * one-minute cron might next read a week on Thursday is worth nothing: it would
 * be expired far more often than not, so the driver would need the refresh path
 * anyway *and* a column to store it in. Fetching one costs a single request on
 * the publish that needs it. What is stored is the password, and a password has
 * no expiry to manage.
 *
 * ## The User-Agent, which is the single most common cause of a mystery 429
 *
 * 🔴 **Reddit rate-limits or blocks a generic User-Agent**, and the default one
 * Guzzle sends is about as generic as they come — shared with every other PHP
 * script on the internet, including whatever was abusing the API this morning.
 * A descriptive agent naming the application is what its API rules ask for, and
 * it goes on **both** calls: the token endpoint is policed the same way, and an
 * integration that authenticates anonymously and then identifies itself is a
 * confusing thing to debug because the first request is the one that fails.
 *
 * ## The envelope
 *
 * 🔴 **Reddit answers HTTP 200 with the errors nested at `json.errors`**, an
 * array of `[code, message, field]` triples — `["SUBREDDIT_NOTALLOWED", "you
 * aren't allowed to post there", "sr"]`. An empty array is the success, and the
 * post is then at `json.data.url` and `json.data.id`. A driver that trusted the
 * status code would mark the target delivered for every one of these.
 * `TelegramPublisher`, `VkPublisher` and the Meta family all have the same
 * problem in their own shapes.
 *
 * `RATELIMIT` and `SUBREDDIT_NOTALLOWED` get their own sentence because they are
 * the two a person can act on: the first means wait, and the second means the
 * account cannot post there at all — a private subreddit, a karma threshold, or
 * a name typed wrong.
 *
 * ## The title
 *
 * The social composer has no title field and Reddit requires one, so it is
 * derived from the body's **first line** — and that line is deliberately **not**
 * then removed from the body. The post reads its first line twice, which is
 * visible and fixable in thirty seconds, whereas silently deleting a sentence
 * from somebody's copy is data loss they cannot see from Kargah at all. Never
 * edit the body you were handed; `Modules\Blog\Services\WordPressPublisher`
 * makes the same argument at length and this is the same rule.
 *
 * The 300-character cap is Reddit's, not Kargah's, and it applies to the
 * **derived** title — a string Kargah invented rather than the author's words —
 * which is the only reason truncating it is acceptable here while truncating a
 * body never is.
 *
 * ## No pictures, deliberately
 *
 * The catalogue sets `max_count` to zero. An image submission is a lease upload
 * against Reddit's own media host followed by a submit that references the
 * resulting asset, with its own failure modes and its own error shapes, and half
 * of it working is worse than none of it. Text posts work today; pictures are
 * honest future work. The refusal is worded here rather than left to
 * `HttpPublisher::acceptableMedia()`, whose catalogue-driven sentence at a limit
 * of zero reads as a bug rather than as a decision — the catalogue check still
 * runs immediately after, as the backstop it is meant to be.
 */
class RedditPublisher extends HttpPublisher
{
    /** Not `oauth.reddit.com` — the token is issued by the ordinary site. */
    private const TOKEN_ENDPOINT = 'https://www.reddit.com/api/v1/access_token';

    private const SUBMIT_ENDPOINT = 'https://oauth.reddit.com/api/submit';

    /** The cheapest call that proves a token belongs to an account. */
    private const IDENTITY_ENDPOINT = 'https://oauth.reddit.com/api/v1/me';

    /**
     * Descriptive, and naming the thing making the request. See the class
     * docblock — this is not decoration, it is what keeps the account off the
     * generic-agent throttle.
     */
    private const USER_AGENT = 'Kargah/1.0 (self-hosted freelance workspace)';

    /** Reddit's number for a submission title, not Kargah's. */
    private const TITLE_LIMIT = 300;

    public function network(): string
    {
        return Networks::REDDIT;
    }

    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        // Before anything moves and before a token is even fetched: a picture
        // attached to a Reddit target is a decision somebody made in the
        // composer, and it deserves a sentence rather than an arithmetic
        // complaint about a maximum of zero.
        $this->refusePictures($media);
        $this->acceptableMedia($media);

        $body = $this->bodyWithin($body, $media);
        $title = $this->derivedTitle($body);
        $subreddit = $this->subreddit($account);

        $response = $this->send(
            $this->authorised($this->accessToken($account)),
            'post',
            self::SUBMIT_ENDPOINT,
            [
                'sr' => $subreddit,
                // A self post — text living on Reddit — as opposed to `link`.
                'kind' => 'self',
                'title' => $title,
                'text' => $body,
                // Without this the endpoint answers with a block of HTML for a
                // browser to render, and the errors are unreachable.
                'api_type' => 'json',
            ],
        );

        $this->refuseRedditErrors($response);

        $data = $response['json']['data'] ?? null;
        $data = is_array($data) ? $data : [];

        // `id` is the bare thing (`1a2b3c`) and `name` is the fullname
        // (`t3_1a2b3c`). Either identifies the post; the bare one is preferred
        // because it is what the permalink contains.
        $id = $data['id'] ?? $data['name'] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            throw PublishFailed::malformed($this->network(), 'the submission carried no post id');
        }

        $url = $data['url'] ?? null;

        return new PublishedPost((string) $id, is_string($url) && $url !== '' ? $url : null);
    }

    /**
     * A token, then `/api/v1/me`, which writes nothing.
     *
     * Fetching a token is itself a POST, so this is not a read-only method in
     * the HTTP sense — but it publishes nothing and creates nothing on Reddit,
     * which is the promise `Publisher::verify()` actually makes.
     */
    public function verify(SocialAccount $account): string
    {
        $response = $this->send(
            $this->authorised($this->accessToken($account)),
            'get',
            self::IDENTITY_ENDPOINT,
        );

        $name = $response['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw PublishFailed::malformed($this->network(), 'the token was accepted but no account came back');
        }

        return 'u/'.$name;
    }

    /**
     * A fresh access token, and the one place the password is used.
     *
     * Everything that can throw between here and the return is wrapped, because
     * the detail in a `PublishFailed` is Reddit's own words and Kargah cannot
     * promise what a refusal body contains. See `redact()`.
     *
     * @throws PublishFailed
     */
    private function accessToken(SocialAccount $account): string
    {
        $password = $this->require($account, 'password');

        try {
            $response = $this->send(
                $this->request()
                    ->asForm()
                    ->withBasicAuth(
                        $this->require($account, 'client_id'),
                        $this->require($account, 'client_secret'),
                    )
                    ->withHeaders(['User-Agent' => self::USER_AGENT]),
                'post',
                self::TOKEN_ENDPOINT,
                [
                    'grant_type' => 'password',
                    'username' => $this->require($account, 'username'),
                    'password' => $password,
                ],
            );

            $token = $response['access_token'] ?? null;

            if (! is_string($token) || $token === '') {
                $said = is_string($response['error'] ?? null) ? $response['error'] : null;

                throw PublishFailed::rejected(
                    $this->network(),
                    'the sign-in was refused'.($said === null ? '' : ' — Reddit said “'.$said.'”')
                    .'. Check the app ID and secret against reddit.com/prefs/apps, that the account is '
                    .'listed as a developer of that app, and that it does not have two-factor '
                    .'authentication switched on — the password grant has nowhere to put a code',
                );
            }

            return $token;
        } catch (PublishFailed $e) {
            throw $this->redact($e, $password);
        }
    }

    /** The submit and identity calls, which differ only in method and URL. */
    private function authorised(string $token): PendingRequest
    {
        return $this->request()
            ->asForm()
            // Lower case, which is what Reddit's own documentation sends. The
            // scheme is case-insensitive per RFC 6750, so this is fidelity to
            // the reproduction somebody will make with curl rather than a
            // requirement.
            ->withToken($token, 'bearer')
            ->withHeaders(['User-Agent' => self::USER_AGENT]);
    }

    /**
     * Turn a 200 whose `json.errors` is not empty into the failure it is.
     *
     * @param  array<array-key, mixed>  $response
     *
     * @throws PublishFailed
     */
    private function refuseRedditErrors(array $response): void
    {
        $errors = $response['json']['errors'] ?? null;

        if (! is_array($errors) || $errors === []) {
            return;
        }

        $first = $errors[0] ?? null;
        $first = is_array($first) ? array_values($first) : [];

        $code = is_scalar($first[0] ?? null) ? (string) $first[0] : '';
        $said = is_scalar($first[1] ?? null) ? (string) $first[1] : '';

        // Reddit's own sentence is carried through in every case: the person
        // reading the red row on the posts page is better served by what the
        // network said than by Kargah's paraphrase of it.
        $detail = $said === '' ? 'it gave no reason' : 'Reddit said “'.$said.'”';

        throw PublishFailed::rejected($this->network(), match ($code) {
            'RATELIMIT' => $detail.'. Nothing is wrong with the post — Reddit throttles new and '
                .'low-karma accounts hard, so wait as long as it asked and press retry',
            'SUBREDDIT_NOTALLOWED' => $detail.'. That subreddit will not take a post from this '
                .'account: it may be private, it may require karma or an account age this one does '
                .'not have, or the name may be typed wrong',
            'SUBREDDIT_NOEXIST' => $detail.'. Check the subreddit name on the connection — it is '
                .'stored without the r/ prefix',
            'NO_SELFS' => $detail.'. That subreddit only takes link posts, and this connection '
                .'submits text posts only',
            'TOO_LONG' => $detail.'. The title is derived from the first line of the copy, so '
                .'shortening that line shortens the title',
            default => $detail.($code === '' ? '' : ' ('.$code.')'),
        });
    }

    /**
     * The title Reddit requires and the composer has no field for.
     *
     * The first line that has anything on it, verbatim, truncated to Reddit's
     * 300 if it runs past it — and left in the body regardless. See the class
     * docblock for why removing it would be the worse bug.
     *
     * @throws PublishFailed
     */
    private function derivedTitle(string $body): string
    {
        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            return mb_strlen($line) > self::TITLE_LIMIT
                // The ellipsis is inside the limit rather than beyond it, so the
                // string handed to Reddit is exactly 300 characters.
                ? mb_substr($line, 0, self::TITLE_LIMIT - 1).'…'
                : $line;
        }

        throw PublishFailed::rejected(
            $this->network(),
            'Reddit needs a title and Kargah takes it from the first line of the copy, which is empty',
        );
    }

    /**
     * The subreddit, however the person typed it.
     *
     * `r/kargah`, `/r/kargah` and `kargah` all reach the same place; sent
     * verbatim, the first two produce a `SUBREDDIT_NOEXIST` for a subreddit
     * literally called `r/kargah`.
     *
     * @throws PublishFailed
     */
    private function subreddit(SocialAccount $account): string
    {
        $name = trim($this->require($account, 'subreddit'));
        $name = ltrim($name, '/');

        if (preg_match('/^r\//i', $name) === 1) {
            $name = substr($name, 2);
        }

        if ($name === '') {
            throw PublishFailed::rejected($this->network(), 'the connection names no subreddit to post to');
        }

        return $name;
    }

    /**
     * Every picture is refused, and the message says why rather than counting.
     *
     * @param  list<MediaItem>  $media
     *
     * @throws PublishFailed
     */
    private function refusePictures(array $media): void
    {
        if ($media === []) {
            return;
        }

        throw PublishFailed::rejected(
            $this->network(),
            'this connection submits text posts only, so “'.$media[0]->name.'” cannot go to Reddit — '
            .'an image submission needs a lease upload against Reddit’s own media host and Kargah does '
            .'not do that yet. The copy will go out if you remove the '
            .(count($media) === 1 ? 'picture' : 'pictures').' from this destination',
        );
    }

    /**
     * The same failure with the password taken out of it.
     *
     * Reddit's refusal bodies are Reddit's, and `HttpPublisher::detailFrom()`
     * copies up to 300 characters of one into the message that ends up in
     * `post_targets.error` and on a page. Nothing observed does echo a submitted
     * password back — but the cost of being wrong once is a permanent plaintext
     * credential in the database, and the cost of this method is a `str_replace`
     * on a path that only runs when something already failed.
     */
    private function redact(PublishFailed $failure, string $secret): PublishFailed
    {
        if (trim($secret) === '') {
            return $failure;
        }

        $message = str_replace($secret, '[password removed]', $failure->getMessage());

        return $message === $failure->getMessage() ? $failure : new PublishFailed($message);
    }
}
