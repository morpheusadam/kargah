<?php

namespace Modules\Social\Services\Publishers;

use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * Threads, through the Threads API.
 *
 * 🔴 **A different host and a different token, despite the family resemblance.**
 * Threads lives on `graph.threads.net`, versioned `v1.0` on its own clock rather
 * than on the Graph version this codebase pins for Facebook and Instagram, and
 * it refuses a Page token and an Instagram token alike — even when the Threads
 * account is the same account the Instagram credential belongs to. Somebody who
 * has just connected Instagram successfully will reasonably try that token here
 * and get an error that says nothing about which token was wrong; the
 * catalogue's hint says so on the connect page and this docblock says so here.
 *
 * That is also why this driver builds its own URLs instead of using
 * `MetaGraph::graphUrl()`. One builder covering both hosts would need a flag,
 * and a flag is the shape of the abstraction `MetaGraph`'s docblock argues
 * against. What it does share is real: the error envelope, the 200-with-an-error
 * problem, and the container-then-publish dance.
 *
 * **Unlike Instagram, a text-only post is a real post here** — `media_type=TEXT`
 * with no picture at all — which makes Threads the only member of this family
 * that behaves like an ordinary microblog. It still goes through two calls
 * rather than one: create the container, then publish it. There is no one-shot
 * edge even for text.
 *
 * **The picture is still fetched, not sent.** A Threads container carries an
 * `image_url` exactly as Instagram's does, so an image post has the same
 * requirement — the install must be reachable from the internet — and the same
 * signed, expiring link answers it. The difference from `InstagramPublisher` is
 * that this is a per-post condition rather than a per-account one, so
 * `unavailableReason()` is deliberately left alone: a text post from a laptop
 * publishes perfectly, and refusing the account outright would take that away
 * to prevent a failure that only applies when a picture is attached.
 *
 * **No permalink is recorded**, for the same reason as Instagram: a
 * threads.net link needs a shortcode that only a second fetch would give, and
 * the id is what `post_targets` needs. The page renders an em dash.
 *
 * Deliberately does **not** implement `IngestsNotifications`.
 */
class ThreadsPublisher extends HttpPublisher implements RefreshesToken
{
    use MetaGraph;

    /**
     * Threads' own host, which is not Graph's.
     *
     * A Page or Instagram call sent here answers with an authentication error,
     * and a Threads call sent to `graph.facebook.com` answers with an unknown
     * edge. Neither error names the host, which is why both live in constants
     * rather than being assembled from a shared one.
     */
    private const HOST = 'https://graph.threads.net';

    /**
     * Versioned separately from `MetaGraph::GRAPH_VERSION` because it is a
     * separate clock — Threads has been at `v1.0` since the API opened, and it
     * does not move when Graph does. Pinned for the same reason all the same.
     */
    private const VERSION = 'v1.0';

    public function network(): string
    {
        return Networks::THREADS;
    }

    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        $user = $this->require($account, 'threads_user_id');
        $token = $this->require($account, 'access_token');
        $media = $this->acceptableMedia($media);
        $body = $this->bodyWithin($body, $media);

        $creation = match (true) {
            $media === [] => $this->textContainer($user, $token, $body),
            count($media) === 1 => $this->imageContainer($user, $token, $body, $media[0]),
            default => $this->carouselContainer($user, $token, $body, $media),
        };

        $id = $this->publishContainer($this->threadsUrl($user.'/threads_publish'), [
            'creation_id' => $creation,
            'access_token' => $token,
        ]);

        return new PublishedPost($id);
    }

    /**
     * `GET /me` on the Threads host names the account the token belongs to.
     *
     * It is also the cheapest way to catch the mistake this network invites: an
     * Instagram token sent here is refused, so a handle coming back at all is
     * evidence the person pasted a Threads token rather than the one that was
     * on their clipboard from the previous connect.
     */
    public function verify(SocialAccount $account): string
    {
        $response = $this->graphSend('get', $this->threadsUrl('me'), [
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
     * The same trade Instagram makes, under Threads' own name for it.
     *
     * `th_refresh_token`, not `ig_refresh_token`. The two hosts each refuse the
     * other's grant name, which is the same lesson this driver's class docblock
     * teaches about tokens: the accounts can be the same account and none of the
     * strings are interchangeable. `threads_basic` covers it, and that is the
     * permission `verify()` already spends.
     *
     * Built from `self::HOST` rather than `threadsUrl()` because the refresh edge
     * is deliberately unversioned — the argument is on
     * `MetaGraph::refreshedGraphToken()`, and it is the one place this family
     * leaves a version off on purpose.
     */
    public function refreshToken(SocialAccount $account): RefreshedToken
    {
        return $this->refreshedGraphToken(
            self::HOST.'/refresh_access_token',
            'th_refresh_token',
            $this->require($account, 'access_token'),
        );
    }

    /** No picture: the container is the text. */
    private function textContainer(string $user, string $token, string $body): string
    {
        return $this->createContainer($this->threadsUrl($user.'/threads'), [
            'media_type' => 'TEXT',
            'text' => $body,
            'access_token' => $token,
        ]);
    }

    /** One picture: the text rides on the same container. */
    private function imageContainer(string $user, string $token, string $body, MediaItem $item): string
    {
        return $this->createContainer($this->threadsUrl($user.'/threads'), [
            'media_type' => 'IMAGE',
            'image_url' => $this->fetchableUrl($item),
            'text' => $body,
            'access_token' => $token,
        ]);
    }

    /**
     * Several pictures: a container per picture, then one for the post.
     *
     * Structurally the same two-level shape as Instagram's carousel, and
     * deliberately written out again rather than shared with it. The two agree
     * on the shape and on nothing else — a different host, a different field
     * name for the copy (`text` against `caption`), a different child media type
     * and a different ceiling on the count — so the shared version would be a
     * method with four parameters that exist only to say which network is
     * calling it.
     *
     * @param  list<MediaItem>  $media
     */
    private function carouselContainer(string $user, string $token, string $body, array $media): string
    {
        $children = [];

        foreach ($media as $item) {
            $children[] = $this->createContainer($this->threadsUrl($user.'/threads'), [
                'media_type' => 'IMAGE',
                'image_url' => $this->fetchableUrl($item),
                // The literal string rather than a PHP true — see `MetaGraph`.
                'is_carousel_item' => 'true',
                'access_token' => $token,
            ]);
        }

        return $this->createContainer($this->threadsUrl($user.'/threads'), [
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $children),
            'text' => $body,
            'access_token' => $token,
        ]);
    }

    /** `https://graph.threads.net/v1.0/<path>`. */
    private function threadsUrl(string $path): string
    {
        return self::HOST.'/'.self::VERSION.'/'.ltrim($path, '/');
    }
}
