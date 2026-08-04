<?php

namespace Modules\Social\Services\Publishers;

use Illuminate\Http\Client\Response;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;
use Modules\Social\Support\OAuth1;

/**
 * Tumblr, through an application you registered and a token for your own
 * account, signed with OAuth 1.0a.
 *
 * **The same shape as X, and it reuses X's signer.** Tumblr's developer page
 * registers an application and its own API console then generates an OAuth token
 * and secret for the account you are already signed in as — four strings on one
 * screen, no redirect, no callback URL, no refresh clock. That is the shape
 * every credential in Kargah has, and it is the reason this network fits at all;
 * `Modules\Social\Support\OAuth1` already exists, is tested against RFC 5849 by
 * hand-computed vectors, and is constructed here with Tumblr's four values.
 * There is deliberately no second signer.
 *
 * 🔴 **The body-exclusion rule decides whether any of this works, and it decides
 * the content type.** `OAuth1`'s docblock states it: the signature base string
 * covers the `oauth_*` parameters plus the **query string** and nothing else.
 * The request body is included only when it is `application/x-www-form-urlencoded`,
 * and `OAuth1` has no parameter for a body on purpose — there is no argument you
 * could pass that would make it sign one. So the content type here is chosen to
 * match the signer rather than the other way round: a **text post is JSON** and
 * a **photo post is multipart**, both of which are excluded from the base
 * string by the spec. Sending the same fields form-encoded would be signing the
 * wrong thing — the fields would have to be in the base string, `OAuth1` as
 * written does not put them there, and the failure is an HTTP 401 that says
 * nothing about why. If a form-encoded path is ever wanted here it needs a
 * change to `OAuth1`, not a change to this file.
 *
 * **Legacy post types, not NPF, and the choice is about media.** Tumblr's modern
 * shape is Neue Post Format on `/v2/blog/{id}/posts`, and for a text post it is
 * strictly more machinery for exactly the same result: an array of content
 * blocks instead of a `body`. Where the two genuinely differ is pictures — NPF
 * uploads media as a multipart envelope whose first part is a `json` document
 * describing the post and whose remaining parts are the files it refers to by
 * identifier, so the post body and the files have to agree with each other
 * across two encodings. The legacy `/v2/blog/{id}/post` endpoint takes
 * `type=photo` with the files attached alongside flat fields, which is one
 * encoding and the same shape `TelegramPublisher` and `DiscordPublisher`
 * already send. Legacy is still accepted and still creates ordinary posts; when
 * Tumblr retires it the fix is this one method and the field names in it.
 *
 * **`meta.status` is the real status.** Tumblr wraps every answer in
 * `{"meta": {"status": …, "msg": …}, "response": …}` and will return HTTP 200
 * carrying a failing `meta.status`, so a successful status code is not evidence
 * anything was posted. That is the same trap Slack has with `ok: false`,
 * Telegram with `ok: false` and Meta with a 200 that has an `error` object;
 * three networks in this module have it, which is why every one of them reads
 * the body before it believes the code.
 *
 * **A picture makes it a different kind of post, not a post with a picture.**
 * `type=text` has a `title` and a `body`; `type=photo` has a `caption` and no
 * title at all. So attaching an image moves the copy from `body` to `caption`
 * and drops the title — exactly as Telegram's `sendPhoto` is a different call
 * from `sendMessage` with the copy in `caption`. The catalogue's media note says
 * so where somebody attaching a picture can read it.
 *
 * **A title is derived from the body's first line and the first line stays
 * where it is.** Tumblr, unlike WordPress, does not require a title — an
 * untitled text post is perfectly legal. It is sent anyway because a titled post
 * is what a Tumblr text post looks like on a dashboard and in an archive, and
 * because the alternative to deriving is a wall of untitled text. The half worth
 * arguing is the same half `Modules\Blog\Services\WordPressPublisher` argues:
 * the line is **not** then removed from the body. A post whose first sentence
 * appears twice is visible and fixable in thirty seconds on Tumblr, whereas
 * silently deleting a sentence from somebody's copy is data loss they cannot see
 * from Kargah at all. Never edit the body you were handed. The day the composer
 * grows a title field this driver should implement `TakesTargetOptions` and take
 * the typed one, keeping the derivation as the fallback — which is precisely the
 * arrangement `WordPressPublisher` already has.
 *
 * `verify()` reads `/v2/user/info` and posts nothing. It goes one step further
 * than most: the response lists the account's blogs, so it checks that the
 * configured `blog_identifier` is actually one of them. 'The token works but
 * that blog is not yours' is a far more useful answer than 'the token works',
 * and it is the failure that would otherwise wait until the first real post.
 *
 * Deliberately does **not** implement `IngestsNotifications`. Tumblr's
 * notification feed is not part of the documented public API, and the
 * catalogue's permissions panel promises Kargah cannot read a dashboard, a
 * message or a follower. `Networks` marks this network `ingests: false`.
 */
