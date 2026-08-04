<?php

namespace Modules\Social\Services\Publishers;

use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * Instagram, through the Content Publishing API on a Business or Creator account.
 *
 * 🔴 **Instagram will not take image bytes, and that is not a limitation Kargah
 * can work around.** The container call takes an `image_url` and Meta's own
 * servers go and fetch it. There is no multipart edge, no upload session and no
 * base64 field — the picture has to be somewhere on the public internet at the
 * moment the call is made, or the post cannot happen at all. That single fact
 * is why `Modules\Data\Contracts\AttachmentService::publicUrl()` exists, and why
 * this driver is the only publisher in Kargah with a precondition about the
 * install rather than about the account.
 *
 * 🔴 **So Instagram does not work on a machine the internet cannot reach**, and
 * no amount of correct credentials changes that. On this development machine it
 * will not publish, and it should not: `unavailableReason()` says so in a
 * sentence, before the composer offers the account, because the alternative is
 * a Graph error about a media download failing that reads as “Instagram is
 * broken” rather than “this laptop has no public address”. Put Kargah on a
 * domain, or point APP_URL at a tunnel while developing, and the same code
 * publishes.
 *
 * **There is no text-only Instagram post.** Not one Kargah declines to make —
 * one the API does not have. A post with no pictures is refused here, by name,
 * before a single HTTP call, so the error on the target row says what is
 * missing rather than repeating whatever Graph makes of an empty container.
 *
 * **One picture and several pictures are different sequences, not one sequence
 * of different length.** A single image is: create a container carrying the
 * `image_url` and the caption, then publish it. A carousel of two to ten is:
 * create a container per child carrying `is_carousel_item=true` and **no**
 * caption, then create a parent with `media_type=CAROUSEL`, the children's ids
 * and the caption, then publish the parent. Putting the caption on a child is
 * the mistake worth naming — it is accepted, ignored, and the finished carousel
 * arrives with no caption at all.
 *
 * **JPEG and nothing else.** The catalogue enforces it before the composer will
 * attach a file, because Instagram's container refuses a PNG with an error that
 * names neither the file nor the reason.
 *
 * **No permalink is recorded.** The publish step answers with a media id, and
 * an instagram.com link needs a shortcode that is only obtainable by fetching
 * the media back with `fields=permalink` — one more round trip on every publish,
 * inside a budget shared with every other target on the post. The id is what
 * `post_targets` needs; the page renders an em dash where the link would be,
 * exactly as it does for a private Telegram chat.
 *
 * Deliberately does **not** implement `IngestsNotifications`: reading comments
 * or mentions needs permissions Kargah does not ask for.
 */
class InstagramPublisher extends HttpPublisher
{
    use MetaGraph;

    public function network(): string
    {
        return Networks::INSTAGRAM;
    }

    /**
     * Credentials first, then the one thing about the install that matters.
     *
     * The order is deliberate. A fresh install has no token, and that is the
     * more useful sentence of the two; only an account that is otherwise ready
     * gets told about the address. Nothing here has any way of knowing whether
     * Meta can genuinely reach this install — see
     * `MetaGraph::unreachableInstallReason()` — so this catches the case that is
     * certainly true and stays quiet about the case it cannot judge.
     */
    public function unavailableReason(SocialAccount $account): ?string
    {
        if (($reason = parent::unavailableReason($account)) !== null) {
            return $reason;
        }

        $unreachable = $this->unreachableInstallReason();

        return $unreachable === null
            ? null
            : $account->label().' cannot publish from this install: '.$unreachable.'.';
    }

    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        $user = $this->require($account, 'ig_user_id');
        $token = $this->require($account, 'access_token');
        $media = $this->acceptableMedia($media);

        if ($media === []) {
            // Before any request, because there is no request that could
            // succeed. Instagram has no text-only post; the API has no edge for
            // one.
            throw PublishFailed::rejected(
                $this->graphName(),
                'Instagram has no text-only post — every post needs at least one JPEG, so attach a picture '
                .'or take Instagram off this post',
            );
        }

        $body = $this->bodyWithin($body, $media);

        $creation = count($media) === 1
            ? $this->singleContainer($user, $token, $body, $media[0])
            : $this->carouselContainer($user, $token, $body, $media);

        $id = $this->publishContainer($this->graphUrl($user.'/media_publish'), [
            'creation_id' => $creation,
            'access_token' => $token,
        ]);

        return new PublishedPost($id);
    }

    /**
     * The account's own id and handle, and nothing that could post.
     *
     * The handle is the point: the id a person pastes is the
     * `instagram_business_account` id rather than anything they see in the app,
     * so echoing back the `@name` it belongs to is the only way to tell a
     * correct id from a plausible one.
     */
    public function verify(SocialAccount $account): string
    {
        $response = $this->graphSend('get', $this->graphUrl($this->require($account, 'ig_user_id')), [
            'fields' => 'id,username',
            'access_token' => $this->require($account, 'access_token'),
        ]);

        $username = $response['username'] ?? null;

        if (! is_string($username) || $username === '') {
            throw PublishFailed::malformed($this->graphName(), 'the token was accepted but no account came back');
        }

        return '@'.$username;
    }

    /** One image: the container carries the picture and the caption together. */
    private function singleContainer(string $user, string $token, string $body, MediaItem $item): string
    {
        return $this->createContainer($this->graphUrl($user.'/media'), [
            'image_url' => $this->fetchableUrl($item),
            'caption' => $body,
            'access_token' => $token,
        ]);
    }

    /**
     * Two to ten images: a container per picture, then a container for the post.
     *
     * The children are created in the order the pictures were attached and named
     * in that order in `children`, because a carousel is a sequence and the
     * order is the author's. `PostMedia` already reverses
     * `AttachmentService::forTarget()` for the same reason.
     *
     * @param  list<MediaItem>  $media
     */
    private function carouselContainer(string $user, string $token, string $body, array $media): string
    {
        $children = [];

        foreach ($media as $item) {
            $children[] = $this->createContainer($this->graphUrl($user.'/media'), [
                'image_url' => $this->fetchableUrl($item),
                // The literal string rather than a PHP true — see `MetaGraph`.
                'is_carousel_item' => 'true',
                // No caption. A caption on a child is accepted and discarded,
                // and the carousel arrives without one.
                'access_token' => $token,
            ]);
        }

        return $this->createContainer($this->graphUrl($user.'/media'), [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $children),
            'caption' => $body,
            'access_token' => $token,
        ]);
    }
}
