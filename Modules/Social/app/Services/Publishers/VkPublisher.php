<?php

namespace Modules\Social\Services\Publishers;

use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * VK, through `wall.post` on the wall the token can write to.
 *
 * One wall per connection, named by `owner_id`: a positive number is a person's
 * own wall and a **negative** one is a community's. That minus sign is the whole
 * of VK's addressing scheme and it is the first thing to check when a post lands
 * somewhere unexpected — an id typed without it publishes to the operator's
 * personal wall and VK reports nothing wrong, because nothing was.
 *
 * Deliberately does **not** implement `IngestsNotifications`. Reading a wall's
 * comments or a profile's notifications needs scopes this connection does not
 * ask for, and `social:sync-notifications` skips the network by name rather than
 * showing an empty feed that reads as 'nothing happened'.
 *
 * ## The envelope, which is the thing that costs a debugging cycle
 *
 * 🔴 **VK answers HTTP 200 with `{"error": {...}}` for almost every refusal.** A
 * successful status code is not evidence that anything was published, and a
 * driver that trusted `$response->failed()` would record a remote id of nothing
 * and mark the target delivered. Every call here reads the envelope before it
 * reads the status. `TelegramPublisher` has the same problem with `ok: false`
 * and the three Meta drivers with a 200 carrying `error`; this is the third
 * instance of the same shape in this directory, which is why it is worth saying
 * plainly in all three.
 *
 * Two error codes get their own sentence because a person can act on them.
 * **Code 5** is an invalid or expired token, and it is the failure this network
 * is most likely to produce: a token granted without the `offline` scope stops
 * working within the day, looks identical to one that never expires, and dies
 * exactly this way — so the message says which scope to ask for rather than
 * leaving somebody to compare two opaque strings. **Code 214** is 'access to
 * adding post denied', which in practice means the token was granted without
 * `wall`, or the account is not an administrator of the community it is naming.
 * **Code 9** is flood control, which is what VK says when the same text is sent
 * twice, and **code 6** is the ordinary per-second rate limit.
 *
 * ## Pictures: three requests per image, and the middle one is not to the API
 *
 * Nothing else in Kargah looks like this, so it is written out in full:
 *
 * 1. `GET photos.getWallUploadServer` answers with a one-shot `upload_url` on
 *    one of VK's upload hosts.
 * 2. The bytes go to that URL as multipart under a part named `photo`, and it
 *    answers `{server, photo, hash}` — a bare object with **no VK envelope at
 *    all**, so the error reading in `vkSend()` does not apply to it. Its own
 *    failure mode is a `photo` field containing the literal string `[]`, which
 *    means the file was not accepted; that is checked, because saving it would
 *    otherwise succeed and produce an attachment with no picture in it.
 * 3. `POST photos.saveWallPhoto` turns those three opaque values into a stored
 *    photo and answers with its `owner_id` and `id`.
 *
 * Only then does `wall.post` go out, with `attachments` set to a comma-separated
 * list of `photo{owner_id}_{id}` in the order the pictures were attached. Ten
 * images is therefore thirty-one requests, which is why the catalogue caps the
 * count rather than leaving it open.
 *
 * ⚠️ **Step 3 is already public, and a failure at `wall.post` leaves it there.**
 * `photos.saveWallPhoto` does not stage anything: it files the picture in the
 * wall's own photo album, where the community's followers can see it and where
 * VK may surface it in their feed — before the post it belongs to exists, and
 * whether or not that post ever does. So a `wall.post` that fails on the last of
 * thirty-one requests has still published the pictures, and Kargah reports the
 * target as failed, which it is. Somebody looking at the album afterwards finds
 * images with no post attached and nothing in Kargah explaining them.
 *
 * That is accepted rather than solved, and the reason is that the alternatives
 * are each worse. Deleting on failure means `photos.delete` inside a `catch`,
 * which is a second network call on a path that is already failing — most likely
 * because VK is unreachable, so the cleanup fails too, and now the error message
 * is about the cleanup rather than about the post. Uploading after `wall.post`
 * is not possible: the attachment ids are an argument to it. The honest fix is
 * an album chosen for the purpose, which VK's wall-upload flow does not offer.
 *
 * What this costs in practice is small — `wall.post` is the *most* likely of the
 * thirty-one to succeed, since the uploads have already proved the token, the
 * scopes and the community rights — but it is a real remote side effect from a
 * post Kargah calls failed, and a person clearing up after one should be able to
 * find out why the pictures are there.
 *
 * 🔴 **`group_id` is positive where `owner_id` is negative.** The upload calls
 * take the community's bare id and the post takes it with a minus sign in front,
 * and getting that wrong is a *silent* failure: the photo uploads to the wrong
 * album, `wall.post` accepts an attachment the community does not own, and the
 * post appears without the picture. For a personal wall there is no `group_id`
 * at all and sending an empty one is not the same as omitting it.
 *
 * 🔴 **Every call here is a POST, including the reads, and that is about the
 * token rather than about REST.** There is no bearer scheme on
 * `api.vk.com/method/*`, so the token is a parameter — and a parameter on a GET
 * is in the URL. When a request times out, Guzzle builds its exception message
 * by appending the whole URI, `HttpPublisher` turns that into a `PublishFailed`,
 * `PostPublisher` writes it to `post_targets.error`, and the posts page prints
 * it. One timeout on `users.get` would have put the account's access token,
 * unencrypted, in the database and on a page — defeating all three layers of
 * credential hiding on `SocialAccount`, none of which apply to a request URL.
 * A form body never appears in that message. See `vkSend()`.
 */