class TumblrPublisher extends HttpPublisher
{
    private const API = 'https://api.tumblr.com/v2';

    /**
     * How much of the first line becomes the title. See `derivedTitle()`.
     *
     * Kargah's number, not Tumblr's — Tumblr documents no ceiling on a title. If
     * it is wrong the symptom is a long heading, which is cosmetic.
     */
    private const TITLE_CHARACTERS = 120;

    public function network(): string
    {
        return Networks::TUMBLR;
    }

    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        $blog = $this->blogIdentifier($account);
        $signer = $this->signer($account);
        $media = $this->acceptableMedia($media);
        $body = $this->bodyWithin($body, $media);

        $url = self::API.'/blog/'.$blog.'/post';

        $response = $media === []
            ? $this->postText($signer, $url, $body)
            : $this->postPhotos($signer, $url, $body, $media);

        $id = $this->postId($this->accepted($response));

        // The identifier is the blog's host, so the permalink is simply built
        // from it. Tumblr redirects `/post/<id>` to the slugged URL, so this
        // opens the right post even though the slug is not known here.
        return new PublishedPost($id, 'https://'.$blog.'/post/'.$id);
    }

    /**
     * A legacy text post: JSON, so nothing of it reaches the signature.
     *
     * @return array<array-key, mixed>
     *
     * @throws PublishFailed
     */
    private function postText(OAuth1 $signer, string $url, string $body): array
    {
        $fields = ['type' => 'text', 'body' => $body];

        $title = $this->derivedTitle($body);

        if ($title !== '') {
            $fields['title'] = $title;
        }

        return $this->send(
            $this->request()->withHeaders(['Authorization' => $signer->header('POST', $url)]),
            'post',
            $url,
            $fields,
        );
    }

    /**
     * A legacy photo post: multipart, which is also excluded from the signature.
     *
     * The files are indexed parts — `data[0]`, `data[1]` — because a legacy
     * photo post with more than one picture is a photoset, and the index is how
     * Tumblr keeps their order. One picture still goes up as `data[0]` rather
     * than as a bare `data`, so there is one code path instead of two.
     *
     * There is no title: `type=photo` has no such field, and the copy travels as
     * the caption. See the class docblock.
     *
     * @param  list<MediaItem>  $media
     * @return array<array-key, mixed>
     *
     * @throws PublishFailed
     */
    private function postPhotos(OAuth1 $signer, string $url, string $body, array $media): array
    {
        $files = [];

        foreach ($media as $index => $item) {
            $files['data['.$index.']'] = [$item->filename(), $item->contents(), $item->mime];
        }

        return $this->sendMultipart(
            $this->uploadRequest()->withHeaders(['Authorization' => $signer->header('POST', $url)]),
            $url,
            $files,
            ['type' => 'photo', 'caption' => $body],
        );
    }

    /**
     * `/v2/user/info` names the account and, more usefully, its blogs.
     *
     * Confirming the configured blog is among them is the point of this rather
     * than a flourish. Four credentials that authenticate perfectly against a
     * blog the account does not own produce a 404 or a 403 at the first real
     * post, hours or days later, on a post somebody was relying on. Asking here
     * turns that into a sentence on the connect page.
     *
     * @throws PublishFailed
     */
    public function verify(SocialAccount $account): string
    {
        $blog = $this->blogIdentifier($account);
        $url = self::API.'/user/info';

        $body = $this->accepted($this->send(
            $this->request()->withHeaders([
                'Authorization' => $this->signer($account)->header('GET', $url),
            ]),
            'get',
            $url,
        ));

        $user = $body['response']['user'] ?? null;
        $name = is_array($user) && is_string($user['name'] ?? null) ? $user['name'] : '';

        if ($name === '') {
            throw PublishFailed::malformed($this->network(), 'the credentials were accepted but no account came back');
        }

        $blogs = is_array($user) && is_array($user['blogs'] ?? null) ? $user['blogs'] : [];

        [$matchable, $listed] = $this->blogsOf($blogs);

        if (! in_array(mb_strtolower($blog), $matchable, true)) {
            throw PublishFailed::rejected(
                $this->network(),
                'the four credentials work — Tumblr recognised the account “'.$name.'” — but “'.$blog
                .'” is not one of its blogs, so nothing could ever be posted to it. That account owns '
                .($listed === [] ? 'no blogs at all' : implode(' and ', $listed)),
            );
        }

        return $blog.', as @'.$name;
    }

    /**
     * Every spelling of every blog the account owns, and the list to show.
     *
     * A blog answers to more than one name: `name` is the short form
     * (`kargah-workshop`), `url` carries the host, and a blog on a custom domain
     * has a host that looks nothing like its name. The configured identifier may
     * legitimately be any of them — the catalogue's hint says a custom domain
     * works — so all three spellings are matchable and the comparison is
     * case-insensitive, because a host is.
     *
     * @param  array<array-key, mixed>  $blogs
     * @return array{0: list<string>, 1: list<string>}
     */
    private function blogsOf(array $blogs): array
    {
        $matchable = [];
        $listed = [];

        foreach ($blogs as $blog) {
            if (! is_array($blog)) {
                continue;
            }

            $name = is_string($blog['name'] ?? null) ? trim($blog['name']) : '';
            $host = is_string($blog['url'] ?? null)
                ? mb_strtolower((string) parse_url($blog['url'], PHP_URL_HOST))
                : '';

            foreach ([$name, $name === '' ? '' : $name.'.tumblr.com', $host] as $spelling) {
                if ($spelling !== '') {
                    $matchable[] = mb_strtolower($spelling);
                }
            }

            $shown = $host !== '' ? $host : $name;

            if ($shown !== '') {
                $listed[] = '“'.$shown.'”';
            }
        }

        return [array_values(array_unique($matchable)), $listed];
    }

    /**
     * The blog's host, however the person spelled it.
     *
     * It goes straight into a request path, so it is checked rather than
     * trusted — `DiscordPublisher::endpoint()` earns the same property for the
     * same reason. A whole URL pasted out of the address bar is reduced to its
     * host, because that is what somebody who has just been told 'the blog's
     * host name' most plausibly copies, and it is unambiguous. Anything with a
     * path left in it is refused rather than guessed at: `yourblog.tumblr.com/archive`
     * would build a URL that 404s, and a 404 from Tumblr reads like the post was
     * rejected.
     *
     * @throws PublishFailed
     */
    private function blogIdentifier(SocialAccount $account): string
    {
        $blog = mb_strtolower(trim($this->require($account, 'blog_identifier')));

        if (str_contains($blog, '://')) {
            $blog = mb_strtolower((string) parse_url($blog, PHP_URL_HOST));
        }

        $blog = trim($blog, '/');

        if ($blog === '' || str_contains($blog, '/') || str_contains($blog, '?')) {
            throw PublishFailed::rejected(
                $this->network(),
                'the blog identifier is the blog’s host name — yourblog.tumblr.com, or a custom domain — '
                .'and this account has “'.$this->require($account, 'blog_identifier').'”, which is a path or a whole post URL',
            );
        }

        return $blog;
    }

    /** The four pasted strings, as something that can sign a request. */
    private function signer(SocialAccount $account): OAuth1
    {
        return new OAuth1(
            $this->require($account, 'consumer_key'),
            $this->require($account, 'consumer_secret'),
            $this->require($account, 'token'),
            $this->require($account, 'token_secret'),
        );
    }

    /**
     * The title, taken from the first line and left there. See the class docblock.
     *
     * Cut at a length rather than sent whole because a Tumblr title is a
     * heading: a paragraph in it is not a title, it is the post again.
     */
    private function derivedTitle(string $body): string
    {
        $first = (string) (preg_split('/\R/u', trim($body), 2)[0] ?? '');
        $first = trim((string) preg_replace('/\s+/u', ' ', $first));

        if ($first === '') {
            return '';
        }

        return mb_strlen($first) <= self::TITLE_CHARACTERS
            ? $first
            : rtrim(mb_substr($first, 0, self::TITLE_CHARACTERS - 1)).'…';
    }

    /**
     * The envelope, once `meta.status` has been believed rather than the code.
     *
     * @param  array<array-key, mixed>  $body
     * @return array<array-key, mixed>
     *
     * @throws PublishFailed
     */
    private function accepted(array $body): array
    {
        $status = $body['meta']['status'] ?? null;
        $status = is_numeric($status) ? (int) $status : null;

        if ($status === null) {
            throw PublishFailed::malformed(
                $this->network(),
                'the answer carried no meta.status, and that is the only place Tumblr says whether it did anything',
            );
        }

        if ($status >= 200 && $status < 300) {
            return $body;
        }

        $detail = $this->reasonIn($body);
        $hint = $this->hintFor($status);

        throw PublishFailed::rejected(
            $this->network(),
            'it answered HTTP 200 with meta.status '.$status
            .($detail === '' ? '' : ' ('.$detail.')')
            .($hint === '' ? '' : ' — '.$hint),
        );
    }

    /**
     * The post's id, preferring the string over the number.
     *
     * **`id_string` when it is there, never `id` alone.** A Tumblr post id is a
     * 64-bit integer and JSON has no such thing: decoded through a double it
     * loses its last digits silently, and the target would record an id that
     * belongs to a different post or to none. It is the same reasoning
     * `XPublisher` gives for `media_id_string`, and the fallback to `id` exists
     * because the legacy create response is not documented to carry both.
     *
     * @param  array<array-key, mixed>  $body
     *
     * @throws PublishFailed
     */
    private function postId(array $body): string
    {
        $response = $body['response'] ?? null;
        $id = is_array($response) ? ($response['id_string'] ?? $response['id'] ?? null) : null;

        if (! is_scalar($id) || (string) $id === '') {
            throw PublishFailed::malformed($this->network(), 'the response carried no post id');
        }

        return (string) $id;
    }

    /**
     * Whatever Tumblr said about the refusal, out of either place it says it.
     *
     * Newer errors are a JSON:API-ish list at `errors` with a `title` and a
     * `detail`; older ones put a sentence in `meta.msg`, which is sometimes no
     * more than the status name. Both are read, the list first, because
     * `meta.msg` on its own is usually the status code spelled out.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function reasonIn(array $body): string
    {
        $errors = $body['errors'] ?? ($body['response']['errors'] ?? null);

        if (is_array($errors) && is_array($errors[0] ?? null)) {
            $title = is_string($errors[0]['title'] ?? null) ? trim($errors[0]['title']) : '';
            $explanation = is_string($errors[0]['detail'] ?? null) ? trim($errors[0]['detail']) : '';

            $named = trim(implode(' — ', array_filter([$title, $explanation])));

            if ($named !== '') {
                return mb_substr($named, 0, 200);
            }
        }

        $message = $body['meta']['msg'] ?? null;

        return is_string($message) ? mb_substr(trim($message), 0, 200) : '';
    }

    /**
     * The next thing to do, for the three refusals that have an obvious one.
     *
     * None of Tumblr's own messages name a cause. A person reading a red row
     * wants to know which of the four values to look at, and 'Not Authorized' on
     * its own does not tell them.
     */
    private function hintFor(?int $status): string
    {
        return match ($status) {
            401 => 'Tumblr refused the signature: the consumer key and secret, or the token and token secret, do not '
                .'match — the usual cause is a value pasted from a different application, or the application having '
                .'been revoked since',
            403 => 'Tumblr accepted the credentials but not the action. The token’s account is not allowed to post to '
                .'that blog, which happens when the blog belongs to somebody else or is a group blog this account has '
                .'not been given posting rights on',
            404 => 'Tumblr has no blog with that identifier. Check the blog identifier on this account’s connect page: '
                .'it is the blog’s host name, like yourblog.tumblr.com',
            default => '',
        };
    }

    /**
     * A genuine non-200 keeps its reason in the same envelope.
     *
     * Tumblr answers a real HTTP 401 with the same `meta`/`errors` body it uses
     * on a 200, and none of the four keys `HttpPublisher::detailFrom()` looks in
     * appears anywhere in it — so without this the error cell would hold a
     * serialised envelope instead of Tumblr's sentence and the hint.
     */
    protected function detailFrom(Response $response): string
    {
        $body = $response->json();

        $detail = is_array($body) ? $this->reasonIn($body) : '';

        if ($detail === '') {
            $detail = parent::detailFrom($response);
        }

        return trim(mb_substr($detail, 0, 300).' '.$this->hintFor($response->status()));
    }
}
