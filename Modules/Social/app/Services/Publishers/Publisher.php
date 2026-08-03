<?php

namespace Modules\Social\Services\Publishers;

use Modules\Social\Models\SocialAccount;

/**
 * One network Kargah can send a post to.
 *
 * Publishers do not write. They return a remote id and url, and `PostPublisher`
 * records that against the target — so there is exactly one place in the
 * application that knows how a delivery reaches the table, and therefore
 * exactly one place that has to be right about claiming and idempotency.
 *
 * A publisher distinguishes two kinds of not-working, because they call for
 * different reactions. **Unavailable** means it was never going to run: no
 * token, no instance, an account switched off. That is a state of the install,
 * it will not fix itself by retrying, and it must be reported to the person
 * rather than thrown at the queue. **Failed** means the network was asked and
 * did not answer usefully, which is a transient fault worth another attempt.
 *
 * Both end up in `post_targets.error` and neither escapes the job, because a
 * job that dies takes the whole post with it and the next run would resend the
 * targets that already worked.
 */
interface Publisher
{
    /** The value stored in `social_accounts.network`, e.g. `mastodon`. */
    public function network(): string;

    /**
     * Why this account cannot publish at all, or null if it can.
     *
     * The string is written to `post_targets.error` and shown on the page, so
     * it says what is missing *and* what that costs — 'no token' on its own
     * leaves the reader to work out which post did not go out.
     */
    public function unavailableReason(SocialAccount $account): ?string;

    /**
     * Send one post to one account.
     *
     * One HTTP call or two, a short timeout, a couple of retries, then it gives
     * up. Nothing here loops or waits: this runs on shared hosting where a job
     * that will not finish is a job that gets the account suspended.
     *
     * **`$media` is where the drivers stop resembling each other.** Every one of
     * them takes the same list of images and does something structurally
     * different with it: Mastodon uploads each file and quotes the ids back in
     * the status, Bluesky uploads a blob and embeds the returned reference in
     * the record, LinkedIn registers an upload, PUTs to the URL that comes back
     * and then names the resulting asset URN, Telegram stops calling
     * `sendMessage` altogether and calls `sendPhoto` or `sendMediaGroup`
     * instead, and Discord keeps the same endpoint but changes the body from
     * JSON to multipart. There is no shared upload step to factor out, and an
     * abstraction that pretended otherwise would have five special cases inside
     * it — `HttpPublisher` therefore offers the *transports* (multipart, raw
     * bytes, a raw `PUT`) and each driver spends them its own way.
     *
     * The list is images and images only; anything else attached to the post is
     * filtered out before it reaches here. Video is deliberately absent: a
     * chunked, resumable upload can span minutes and this runs inside one PHP
     * request's `max_execution_time`. See `Modules\Social\Support\Networks`.
     *
     * @param  list<MediaItem>  $media  Images, in the order they were attached.
     *
     * @throws PublishFailed when the network is unreachable, errors, or answers
     *                       with something that is not a published post
     */
    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost;

    /**
     * Confirm the credentials work, without publishing anything.
     *
     * The connect page needs an answer before a post is riding on it, and 'send
     * a test post' is not that answer — it puts something on a public timeline
     * that the person then has to delete. Every one of these networks has a
     * cheap identity endpoint instead, so this asks that.
     *
     * @return string what the network says this account is, for the page to
     *                echo back — proof it reached the right account and not
     *                merely a reachable one
     *
     * @throws PublishFailed when the credentials are refused or the network is
     *                       unreachable
     */
    public function verify(SocialAccount $account): string;
}
