<?php

namespace Modules\Social\Services\Publishers;

use Illuminate\Http\Client\PendingRequest;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * Lemmy, posting into one community on whichever instance the account lives on.
 *
 * There is no central host, so the instance is a credential rather than a
 * constant — the same shape `MastodonPublisher` has, and for the same reason.
 *
 * ## The password, which is not an oversight
 *
 * Lemmy's API has exactly one way in and it is `user/login` with a username and
 * a password. There is no application password, no scoped token, no OAuth: the
 * JWT this driver receives can do everything the account can do, which is why
 * the catalogue asks for a dedicated posting account in as many words and why
 * `Networks::REDDIT`'s docblock argues the decision for both networks at once.
 * Two-factor has to be off on that account, because the login endpoint has
 * nowhere to put a code.
 *
 * 🔴 **The consequence for this file is that the password must never leave it.**
 * It is not logged, not put in a Livewire property, and not allowed into a
 * `PublishFailed` — and because a refusal's detail is whatever the instance's
 * own body said, which this driver does not control, the login failure path runs
 * through `redact()`. The message ends up in `post_targets.error`, which is
 * rendered on a page; a leak there is permanent.
 *
 * ## Three calls, then a fourth if there is a picture
 *
 * `POST /api/v3/user/login` for a JWT, `GET /api/v3/community?name=…` to turn
 * the community's name into the numeric id the post endpoint insists on, and
 * `POST /api/v3/post` to make the post. The community lookup is not cached
 * anywhere: two accounts are two instances, and `buildinpublic` is a different
 * id on each of them — a cache keyed on the name alone would eventually post
 * into whichever community happened to hold that id on the wrong server.
 *
 * The response's `ap_id` is the post's canonical ActivityPub URL and is what
 * federates, so it is preferred over anything Kargah could assemble from the
 * instance and the id — a post made on one instance and read on another is the
 * normal case here, and only `ap_id` is right in both places.
 *
 * ## ⚠️ Which Lemmy this targets, and what the other one looks like
 *
 * **The bearer header, which is 0.19 and later.** Lemmy moved authentication out
 * of the request body and into `Authorization: Bearer` in 0.19; before that,
 * every authenticated call carried the JWT as an `auth` field inside the JSON
 * body, and a number of instances still run 0.18. The two shapes are not
 * compatible in either direction and the symptom of aiming the wrong one at an
 * instance is deliberately unhelpful: an older instance answers a bearer-only
 * request with a plain `400` carrying `{"error":"not_logged_in"}`, which reads
 * as a wrong password rather than as a wrong protocol. If a connection fails
 * that way against an instance whose credentials are known good, check the
 * instance's version before anything else.
 *
 * This driver picks the modern shape because it is the one Lemmy documents now
 * and the one every maintained instance serves, and because supporting both
 * would mean a version probe on every publish.
 *
 * ## The title
 *
 * The social composer has no title field and a Lemmy post requires a `name`, so
 * it is derived from the body's **first line** — and that line is deliberately
 * **not** then removed from the body. The post reads its first line twice, which
 * is visible and fixable in thirty seconds, whereas silently deleting a sentence
 * from somebody's copy is data loss they cannot see from Kargah at all. Never
 * edit the body you were handed;
 * `Modules\Blog\Services\WordPressPublisher` makes the same argument at length.
 *
 * The 200-character cap is Lemmy's, and it applies to the **derived** title — a
 * string Kargah invented rather than the author's words — which is the only
 * reason truncating it is acceptable here while truncating a body never is.
 *
 * ## One picture, not a gallery
 *
 * A Lemmy post carries a single `url`, and that is the whole of its media model:
 * there is no attachment list and no album. So one image is uploaded to the
 * instance's own pict-rs host at `/pictrs/image` and the resulting
 * `/pictrs/image/<file>` becomes the post's `url`, which is what makes the
 * thumbnail. Two images would need two posts, and the catalogue caps the count
 * at one so the composer says so while somebody is attaching rather than at
 * send time.
 */
class LemmyPublisher extends HttpPublisher
{
    /** Lemmy's number for a post title, not Kargah's. */
    private const TITLE_LIMIT = 200;

    public function network(): string
    {
        return Networks::LEMMY;
    }

    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        $media = $this->acceptableMedia($media);
        $body = $this->bodyWithin($body, $media);
        $title = $this->derivedTitle($body);

