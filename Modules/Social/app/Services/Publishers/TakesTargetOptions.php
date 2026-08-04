<?php

namespace Modules\Social\Services\Publishers;

use Modules\Social\Models\SocialAccount;

/**
 * A driver that needs more than a body and some pictures.
 *
 * Most destinations do not. Mastodon, Bluesky, LinkedIn, Telegram and Discord
 * each take a string and up to a handful of images, and everything else about
 * the post is theirs to decide. WordPress is the first destination in Kargah
 * where that is not true: a blog post has a title, categories, tags, a
 * draft-or-publish decision and a canonical link, and none of those are copy.
 *
 * **Why a second interface rather than a fourth parameter on `publish()`.**
 * Because `Publisher::publish()` is implemented by five classes that have no use
 * for options, and PHP will not let an implementation declare fewer parameters
 * than its interface — so adding one there means editing five drivers to accept
 * an argument they ignore, and every driver written afterwards inherits the same
 * dead parameter. This module already had the answer: `IngestsNotifications` is
 * a second interface that `PostPublisher`'s sibling asks about with `instanceof`
 * before deciding which call to make, and `Publishing::ingesterFor()` is the
 * shape that falls out of it. This is that pattern, for the write side.
 *
 * The consequence worth stating plainly: a driver that does *not* implement this
 * never sees the options, even when a target carries them. That is correct. A
 * post going to WordPress and to X carries a title for the first and the title
 * is not X's business — the composer wrote it against one target, and only that
 * target's driver is handed it.
 *
 * Implementations must still implement `Publisher::publish()`, and it must
 * behave — a driver reached through the plain path with no options is a
 * scheduled post published by cron before the composer learned to write them,
 * and it has to go out.
 */
interface TakesTargetOptions extends Publisher
{
    /**
     * Send one post to one account, with this target's own options.
     *
     * The options array is whatever the composer wrote to `post_targets.options`
     * and is **not validated by anything upstream** — it is a JSON column written
     * by one page and read by one driver, so the driver owns the contract and
     * the driver checks it. Treat every key as absent, of the wrong type, and
     * stale by a fortnight; a scheduled post published by cron is reading a
     * value somebody typed before the network's rules changed.
     *
     * @param  list<MediaItem>  $media  Images, in the order they were attached.
     * @param  array<string, mixed>  $options  This target's own options, possibly empty.
     *
     * @throws PublishFailed when the network is unreachable, errors, or answers
     *                       with something that is not a published post
     */
    public function publishWithOptions(
        SocialAccount $account,
        string $body,
        array $media = [],
        array $options = [],
    ): PublishedPost;
}
