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
class InstagramPublisher extends HttpPublisher implements RefreshesToken
{
    use MetaGraph;

    /**
     * Instagram's own host, which is not the one the rest of this family uses.
     *
     * 🔴 **A token minted by Instagram Login is refused by `graph.facebook.com`,
     * and the refusal does not mention the host.** Measured against the live
     * hosts on 6 August 2026 with a real credential for `@bineretcom`:
     *
     * ```
     * graph.instagram.com/v23.0/me?fields=id,username,account_type
     *   → {"id":"27848143088180376","username":"bineretcom","account_type":"BUSINESS"}
     * graph.facebook.com/v23.0/me?fields=id,username
     *   → {"error":{"message":"Invalid OAuth access token - Cannot parse access
     *      token","type":"OAuthException","code":190}}
     * ```
     *
     * Code 190 is the code `MetaGraph::graphRefusal()` turns into “the access
     * token has expired or been invalidated… exchange it for a long-lived one”.
     * That sentence is exactly right for a Page token and exactly wrong here: the
     * token is fine, the *host* is wrong, and anybody following the advice would
     * mint a second token and watch it fail identically. One constant prevents a
     * debugging session that has no way to end well.
     *
     * **Why Instagram Login rather than the Facebook-login variant**, which would
     * have kept this driver on `graph.facebook.com`: that route needs a Facebook
     * Page linked to the Instagram account, and Meta's documentation says this one
     * does not — *“This API setup does not require a Facebook Page to be linked to
     * the Instagram professional account.”* The owner has no Page and no Facebook
     * profile attached to the Instagram account at all, so the Page route was not
     * a preference to weigh, it was closed. `.data/meta-app.txt` records the app
     * this depends on and warns against switching it back.
     *
     * `ThreadsPublisher` reaches the same conclusion for `graph.threads.net` and
     * writes its own builder rather than overriding this one. Overriding is right
     * here and not there: every URL in this class already goes through
     * `graphUrl()`, so the override changes four call sites by changing none of
     * them, while Threads also disagrees about the *version* and would have needed
     * a second override to say so.
     */
    private const HOST = 'https://graph.instagram.com';

    public function network(): string
    {
        return Networks::INSTAGRAM;
    }

    /**
     * `https://graph.instagram.com/v23.0/<path>`.
     *
     * The version stays `MetaGraph::GRAPH_VERSION` on purpose — unlike Threads,
     * Instagram moves on Graph's clock, so there is one number to change when
     * Meta retires it rather than two that can drift apart.
     */
    protected function graphUrl(string $path): string
    {
        return self::HOST.'/'.self::GRAPH_VERSION.'/'.ltrim($path, '/');
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

    /**
     * Sixty more days, for the price of the token already stored.
     *
     * `ig_refresh_token` is the grant, and `instagram_business_basic` — already
     * granted, already needed by `verify()` — is the only permission it wants.
     * Meta refuses a token younger than twenty-four hours and one that has
     * already expired; `social:refresh-tokens` asks around the thirty-day mark,
     * so neither is reachable in ordinary use, and both come back as a plain
     * refusal recorded on the account rather than as anything this driver has to
     * anticipate.
     *
     * 🔴 **`ig_user_id` is not sent and must not be.** The edge identifies the
     * account from the token itself, and this is the one Instagram call in the
     * driver that does not name a user in its path — which is also why it is the
     * only one that keeps working when the stored id is wrong.
     */
    public function refreshToken(SocialAccount $account): RefreshedToken
    {
        return $this->refreshedGraphToken(
            self::HOST.'/refresh_access_token',
            'ig_refresh_token',
            $this->require($account, 'access_token'),
        );
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
