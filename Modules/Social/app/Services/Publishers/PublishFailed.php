<?php

namespace Modules\Social\Services\Publishers;

use Modules\Social\Support\Networks;

/**
 * A network was asked to publish and did not.
 *
 * Thrown rather than returned so that a half-parsed response cannot be mistaken
 * for a published post. Every message names the network and says what actually
 * happened, because the only reader is whoever is looking at a red row on the
 * posts page asking why their Thursday morning post is still sitting there.
 *
 * Caught by `PostPublisher` and written to `post_targets.error`. It never
 * reaches the queue: a job that dies takes the whole post with it, and the
 * retry would resend the targets that already worked.
 *
 * **The name is the catalogue's, not `ucfirst()`'s.** These four used to open
 * with `ucfirst($network)`, which is right for exactly as long as every network
 * key is one lowercase word. It stopped being right the day `facebook_page`
 * arrived: "Facebook_page refused the post" is the sort of sentence that makes a
 * person think the failure is Kargah's rather than Facebook's. `Networks::label()`
 * already falls back to `ucfirst()` for a key the catalogue does not know, so a
 * row written by an older version still reads as something.
 */
class PublishFailed extends \RuntimeException
{
    public static function unreachable(string $network, string $detail): self
    {
        return new self(Networks::label($network).' could not be reached, so the post was not sent: '.$detail);
    }

    public static function status(string $network, int $status, string $detail = ''): self
    {
        $message = Networks::label($network).' answered HTTP '.$status.' and the post was not published.';

        return new self($detail === '' ? $message : $message.' '.$detail);
    }

    public static function malformed(string $network, string $detail): self
    {
        return new self(Networks::label($network).' answered with something that is not a published post: '.$detail.'.');
    }

    public static function rejected(string $network, string $detail): self
    {
        return new self(Networks::label($network).' refused the post: '.$detail.'.');
    }
}