class VkPublisher extends HttpPublisher
{
    /** Every method lives under one host and differs only in the last segment. */
    private const HOST = 'https://api.vk.com/method/';

    /**
     * The API version every call carries, pinned rather than floating.
     *
     * VK resolves an omitted `v` against the application's own default, which is
     * whatever it was when the application was created — so two installs would
     * behave differently against the same code. Pinned, a version change is
     * something somebody chooses after reading the changelog, and the symptom of
     * it being retired is every VK target failing on the same day rather than
     * one field quietly changing shape.
     */
    private const API_VERSION = '5.199';

    /** The token is invalid or has expired. See the class docblock. */
    private const TOKEN_INVALID = 5;

    /** Too many requests per second. */
    private const TOO_MANY_REQUESTS = 6;

    /** Flood control — most often the same text sent twice. */
    private const FLOOD_CONTROL = 9;

    /** Access to adding a post denied: a missing `wall` scope, or no rights on the community. */
    private const POST_DENIED = 214;

    public function network(): string
    {
        return Networks::VK;
    }

    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        $token = $this->require($account, 'access_token');
        $owner = $this->owner($account);
        $media = $this->acceptableMedia($media);
        $body = $this->bodyWithin($body, $media);

        $fields = [
            'owner_id' => $owner,
            'message' => $body,
        ];

        // 🔴 Without this, a post to a **community** wall is signed by the
        // administrator personally rather than by the community.
        //
        // It is not an error and VK reports nothing wrong — the post appears,
        // in the right place, under the wrong name — which makes it the kind of
        // defect somebody discovers from a reply addressed to them rather than
        // from anything Kargah could show. `from_group` is only meaningful when
        // the wall belongs to a community, and a community is exactly what the
        // leading minus on `owner_id` means; sending it for a personal wall is
        // at best ignored and at worst an argument error, so it is conditional.
        //
        // The literal string rather than a PHP `true`, for the same reason the
        // Meta drivers spell their booleans out: this body is form-encoded, and
        // a PHP `true` travels as `1` while a PHP `false` travels as `0` — one
        // of which VK reads as a deliberate "no". One rule, no per-transport
        // thinking.
        if (str_starts_with($owner, '-')) {
            $fields['from_group'] = '1';
        }

        $attachments = $this->uploadAll($token, $owner, $media);

        // Absent rather than empty when there are no pictures: VK reads an empty
        // `attachments` as a malformed list often enough to be worth avoiding,
        // and a text post has no business naming the parameter at all.
        if ($attachments !== []) {
            $fields['attachments'] = implode(',', $attachments);
        }

        $response = $this->vkSend('wall.post', $token, $fields);

        $postId = $response['response']['post_id'] ?? null;

        if (! is_scalar($postId) || (string) $postId === '') {
            throw PublishFailed::malformed($this->network(), 'the response carried no post id');
        }

