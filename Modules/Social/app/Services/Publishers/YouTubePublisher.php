<?php

namespace Modules\Social\Services\Publishers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * YouTube, uploading one video to a channel through the Data API v3.
 *
 * 🔴 **The only driver in Kargah whose post is a video, and the only one that
 * cannot answer `publish()`.** There is no text post on YouTube and no photo
 * post; `videos.insert` is the single way to put anything on a channel. So this
 * implements `PublishesVideo`, `publish()` refuses by name, and `PostPublisher`
 * routes between the two by `instanceof`. `Networks::YOUTUBE`'s constant
 * docblock makes the same argument from the catalogue's side.
 *
 * ## The credential, and why it is three fields rather than one
 *
 * Every other network here hands a person a string from a settings screen.
 * Google does not: there is no long-lived API key that can upload, and the only
 * route to `youtube.upload` is an OAuth consent that returns a **refresh
 * token**. Kargah therefore stores the client id, the client secret and that
 * refresh token, and exchanges them for a one-hour access token on every
 * publish — never storing the short-lived one.
 *
 * That is deliberately the shape `RedditPublisher` already established ("the
 * access token Reddit issues lasts an hour, and this driver fetches a fresh one
 * on every publish rather than storing it"). Two drivers doing the same thing
 * the same way is worth more than saving one round trip: there is one place to
 * look when a short-lived-token flow misbehaves.
 *
 * 🔴 **`invalid_grant` is the failure this network will actually produce**, and
 * it gets its own sentence for the same reason Meta's code 190 does. Google
 * expires the refresh tokens of an OAuth app whose consent screen is still in
 * **Testing** after seven days — silently, with a working credential one day and
 * a dead one the next, and nothing on the pasted string to say which kind it
 * was. `Networks::YOUTUBE['requirement']` warns before the paste; this warns
 * after, because the person reading a red target row is not the person who read
 * the form.
 *
 * ## The upload
 *
 * Two calls, and they are not the container-then-publish dance Meta uses. First
 * a **resumable session** is opened with the metadata and the byte count, and
 * Google answers with a URL in the `Location` header. Then the bytes are `PUT`
 * to that URL and the finished video comes back. The session URL is the only
 * thing that carries the upload's identity, which is why the `Location` header
 * is read as a hard requirement rather than an optimisation.
 *
 * 🔴 **The bytes stream and are never turned into a string**, through
 * `VideoItem::stream()` rather than `MediaItem::contents()`. A hundred-megabyte
 * `file_get_contents` inside a queue worker on shared hosting is the failure
 * this whole shape exists to avoid; see `VideoItem`'s docblock.
 *
 * 🔴 **One attempt, never a retry.** `HttpPublisher` already argues that
 * re-sending ten megabytes is how one target eats a job's whole execution
 * budget; a video is an order of magnitude worse, and a half-finished upload
 * retried from zero is worse still. A failed upload is a red row with a retry
 * button, which is a decision for a person rather than for a `retry()` count.
 *
 * ## The title
 *
 * A video has a title *and* a description where every other network has one
 * field, so the first line of the copy becomes the title and the whole body
 * stays as the description — the same split `RedditPublisher::derivedTitle()`
 * makes, deliberately, so that somebody composing for both learns the rule once.
 *
 * **No permalink guesswork here, unlike Instagram and Threads.** `videos.insert`
 * answers with the video id, and a YouTube watch URL is that id in a fixed
 * template — so the target row gets a real link rather than an em dash.
 */
class YouTubePublisher extends HttpPublisher implements PublishesVideo
{
    /** Google's OAuth token endpoint, shared by every Google API. */
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** Metadata reads. Note the host differs from the upload host below. */
    private const API = 'https://www.googleapis.com/youtube/v3';

    /**
     * Uploads go to `/upload/…`, and sending them to `self::API` instead answers
     * `404` with an HTML body rather than anything about videos. One constant
     * each, for the same reason `MetaGraph` and `ThreadsPublisher` keep separate
     * hosts rather than assembling one from a flag.
     */
    private const UPLOAD = 'https://www.googleapis.com/upload/youtube/v3/videos';

    private const WATCH = 'https://www.youtube.com/watch?v=';

    /** YouTube's number for a video title, not Kargah's. */
    private const TITLE_LIMIT = 100;

    /**
     * How long to wait for the bytes to finish going up.
     *
     * Ten minutes rather than `HttpPublisher::UPLOAD_TIMEOUT`'s thirty seconds,
     * because thirty seconds would fail every video from a domestic connection
     * while the metadata call sailed through. It is a ceiling on a runaway
     * upload rather than a target: what actually bounds this is
     * `Networks::YOUTUBE['media']['max_bytes']`, checked in the composer before
     * a post row exists.
     */
    private const UPLOAD_SECONDS = 600;

    public function network(): string
    {
        return Networks::YOUTUBE;
    }

    /**
     * Refused, always, and by name.
     *
     * `Publisher` requires this method and YouTube cannot answer it: there is no
     * endpoint that publishes text or a picture to a channel. Saying so here
     * costs one method and buys a sentence a person can act on, instead of
     * whatever Google makes of a request Kargah should never have sent — the
     * same trade `InstagramPublisher::publish()` makes when a post has no image.
     *
     * In practice `PostPublisher` routes a YouTube target to `publishVideo()`
     * and only lands here when the post carries no video at all.
     */
    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        throw PublishFailed::rejected(
            $this->network(),
            'YouTube has no text or photo post — a video is the post, so attach one or take YouTube off this post',
        );
    }

    /**
     * Upload the video and publish it.
     *
     * @param  array<string, mixed>  $options
     */
    public function publishVideo(
        SocialAccount $account,
        string $body,
        VideoItem $video,
        array $options = [],
    ): PublishedPost {
        $this->acceptableVideo($video);

        $token = $this->accessToken($account);
        $title = $this->derivedTitle($body);

        $session = $this->openUpload($token, $video, $title, $body, $this->privacyFrom($options));

        $id = $this->putStream($session, $video);

        return new PublishedPost($id, self::WATCH.$id);
    }

    /**
     * The channel this credential can upload to.
     *
     * `channels.list?mine=true` is the cheapest call that names it, and naming
     * it is the point: a Google account can own several channels and a brand
     * account is a different channel again, so "the credentials work" is not the
     * same answer as "they reach the channel you meant". The same argument
     * `InstagramPublisher::verify()` makes about echoing back the `@handle`.
     */
    public function verify(SocialAccount $account): string
    {
        $body = $this->googleSend(
            $this->authorised($this->accessToken($account)),
            'get',
            self::API.'/channels',
            ['part' => 'snippet', 'mine' => 'true'],
        );

        $items = $body['items'] ?? null;
        $snippet = is_array($items) && is_array($items[0] ?? null) ? ($items[0]['snippet'] ?? null) : null;
        $title = is_array($snippet) ? ($snippet['title'] ?? null) : null;

        if (! is_string($title) || trim($title) === '') {
            // Reached Google, was accepted, and no channel came back — which is
            // what a Google account that has never created a channel looks like.
            throw PublishFailed::rejected(
                $this->network(),
                'the credentials work but this Google account has no YouTube channel, so there is nothing to upload to — create one on youtube.com first',
            );
        }

        $handle = is_array($snippet) && is_string($snippet['customUrl'] ?? null) ? $snippet['customUrl'] : null;

        return $handle === null || trim($handle) === '' ? $title : $title.' ('.$handle.')';
    }

    /**
     * A one-hour access token, minted fresh for this publish and never stored.
     *
     * @throws PublishFailed
     */
    private function accessToken(SocialAccount $account): string
    {
        $body = $this->googleSend($this->request()->asForm(), 'post', self::TOKEN_URL, [
            'client_id' => $this->require($account, 'client_id'),
            'client_secret' => $this->require($account, 'client_secret'),
            'refresh_token' => $this->require($account, 'refresh_token'),
            'grant_type' => 'refresh_token',
        ]);

        $token = $body['access_token'] ?? null;

        if (! is_string($token) || trim($token) === '') {
            throw PublishFailed::malformed($this->network(), 'the token exchange was accepted but carried no access token');
        }

        return $token;
    }

    /**
     * Open a resumable session and return the URL Google wants the bytes at.
     *
     * The byte count goes up front in `X-Upload-Content-Length` because that is
     * what lets Google refuse an oversized upload before a single byte moves,
     * rather than after four minutes of transfer.
     *
     * @return string the session URL from the `Location` header
     *
     * @throws PublishFailed
     */
    private function openUpload(
        string $token,
        VideoItem $video,
        string $title,
        string $description,
        string $privacy,
    ): string {
        $request = $this->authorised($token)->withHeaders([
            'X-Upload-Content-Type' => $video->mime,
            'X-Upload-Content-Length' => (string) $video->sizeBytes,
        ]);

        $url = self::UPLOAD.'?uploadType=resumable&part=snippet,status';

        try {
            $response = $request->post($url, [
                'snippet' => [
                    'title' => $title,
                    'description' => $description,
                ],
                'status' => ['privacyStatus' => $privacy],
            ]);
        } catch (ConnectionException $e) {
            throw $this->cannotReach($url, $e);
        }

        $this->refuseGoogleError($response);

        $location = $response->header('Location');

        if ($location === '') {
            // A 200 with no session URL is not a success anybody can continue
            // from, and sending the bytes anywhere else would upload them into
            // nothing.
            throw PublishFailed::malformed(
                $this->network(),
                'YouTube accepted the video’s details but did not say where to send the file',
            );
        }

        return $location;
    }

    /**
     * `PUT` the file at the session URL and read back the finished video's id.
     *
     * ⚠️ **Named `putStream`, not `sendBytes`.** `HttpPublisher::sendBytes()`
     * already exists with a different signature — it takes a `PendingRequest`
     * and a string of bytes — and re-declaring it here is a fatal error rather
     * than an override. The name difference is also honest: that one buffers and
     * this one does not.
     *
     * The handle is closed in a `finally` rather than left to the request: a
     * refused upload still opened a file, and a queue worker that runs a hundred
     * failed posts would otherwise run out of descriptors long before anybody
     * noticed why.
     *
     * @throws PublishFailed
     */
    private function putStream(string $sessionUrl, VideoItem $video): string
    {
        $stream = $video->stream();

        try {
            $response = Http::acceptJson()
                ->timeout(self::UPLOAD_SECONDS)
                ->connectTimeout(static::CONNECT_TIMEOUT)
                ->withHeaders(['Content-Length' => (string) $video->sizeBytes])
                ->withBody($stream, $video->mime)
                ->put($sessionUrl);
        } catch (ConnectionException $e) {
            throw $this->cannotReach($sessionUrl, $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $this->refuseGoogleError($response);

        $body = $response->json();
        $id = is_array($body) ? ($body['id'] ?? null) : null;

        if (! is_scalar($id) || (string) $id === '') {
            throw PublishFailed::malformed($this->network(), 'the upload finished but no video id came back');
        }

        return (string) $id;
    }

    /**
     * Whether YouTube will take this file at all, asked before a token is even
     * fetched.
     *
     * `HttpPublisher::acceptableMedia()` does this for images against the same
     * catalogue entry, and cannot be reused because it takes a list of
     * `MediaItem`. The rules it reads are the same ones.
     *
     * @throws PublishFailed
     */
    private function acceptableVideo(VideoItem $video): void
    {
        $rules = Networks::media($this->network());

        if (! in_array($video->mime, $rules['mimes'], true)) {
            throw PublishFailed::rejected(
                $this->network(),
                'it does not accept '.$video->mime.' — “'.$video->name.'” cannot go to this channel, and MP4 is the safest container',
            );
        }

        if ($video->sizeBytes > $rules['max_bytes']) {
            throw PublishFailed::rejected(
                $this->network(),
                '“'.$video->name.'” is '.round($video->sizeBytes / 1048576).' MB and this install’s ceiling is '
                .round($rules['max_bytes'] / 1048576).' MB — YouTube would take it, but this server’s upload limit '
                .'and one job’s execution budget will not',
            );
        }
    }

    /**
     * Public unless the target says otherwise.
     *
     * 🔴 **Deliberately not `private`.** Every other driver here makes the post
     * visible, and a person pressing publish means publish; a video that uploads
     * successfully and cannot be seen would mark the target `published` while
     * achieving nothing, which is the silent-failure shape this codebase treats
     * as worse than an error. `unlisted` and `private` remain reachable through
     * the target's options for somebody who wants a review step.
     *
     * @param  array<string, mixed>  $options
     */
    private function privacyFrom(array $options): string
    {
        $chosen = $options['privacy_status'] ?? null;

        return is_string($chosen) && in_array($chosen, ['public', 'unlisted', 'private'], true)
            ? $chosen
            : 'public';
    }

    /**
     * The title YouTube requires and the composer has no field for.
     *
     * The first line with anything on it, truncated to YouTube's 100 — and left
     * in the description as well, because removing it would silently edit
     * somebody's copy. `RedditPublisher::derivedTitle()` makes the identical
     * choice for the identical reason.
     *
     * 🔴 **`<` and `>` are stripped.** The API refuses a title containing either
     * with a `400` that names neither the character nor the field, and a person
     * who wrote "Kargah <live>" would have no way to guess. Stripping two
     * characters silently is the lesser edit; refusing the post over them would
     * be the greater one.
     *
     * @throws PublishFailed
     */
    private function derivedTitle(string $body): string
    {
        foreach (preg_split('/\R/u', $body) ?: [] as $line) {
            $line = trim(str_replace(['<', '>'], '', $line));

            if ($line === '') {
                continue;
            }

            return mb_strlen($line) > self::TITLE_LIMIT
                // The ellipsis sits inside the limit, so what Google receives is
                // exactly 100 characters.
                ? mb_substr($line, 0, self::TITLE_LIMIT - 1).'…'
                : $line;
        }

        throw PublishFailed::rejected(
            $this->network(),
            'YouTube needs a title and Kargah takes it from the first line of the copy, which is empty',
        );
    }

    /** Every Google call carries the bearer token in a header, never in a query. */
    private function authorised(string $token): PendingRequest
    {
        return $this->request()->withToken($token);
    }

    /**
     * One Google call, decoded, with the error envelope read before the status.
     *
     * @param  'get'|'post'  $method
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>
     *
     * @throws PublishFailed
     */
    private function googleSend(PendingRequest $request, string $method, string $url, array $payload = []): array
    {
        try {
            $response = $method === 'get' ? $request->get($url, $payload) : $request->post($url, $payload);
        } catch (ConnectionException $e) {
            throw $this->cannotReach($url, $e);
        }

        $this->refuseGoogleError($response);

        $body = $response->json();

        if (! is_array($body)) {
            throw PublishFailed::malformed($this->network(), 'the body did not decode as JSON');
        }

        return $body;
    }

    /**
     * Google's refusal, in both shapes it comes in.
     *
     * 🔴 **`HttpPublisher::detailFrom()` cannot read either.** It looks for a
     * *string* at `error`, and the API's `error` is an object while OAuth's is a
     * short machine code with the sentence next door in `error_description` — so
     * left to the base class both would fall through to the raw JSON and put a
     * serialised envelope in the target's error cell. `MetaGraph`'s docblock
     * records the same trap for Graph, and it is the same fix.
     */
    private function refuseGoogleError(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        // OAuth's shape: {"error":"invalid_grant","error_description":"..."}
        $oauthCode = is_string($body['error'] ?? null) ? $body['error'] : null;

        if ($oauthCode !== null) {
            throw PublishFailed::rejected($this->network(), $this->oauthSentence(
                $oauthCode,
                is_string($body['error_description'] ?? null) ? $body['error_description'] : '',
            ));
        }

        // The API's shape: {"error":{"code":403,"message":"…","errors":[{"reason":"…"}]}}
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];
        $message = is_string($error['message'] ?? null) ? $error['message'] : '';
        $reason = is_array($error['errors'][0] ?? null) && is_string($error['errors'][0]['reason'] ?? null)
            ? $error['errors'][0]['reason']
            : '';

        if ($message === '' && $reason === '') {
            throw PublishFailed::status($this->network(), $response->status(), $this->cut($response->body()));
        }

        throw PublishFailed::rejected($this->network(), $this->apiSentence($reason, $message));
    }

    /**
     * The OAuth failures worth their own sentence.
     *
     * `invalid_grant` is by a distance the one this network will actually
     * produce — see the class docblock — and the advice that fixes it is not
     * guessable from Google's own wording, which is the single word "Bad
     * Request" often enough to be useless.
     */
    private function oauthSentence(string $code, string $description): string
    {
        $said = $description === '' ? '' : ' (Google said “'.$this->cut($description).'”)';

        return match ($code) {
            'invalid_grant' => 'the refresh token has been revoked or has expired'.$said.' — if the OAuth consent '
                .'screen for this app is still in Testing, Google expires every refresh token after seven days, so '
                .'set it to In production and run the consent once more',
            'invalid_client' => 'Google refused the client id or secret'.$said.' — these are the app’s credentials '
                .'rather than the channel’s, and both have to come from the same OAuth client',
            'unauthorized_client' => 'this OAuth client is not allowed the upload scope'.$said.' — the client has to '
                .'be created as a Desktop app and consented with the YouTube upload scope',
            default => 'Google refused the credentials ('.$code.')'.$said,
        };
    }

    /** The API failures worth their own sentence. */
    private function apiSentence(string $reason, string $message): string
    {
        $said = $message === '' ? 'YouTube refused it without saying why' : $this->cut($message);

        return match ($reason) {
            'quotaExceeded', 'uploadLimitExceeded' => $said.'. This is a daily quota rather than anything wrong with '
                .'the video — the default allowance is a handful of uploads a day and it resets at midnight Pacific',
            'youtubeSignupRequired' => $said.'. The Google account this credential belongs to has no YouTube channel '
                .'yet, so there is nothing to upload to',
            'forbidden', 'insufficientPermissions' => $said.'. The consent that produced this refresh token did not '
                .'include the YouTube upload scope, so it has to be run again',
            'invalidVideoMetadata', 'invalidTitle' => $said.'. The title comes from the first line of the copy, so '
                .'changing that line changes the title',
            default => $said.($reason === '' ? '' : ' ('.$reason.')'),
        };
    }

    /** Whatever Google said, cut to something that fits an error cell. */
    private function cut(string $detail): string
    {
        return mb_substr(trim($detail), 0, 300);
    }
}
