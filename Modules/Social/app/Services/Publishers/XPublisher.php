<?php

namespace Modules\Social\Services\Publishers;

use Illuminate\Http\Client\Response;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;
use Modules\Social\Support\OAuth1;

/**
 * X, through an app you own, signed with OAuth 1.0a.
 *
 * **There is no handshake here and that is the whole reason this network fits.**
 * X's developer portal generates an access token and secret for your own account
 * with one click, on the same screen as the app's key and secret. Four strings,
 * pasted into a form, no redirect, no callback URL, no refresh clock — the same
 * shape as every other credential Kargah holds. The signing itself lives in
 * `Modules\Social\Support\OAuth1`, including the rule about what does and does
 * not go into the signature, which is the part that decides whether any of this
 * works.
 *
 * **Two API versions and two hosts in one publish, deliberately.** The tweet is
 * v2 on `api.twitter.com` with a JSON body; the picture goes to v1.1 on
 * `upload.twitter.com`, which is a different host rather than a different path,
 * and a request sent to the wrong one is a 404 with no explanation. That split
 * is X's, not Kargah's. X has since published a v2 media endpoint and means to
 * retire the v1.1 one eventually; the v1.1 upload is what is verified to work
 * with a pasted OAuth 1.0a pair today, so it is what this sends, and the day it
 * stops working the fix is this one constant.
 *
 * **Simple upload only. No `INIT`/`APPEND`/`FINALIZE`.** The chunked protocol
 * exists for video, Kargah sends still images and nothing else, and the
 * catalogue caps them at four of five megabytes each — which fits in one
 * request inside a shared host's `max_execution_time`. A chunked upload can span
 * minutes and would be killed halfway with the post half-sent. See the media
 * note on `Networks`.
 *
 * **`media_id_string`, never `media_id`.** The upload answers with both and they
 * are the same number, but `media_id` is a 64-bit integer and JSON has no such
 * thing — decoded through a double it loses its last digits, silently, and
 * attaches a picture that does not exist or belongs to somebody else. The string
 * is the only safe reading and the only one that goes into the tweet.
 *
 * **An upload that succeeds under a tweet that fails is left alone.** No
 * clean-up call, no best-effort delete: X expires unattached media on its own
 * within a day, and a second request made while handling a failure is a second
 * thing that can fail. The person sees one failed target with X's own words on
 * it and presses retry, which uploads again.
 *
 * `verify()` reads `/2/users/me` and posts nothing — see `Publisher::verify()`
 * for why a test tweet is not an acceptable way to check a credential.
 *
 * Notifications are deliberately absent: the v2 mentions endpoint needs a paid
 * tier and the free one answers 403, so `Networks` marks X `ingests: false` and
 * `social:sync-notifications` skips it rather than logging a refusal every hour.
 */
class XPublisher extends HttpPublisher
{
    private const API = 'https://api.twitter.com';

    /** v1.1, and a **different host** — see the class docblock. */
    private const UPLOAD = 'https://upload.twitter.com/1.1/media/upload.json';

    private const WEB = 'https://x.com';

    /**
     * One attempt, not three.
     *
     * `HttpPublisher::request()` retries the same `PendingRequest`, so a
     * retried tweet would carry the identical `Authorization` header —
     * `OAuth1::header()` is called once, before `->post()` is dispatched, and
     * Laravel's retry re-sends what it already built rather than signing
     * again. A nonce is single use by definition (`uploadAll()` below signs a
     * fresh one per picture for exactly this reason), so X refuses the
     * replay: a transient 503 on the first attempt becomes a 401 on the
     * second, and that reads on the posts page as a bad credential rather
     * than the blip it was. The cost accepted here is that a genuine
     * transient failure now fails the target on the first try instead of
     * healing itself inside the job; `PostTarget::FAILED` is not terminal, so
     * a person pressing retry signs and sends fresh rather than replaying.
     */
    protected const TRIES = 1;

    /** The upload path signs the same way, once per call. See `TRIES`. */
    protected const UPLOAD_TRIES = 1;

    public function network(): string
    {
        return Networks::X;
    }

    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        $signer = $this->signer($account);
        $media = $this->acceptableMedia($media);
        $body = $this->bodyWithin($body, $media);

        // Pictures first: a tweet naming a media id that was never uploaded is
        // refused, so there is no ordering in which this could be cheaper.
        $ids = $this->uploadAll($signer, $media);

        $payload = ['text' => $body];

        if ($ids !== []) {
            $payload['media'] = ['media_ids' => $ids];
        }

        $url = self::API.'/2/tweets';

        $response = $this->send(
            $this->request()->withHeaders(['Authorization' => $signer->header('POST', $url)]),
            'post',
            $url,
            $payload,
        );

        $id = $response['data']['id'] ?? null;

        if (! is_scalar($id) || (string) $id === '') {
            throw PublishFailed::malformed($this->network(), 'the response carried no tweet id');
        }