        return new PublishedPost(
            (string) $postId,
            'https://vk.com/wall'.$owner.'_'.$postId,
        );
    }

    /**
     * `users.get` with no ids names whoever the token belongs to.
     *
     * It proves the token works and says which account it is, which is the
     * useful half. It does **not** prove the token may write to the wall in
     * `owner_id` — nothing short of posting would — so the connect page reports
     * the account rather than implying the whole connection is good.
     */
    public function verify(SocialAccount $account): string
    {
        $response = $this->vkSend('users.get', $this->require($account, 'access_token'));

        $user = $response['response'][0] ?? null;

        if (! is_array($user)) {
            throw PublishFailed::malformed($this->network(), 'the token was accepted but no account came back');
        }

        $name = trim(
            (is_string($user['first_name'] ?? null) ? $user['first_name'] : '')
            .' '
            .(is_string($user['last_name'] ?? null) ? $user['last_name'] : '')
        );

        $id = $user['id'] ?? null;

        if ($name === '' && ! is_scalar($id)) {
            throw PublishFailed::malformed($this->network(), 'the token was accepted but no account came back');
        }

        return $name === '' ? 'id'.$id : $name.(is_scalar($id) ? ' (id'.$id.')' : '');
    }

    /**
     * One API call, with the envelope read before anything else.
     *
     * The token and the version are appended here rather than by each caller,
     * because a call missing either is refused in a way that reads like a
     * credential problem. `+` rather than `array_merge` so a caller can never
     * have its own field silently overwritten by one of these two.
     *
     * @param  'get'|'post'  $method
     * @param  array<string, string>  $fields
     * @return array<array-key, mixed>
     *
     * @throws PublishFailed
     */
    private function vkSend(string $apiMethod, string $token, array $fields = []): array
    {
        // 🔴 **Always POST, even for a read, and the reason is the access token.**
        //
        // Every `api.vk.com/method/*` endpoint accepts either, and `users.get` is
        // a read, so a GET is the obvious spelling. It is also the one that leaks:
        // `HttpPublisher::send()` passes the fields to `$request->get()`, which
        // puts them in the query string, and when the call times out Guzzle builds
        // its `ConnectionException` message by appending the **whole URI** —
        // token and all. `HttpPublisher` turns that message into a
        // `PublishFailed`, `PostPublisher` writes it to `post_targets.error`, and
        // the posts page renders it. One timed-out request and the account's
        // access token is sitting unencrypted in the database and printed on a
        // page, which is precisely what `SocialAccount`'s three layers of
        // credential hiding exist to prevent — and none of them apply, because
        // this is a request URL rather than a model.
        //
        // A form body is never in the URI, so it is never in that message. There
        // is no case where a GET is worth that.
        //
        // Form-encoded, not JSON, for a second and unrelated reason:
        // `api.vk.com/method/*` reads parameters out of the query string or a
        // form body and ignores a JSON document entirely — which fails as an
        // empty `message` rather than as a bad request.
        $body = $this->send($this->request()->asForm(), 'post', self::HOST.$apiMethod, $fields + [
            'access_token' => $token,
            'v' => self::API_VERSION,
        ]);

        $this->refuseVkError($body);

        return $body;
    }

    /**
     * Turn a 200 that carries `error` into the failure it actually is.
     *
     * @param  array<array-key, mixed>  $body
     *
     * @throws PublishFailed
     */
    private function refuseVkError(array $body): void
    {
        $error = $body['error'] ?? null;

        if (! is_array($error)) {
            return;
        }

        $code = (int) ($error['error_code'] ?? 0);
        $said = is_string($error['error_msg'] ?? null) && $error['error_msg'] !== ''
            ? $error['error_msg']
            : 'no reason given';

        throw PublishFailed::rejected($this->network(), match ($code) {
            self::TOKEN_INVALID => 'the access token was refused — VK said “'.$said.'”. '
                .'A token granted without the offline scope stops working within a day and looks '
                .'exactly like this when it does, so issue a new one with the wall, photos and '
                .'offline scopes and paste it in again',
            self::POST_DENIED => 'the token is not allowed to add a post here — VK said “'.$said.'”. '
                .'That is almost always a token granted without the wall scope, or an account that '
                .'does not administer the community in the wall owner ID',
            self::FLOOD_CONTROL => 'VK refused this as flood control — “'.$said.'”. '
                .'It says that for the same text sent twice, so check whether the post already went out '
                .'before sending it again',
            self::TOO_MANY_REQUESTS => 'VK is rate limiting this token — “'.$said.'”. '
                .'Nothing is wrong with the post; press retry in a minute',
            default => 'VK answered error '.$code.' — “'.$said.'”',
        });
    }

    /**
     * Register, upload and save each picture, in the order they were attached.
     *
     * Order is the reason this is a plain loop: `attachments` is a sequence and
     * the wall renders it in the order given, so the ids have to come back in
     * the order the pictures went up.
     *
     * @param  list<MediaItem>  $media
     * @return list<string> `photo{owner}_{id}` entries, in attach order
     *
     * @throws PublishFailed
     */
    private function uploadAll(string $token, string $owner, array $media): array
    {
        if ($media === []) {
            return [];
        }

        $group = $this->groupId($owner);
        $attachments = [];

        foreach ($media as $item) {
            $uploaded = $this->sendMultipart(
                $this->uploadRequest(),
                $this->uploadServer($token, $group),
                ['photo' => [$item->filename(), $item->contents(), $item->mime]],
            );

            $attachments[] = $this->save($token, $group, $item, $uploaded);
        }

        return $attachments;
    }

    /**
     * Step one: ask for somewhere to send the bytes.
     *
     * @throws PublishFailed
     */
    private function uploadServer(string $token, ?string $group): string
    {
        $response = $this->vkSend(
            'photos.getWallUploadServer',
            $token,
            // Omitted entirely for a personal wall. An empty `group_id` is not
            // the same as no `group_id` — VK reads the first as group zero.
            $group === null ? [] : ['group_id' => $group],
        );

        $url = $response['response']['upload_url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw PublishFailed::malformed($this->network(), 'VK gave no upload server, so the post was not created');
        }

        return $url;
    }

    /**
     * Step three: turn `{server, photo, hash}` into a stored photo and name it.
     *
     * The three values are passed back exactly as they arrived, `photo`
     * included — it is a JSON string VK signs and re-reads, and decoding or
     * re-encoding it invalidates it.
     *
     * @param  array<array-key, mixed>  $uploaded  the upload host's bare response
     * @return string `photo{owner}_{id}`
     *
     * @throws PublishFailed
     */
    private function save(string $token, ?string $group, MediaItem $item, array $uploaded): string
    {
        $server = $uploaded['server'] ?? null;
        $photo = $uploaded['photo'] ?? null;
        $hash = $uploaded['hash'] ?? null;

        // `photo` comes back as the literal `[]` when the upload host took the
        // request and refused the file. Saving that succeeds and produces an
        // attachment with no picture in it, so it is caught here instead.
        if (! is_scalar($server) || ! is_scalar($hash) || ! is_string($photo) || $photo === '' || $photo === '[]') {
            throw PublishFailed::malformed(
                $this->network(),
                'the upload of “'.$item->name.'” was not accepted by VK’s upload server, so the post was not created',
            );
        }

        $response = $this->vkSend('photos.saveWallPhoto', $token, [
            'server' => (string) $server,
            'photo' => $photo,
            'hash' => (string) $hash,
        ] + ($group === null ? [] : ['group_id' => $group]));

        $saved = $response['response'][0] ?? null;

        $photoOwner = is_array($saved) ? ($saved['owner_id'] ?? null) : null;
        $photoId = is_array($saved) ? ($saved['id'] ?? null) : null;

        if (! is_scalar($photoOwner) || ! is_scalar($photoId)) {
            throw PublishFailed::malformed(
                $this->network(),
                'saving “'.$item->name.'” to the wall album returned no photo, so the post was not created',
            );
        }

        return 'photo'.$photoOwner.'_'.$photoId;
    }

    /**
     * The wall's owner id, however the person typed it.
     *
     * People paste what they can see, and on VK what they can see is the tail of
     * a profile URL: `-87654321`, `club87654321`, `public87654321`,
     * `event87654321`, `id12345678`. Only a signed number means anything to
     * `wall.post`, so all of those have to become one.
     *
     * 🔴 **The prefix carries the sign, and dropping it publishes to the wrong
     * wall in silence.** This method used to keep the digits and a leading minus
     * and nothing else, which turned `club87654321` into `87654321` — and
     * `87654321` is not that community, it is *a person*, whoever happens to
     * hold that user id. VK would have accepted it: the id is well formed, the
     * token is valid, the call succeeds, and the post lands on a stranger's wall
     * with `from_group` correctly omitted because there was no minus sign left
     * to notice. Nothing anywhere would have said so. `club`, `public` and
     * `event` are all community prefixes and all mean a negative id; `id` is the
     * only one that means a person.
     *
     * A bare number with no prefix is left exactly as typed, sign and all,
     * because that is somebody who already knows the convention and a guess
     * would be more likely to be wrong than the value.
     *
     * @throws PublishFailed
     */
    private function owner(SocialAccount $account): string
    {
        $raw = trim($this->require($account, 'owner_id'));
        $lower = strtolower($raw);

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($digits === '' || $digits === '0') {
            throw PublishFailed::rejected(
                $this->network(),
                'the wall owner ID “'.$raw.'” is not a number — it is your user ID for your own wall, '
                .'or the community ID with a minus sign in front of it for a community wall',
            );
        }

        $community = str_starts_with($lower, 'club')
            || str_starts_with($lower, 'public')
            || str_starts_with($lower, 'event')
            || str_starts_with($raw, '-');

        return ($community ? '-' : '').$digits;
    }

    /**
     * The community's own id, positive, or null for a personal wall.
     *
     * See the class docblock: the upload calls want it without the minus sign
     * that the post itself requires, and mixing the two fails silently.
     */
    private function groupId(string $owner): ?string
    {
        return str_starts_with($owner, '-') ? substr($owner, 1) : null;
    }
}
