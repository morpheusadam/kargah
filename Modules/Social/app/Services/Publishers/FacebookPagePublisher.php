<?php

namespace Modules\Social\Services\Publishers;

use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * A Facebook Page, through the Graph API's `/feed` edge.
 *
 * The only one of Kargah's three Meta drivers that can post text on its own, and
 * the only one that can carry image bytes. Everything shared with the other two
 * — the version segment, the error envelope, the fact that Graph answers HTTP
 * 200 with an `error` key — lives in `MetaGraph`.
 *
 * **A Page token, not a user token.** The two are the same shape and only one of
 * them can publish; a user token produces a permission error that reads as if
 * the app were missing a review. The catalogue's hint says so on the connect
 * page, and `verify()` echoes back the name of whatever the token actually
 * belongs to so that the mistake is visible before a post rides on it.
 *
 * **Pictures: uploaded unpublished, then attached.** Each image is POSTed to the
 * Page's `/photos` edge with `published=false`, which stores it and answers with
 * a photo id but puts nothing on the timeline. The `/feed` call then names those
 * ids in `attached_media`, and Facebook composes them into a single story. It is
 * two steps rather than one for a real reason: posting the photos directly would
 * produce one post per picture, and a four-image update would arrive on the Page
 * as four separate posts.
 *
 * The consequence worth writing down is that a failure between the two steps
 * leaves orphaned unpublished photos in the Page's library. They are invisible
 * on the timeline and Facebook clears them itself, so nothing here tries to
 * delete them — a cleanup call after a failed publish would be a second thing
 * that can fail, on the path that is already failing.
 *
 * **`attached_media` is sent as a JSON string in one field**, not as Laravel's
 * nested form encoding (`attached_media[0][media_fbid]=…`). Graph accepts both.
 * The JSON string is chosen because it is exactly what
 * `curl -d 'attached_media=[{"media_fbid":"1"}]'` produces, which is the shape
 * every example Meta publishes uses, and reproducing a refused call with curl is
 * the first thing anybody does when a post is rejected. The nested form keys
 * work too and read badly in a log. `TelegramPublisher` makes the same choice
 * for `sendMediaGroup`'s `media`, for the same reason.
 *
 * Deliberately does **not** implement `IngestsNotifications`. Reading a Page's
 * notifications needs permissions Kargah does not ask for, and asking for them
 * to fill a panel nobody requested would widen what the connect page has to
 * promise. `social:sync-notifications` skips this network by name.
 */
class FacebookPagePublisher extends HttpPublisher
{
    use MetaGraph;

    public function network(): string
    {
        return Networks::FACEBOOK_PAGE;
    }

    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        $page = $this->require($account, 'page_id');
        $token = $this->require($account, 'page_access_token');
        $media = $this->acceptableMedia($media);
        $body = $this->bodyWithin($body, $media);

        $fields = [
            'message' => $body,
            'access_token' => $token,
        ];

        $fbids = [];

        foreach ($media as $item) {
            $fbids[] = $this->uploadPhoto($page, $token, $item);
        }

        if ($fbids !== []) {
            $fields['attached_media'] = (string) json_encode(
                array_map(static fn (string $id): array => ['media_fbid' => $id], $fbids),
            );
        }

        $id = $this->graphId(
            $this->graphSend('post', $this->graphUrl($page.'/feed'), $fields),
            'post id',
        );

        return new PublishedPost($id, 'https://www.facebook.com/'.$id);
    }

    /**
     * `GET /me` against a Page token names the Page, not the person.
     *
     * That asymmetry is the whole value of this check: pasted a user token by
     * mistake and this comes back with somebody's own name, which is visible on
     * the connect page long before a post fails on it. It publishes nothing and
     * reads nothing but the identity fields.
     */
    public function verify(SocialAccount $account): string
    {
        $response = $this->graphSend('get', $this->graphUrl('me'), [
            'fields' => 'id,name,username',
            'access_token' => $this->require($account, 'page_access_token'),
        ]);

        $name = $response['name'] ?? null;
        $username = $response['username'] ?? null;

        if (! is_string($name) || $name === '') {
            throw PublishFailed::malformed($this->graphName(), 'the token was accepted but no Page came back');
        }

        return is_string($username) && $username !== '' ? $name.' (@'.$username.')' : $name;
    }

    /**
     * One picture into the Page's library, published to nowhere.
     *
     * The part carrying the bytes is named `source`, which is the Photo API's
     * own name for it and not a convention shared with anything else in this
     * module — Telegram calls it `photo`, Discord `files[0]`, Mastodon `file`.
     * A part named anything else is refused with a message about a missing
     * required parameter rather than about the part.
     *
     * @return string the photo's id, for `attached_media`
     *
     * @throws PublishFailed
     */
    private function uploadPhoto(string $page, string $token, MediaItem $item): string
    {
        $response = $this->graphUpload(
            $this->graphUrl($page.'/photos'),
            ['source' => [$item->filename(), $item->contents(), $item->mime]],
            [
                // The literal string, not a PHP false — see `MetaGraph`. A part
                // that encoded to an empty string would read as absent, and the
                // default is published, so the picture would appear on the
                // timeline as its own post before the real one was even made.
                'published' => 'false',
                'access_token' => $token,
            ],
        );

        return $this->graphId($response, 'photo id for “'.$item->name.'”');
    }
}
