<?php

namespace Modules\Social\Services\Publishers;

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
 */
class PublishFailed extends \RuntimeException
{
    public static function unreachable(string $network, string $detail): self
    {
        return new self(ucfirst($network).' could not be reached, so the post was not sent: '.$detail);
    }

    public static function status(string $network, int $status, string $detail = ''): self
    {
        $message = ucfirst($network).' answered HTTP '.$status.' and the post was not published.';

        return new self($detail === '' ? $message : $message.' '.$detail);
    }

    public static function malformed(string $network, string $detail): self
    {
        return new self(ucfirst($network).' answered with something that is not a published post: '.$detail.'.');
    }

    public static function rejected(string $network, string $detail): self
    {
        return new self(ucfirst($network).' refused the post: '.$detail.'.');
    }
}