        return new PublishedPost((string) $id, $this->webUrl($account, (string) $id));
    }

    /**
     * `/2/users/me` names the account the four credentials belong to.
     *
     * `user.fields=username` is a query parameter rather than a body one, so it
     * is part of what gets signed — the one place in this driver where that
     * distinction bites. Requesting it at all is necessary: without the field
     * the response carries an id and a display name, and a numeric id is not
     * proof to anybody reading the connect page that Kargah reached the right
     * account.
     */
    public function verify(SocialAccount $account): string
    {
        $url = self::API.'/2/users/me';
        $query = ['user.fields' => 'username'];

        $response = $this->send(
            $this->request()->withHeaders([
                'Authorization' => $this->signer($account)->header('GET', $url, $query),
            ]),
            'get',
            $url,
            $query,
        );

        $username = $response['data']['username'] ?? null;

        if (! is_string($username) || $username === '') {
            throw PublishFailed::malformed($this->network(), 'the credentials were accepted but no account came back');
        }

        return '@'.$username;
    }

    /**
     * Every picture, as the media ids the tweet will name.
     *
     * Each upload is signed on its own. A nonce is single use by definition, so
     * reusing one header across four uploads would have three of them refused —
     * `OAuth1::header()` is therefore called inside the loop rather than once
     * outside it, which is the sort of thing that reads like an oversight until
     * it is written down.
     *
     * @param  list<MediaItem>  $media
     * @return list<string>
     *
     * @throws PublishFailed
     */
    private function uploadAll(OAuth1 $signer, array $media): array
    {
        $ids = [];

        foreach ($media as $item) {
            $response = $this->sendMultipart(
                $this->uploadRequest()->withHeaders([
                    'Authorization' => $signer->header('POST', self::UPLOAD),
                ]),
                self::UPLOAD,
                // One part, named `media`. The v1.1 endpoint takes exactly this
                // and ignores anything else in the request.
                ['media' => [$item->filename(), $item->contents(), $item->mime]],
            );

            $id = $response['media_id_string'] ?? null;

            if (! is_string($id) || $id === '') {
                throw PublishFailed::malformed(
                    $this->network(),
                    'the upload of “'.$item->name.'” carried no media id, so the tweet was not sent',
                );
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /** The four pasted strings, as something that can sign a request. */
    private function signer(SocialAccount $account): OAuth1
    {
        return new OAuth1(
            $this->require($account, 'consumer_key'),
            $this->require($account, 'consumer_secret'),
            $this->require($account, 'access_token'),
            $this->require($account, 'access_token_secret'),
        );
    }

    /**
     * X puts the reason somewhere `HttpPublisher` does not look.
     *
     * v2 answers in the RFC 7807 shape — a `title` naming the class of problem
     * and a `detail` explaining it — and v1.1 answers with an `errors` list.
     * Neither uses `error`, `error_description`, `message` or `description`, so
     * the inherited reader finds nothing and falls back to printing the raw JSON
     * into a target row. This reads X's own words first and keeps the fallback
     * for the shapes it has not met.
     *
     * The hint on 401 and 403 is not decoration. Those two statuses have one
     * overwhelmingly common cause each — a regenerated token, and an app whose
     * User authentication settings are still Read-only — and neither of X's own
     * messages says so. A person reading a red row wants the next thing to do,
     * not a restatement of the status code.
     */
    protected function detailFrom(Response $response): string
    {
        $body = $response->json();

        $detail = is_array($body) ? $this->reasonIn($body) : '';

        if ($detail === '') {
            $detail = parent::detailFrom($response);
        }

        $hint = match ($response->status()) {
            401 => 'X refused the signature: the four credentials do not match, or the access token was regenerated in the developer portal after it was pasted here.',
            403 => 'X accepted the credentials but not the action — the usual cause is the app’s User authentication settings still being Read rather than Read and write.',
            default => '',
        };

        return trim(mb_substr($detail, 0, 300).' '.$hint);
    }

    /**
     * The reason out of either API version's error shape.
     *
     * @param  array<array-key, mixed>  $body
     */
    private function reasonIn(array $body): string
    {
        $title = is_string($body['title'] ?? null) ? trim($body['title']) : '';
        $explanation = is_string($body['detail'] ?? null) ? trim($body['detail']) : '';

        // v2: both, when both are there. `title` alone is 'Forbidden', which is
        // the status code spelled out; `detail` alone loses the classification.
        $v2 = trim(implode(' — ', array_filter([$title, $explanation])));

        if ($v2 !== '') {
            return $v2;
        }

        // v1.1: a list, of which the first is the one that refused the request.
        $first = $body['errors'][0]['message'] ?? null;

        return is_string($first) ? trim($first) : '';
    }

    /**
     * The link a person can open, built from the handle rather than the response.
     *
     * `POST /2/tweets` answers with an id and the text and nothing else — the
     * author is not in it unless the request asked for an expansion, and asking
     * for one to build a URL would be a round trip for a string Kargah already
     * has. An account with no handle recorded falls back to `x.com/i/web/status`,
     * which X resolves to the same tweet, so the posts page always has a link.
     */
    private function webUrl(SocialAccount $account, string $id): string
    {
        $handle = ltrim(trim((string) $account->handle), '@');

        return self::WEB.'/'.($handle === '' ? 'i/web' : $handle).'/status/'.$id;
    }
}
