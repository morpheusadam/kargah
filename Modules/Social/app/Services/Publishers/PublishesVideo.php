<?php

namespace Modules\Social\Services\Publishers;

use Modules\Social\Models\SocialAccount;

/**
 * A network whose post **is** a video.
 *
 * Separate from `Publisher::publish()` rather than a longer parameter list on
 * it, because the two are not the same operation wearing different clothes:
 *
 * - `publish()` takes copy and up to ten pictures, and every one of the fourteen
 *   drivers can answer it in a few seconds inside one request's budget. Its
 *   docblock says video is deliberately absent, and that sentence is still true
 *   of all of them.
 * - this takes **one** video and no pictures, and cannot promise to finish
 *   quickly at all — the time it takes is the size of the file over the
 *   install's upstream, which is minutes rather than seconds.
 *
 * 🔴 **A driver implementing this does not also implement a useful `publish()`.**
 * YouTube has no text post and no photo post; `videos.insert` is the only way to
 * put anything on a channel. So `YouTubePublisher::publish()` exists to satisfy
 * `Publisher` and refuses by name, exactly as `InstagramPublisher::publish()`
 * refuses a post with no picture — the message says what is missing rather than
 * letting Google explain it.
 *
 * `PostPublisher` chooses between the two by `instanceof`, the same shape it
 * already uses for `TakesTargetOptions` and that `Publishing` uses for
 * `IngestsNotifications` and `RefreshesToken`.
 */
interface PublishesVideo
{
    /**
     * Upload one video and publish it.
     *
     * **`$body` is the whole text of the post and this splits it**, because a
     * video has a title and a description where every other network has one
     * field. The first line becomes the title and the rest the description —
     * the same rule `RedditPublisher::derivedTitle()` already uses, so a person
     * composing for several networks learns it once.
     *
     * @param  array<string, mixed>  $options  the target's own options, if it carries any
     *
     * @throws PublishFailed when the network is unreachable, refuses the upload,
     *                       or answers with something that is not a published video
     */
    public function publishVideo(
        SocialAccount $account,
        string $body,
        VideoItem $video,
        array $options = [],
    ): PublishedPost;
}