        $jwt = $this->login($account);

        $payload = [
            'name' => $title,
            'body' => $body,
            'community_id' => $this->communityId($account, $jwt),
        ];

        if ($media !== []) {
            $payload['url'] = $this->uploadPicture($account, $jwt, $media[0]);
        }

        $response = $this->send(
            $this->authorised($jwt),
            'post',
            $this->endpoint($account, '/api/v3/post'),
            $payload,
        );

        $post = $response['post_view']['post'] ?? null;
        $post = is_array($post) ? $post : [];

        $id = $post['id'] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            throw PublishFailed::malformed($this->network(), 'the response carried no post id');
        }

        $apId = $post['ap_id'] ?? null;

        return new PublishedPost(
            (string) $id,
            is_string($apId) && $apId !== ''
                ? $apId
                // Only as a fallback. `ap_id` is the federated identity of the
                // post and the link that works from any instance; this one is
                // merely the link that works from this one.
                : $this->endpoint($account, '/post/'.$id),
        );
    }

    /**
     * Sign in, and say which account the instance thinks that was.
     *
     * Signing in is a POST, so this is not read-only in the HTTP sense — but it
     * creates nothing and posts nothing, which is the promise
     * `Publisher::verify()` actually makes. There is no cheaper way in: Lemmy
     * has no endpoint that will say anything about a session without one.
     */
    public function verify(SocialAccount $account): string
    {
        $jwt = $this->login($account);

        $response = $this->send(
            $this->authorised($jwt),
            'get',
            $this->endpoint($account, '/api/v3/site'),
        );

        // `my_user` is only present when the instance actually read the
        // Authorization header, which makes its absence the clearest signal
        // available that this instance wants the older `auth`-in-the-body shape.
        // See the version note on the class.
        $name = $response['my_user']['local_user_view']['person']['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw PublishFailed::malformed(
                $this->network(),
                'the sign-in was accepted but the instance did not say which account it belongs to, '
                .'which is what an instance older than 0.19 does with a bearer token',
            );
        }

        return '@'.$name.'@'.$this->host($account);
    }

    /**
     * A session token, and the one place the password is used.
     *
     * Wrapped, because the detail in a `PublishFailed` is the instance's own
     * words and Kargah cannot promise what a refusal body contains. See
     * `redact()`.
     *
     * @throws PublishFailed
     */
    private function login(SocialAccount $account): string
    {
        $password = $this->require($account, 'password');

        try {
            $response = $this->send(
                $this->request(),
                'post',
                $this->endpoint($account, '/api/v3/user/login'),
                [
                    // One field for both: Lemmy takes the account name or the
                    // email address here and does not care which.
                    'username_or_email' => $this->require($account, 'username'),
                    'password' => $password,
                ],
            );

            $jwt = $response['jwt'] ?? null;

            if (! is_string($jwt) || $jwt === '') {
                throw PublishFailed::rejected(
                    $this->network(),
                    'the instance answered the sign-in but issued no session token. Check the username '
                    .'and password, and that the account does not have two-factor authentication '
                    .'switched on — the login endpoint has nowhere to put a code',
                );
            }

            return $jwt;
        } catch (PublishFailed $e) {
            throw $this->redact($e, $password);
        }
    }

    /**
     * The community's numeric id, which is the only thing the post endpoint takes.
     *
     * Not cached between publishes. See the class docblock: the same name is a
     * different id on every instance.
     *
     * @throws PublishFailed
     */
    private function communityId(SocialAccount $account, string $jwt): int
    {
        $name = $this->community($account);

        $response = $this->send(
            $this->authorised($jwt),
            'get',
            $this->endpoint($account, '/api/v3/community'),
            ['name' => $name],
        );

        $id = $response['community_view']['community']['id'] ?? null;

        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            throw PublishFailed::rejected(
                $this->network(),
                'the instance has no community called “'.$name.'”. A community on another server is '
                .'named name@instance, and this instance has to have federated with it already',
            );
        }

        return (int) $id;
    }

    /**
     * One picture, uploaded to the instance's own image host.
     *
     * The JWT goes up as a bearer header **and** as a cookie. pict-rs sits
     * behind Lemmy's own proxy rather than behind its API, and which of the two
     * it reads has moved between releases; sending both costs a header and
     * removes a failure that presents as an anonymous upload being rejected.
     *
     * @throws PublishFailed
     */
    private function uploadPicture(SocialAccount $account, string $jwt, MediaItem $item): string
    {
        $response = $this->sendMultipart(
            $this->uploadRequest()
                ->withToken($jwt)
                ->withHeaders(['Cookie' => 'jwt='.$jwt]),
            $this->endpoint($account, '/pictrs/image'),
            // The part name is `images[]` — plural and bracketed even for a
            // single file, because pict-rs takes a list here and a part named
            // `image` is silently ignored, producing an upload with no files in
            // the response.
            ['images[]' => [$item->filename(), $item->contents(), $item->mime]],
        );

        $file = $response['files'][0]['file'] ?? null;

        if (! is_string($file) || $file === '') {
            throw PublishFailed::malformed(
                $this->network(),
                'the upload of “'.$item->name.'” returned no file, so the post was not created',
            );
        }

        // The delete token in the same response is deliberately dropped. Kargah
        // does not delete what it publishes — nothing in this module does — and
        // storing a token whose only use is destruction, against a post nobody
        // asked to remove, is a liability rather than a feature.
        return $this->endpoint($account, '/pictrs/image/'.$file);
    }

    /** Every authenticated call, which differ only in method, path and payload. */
    private function authorised(string $jwt): PendingRequest
    {
        return $this->request()->withToken($jwt);
    }

    /**
     * The title a Lemmy post requires and the composer has no field for.
     *
     * The first line that has anything on it, verbatim, truncated to Lemmy's 200
     * if it runs past it — and left in the body regardless. See the class
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
                // string handed to Lemmy is exactly 200 characters.
                ? mb_substr($line, 0, self::TITLE_LIMIT - 1).'…'
                : $line;
        }

        throw PublishFailed::rejected(
            $this->network(),
            'a Lemmy post needs a title and Kargah takes it from the first line of the copy, which is empty',
        );
    }

    /**
     * The community name, however the person typed it.
     *
     * `!buildinpublic` is how a community is written in a Lemmy comment and
     * `buildinpublic` is what the API wants; `buildinpublic@lemmy.world` is the
     * form for one on another server and passes through untouched.
     *
     * @throws PublishFailed
     */
    private function community(SocialAccount $account): string
    {
        $name = ltrim(trim($this->require($account, 'community')), '!/');

        if ($name === '') {
            throw PublishFailed::rejected($this->network(), 'the connection names no community to post to');
        }

        return $name;
    }

    /**
     * The instance base, with a trailing slash and any stray path removed.
     *
     * People paste `https://lemmy.world/` and `lemmy.world/c/somewhere` alike,
     * and a URL built by concatenation onto either produces a 404 that reads
     * like the instance rejected the post. Identical in shape to
     * `MastodonPublisher::endpoint()`, deliberately: two drivers that both take
     * an instance URL from a person should mangle it the same way.
     *
     * @throws PublishFailed
     */
    private function endpoint(SocialAccount $account, string $path): string
    {
        return 'https://'.$this->host($account).$path;
    }

    /**
     * Just the host out of whatever was pasted into the instance field.
     *
     * Forced to https, which is the same call `MastodonPublisher` makes: a
     * federated instance reachable over plain http is not a thing that exists on
     * the public internet, and sending a password over one would be worse than
     * failing to reach it.
     *
     * @throws PublishFailed
     */
    private function host(SocialAccount $account): string
    {
        $instance = rtrim(trim($this->require($account, 'instance')), '/');

        if (! str_starts_with($instance, 'http://') && ! str_starts_with($instance, 'https://')) {
            $instance = 'https://'.$instance;
        }

        $host = parse_url($instance, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw PublishFailed::rejected($this->network(), 'the instance URL is not a host Kargah can reach');
        }

        return $host;
    }

    /**
     * The same failure with the password taken out of it.
     *
     * The instance's refusal bodies are the instance's, and
     * `HttpPublisher::detailFrom()` copies up to 300 characters of one into the
     * message that ends up in `post_targets.error` and on a page. A Lemmy
     * instance is somebody else's server running somebody else's build, which is
     * exactly the situation in which not trusting a response body is cheap
     * insurance: the cost of being wrong once is a permanent plaintext
     * credential in the database.
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
