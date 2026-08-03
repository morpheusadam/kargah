<?php

namespace Modules\Social\Services\Publishers;

/**
 * What a network hands back when it has accepted a post.
 *
 * The remote id is the part that matters and is required: it is how the target
 * proves it was sent, and how a later run recognises the post it already made.
 * A url is a courtesy some networks give and others do not — Telegram only when
 * the chat is public — so it is nullable rather than invented, and the page
 * renders an em dash rather than a link that goes nowhere.
 */
final class PublishedPost
{
    public function __construct(
        public readonly string $remoteId,
        public readonly ?string $remoteUrl = null,
    ) {
        if (trim($remoteId) === '') {
            throw new \InvalidArgumentException('a published post needs a remote id');
        }
    }
}
