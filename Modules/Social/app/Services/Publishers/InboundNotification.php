<?php

namespace Modules\Social\Services\Publishers;

use Illuminate\Support\Carbon;
use Modules\Social\Models\SocialNotification;

/**
 * One notification, as a network described it, before it becomes a row.
 *
 * The driver's job is to turn whatever shape the network uses into these five
 * fields; the command's job is to write them. Keeping the translation here
 * means the ingestion command has no per-network branches at all, and adding a
 * network is one class rather than an edit in three places.
 *
 * `remoteId` carries the uniqueness that makes re-running safe, so it is
 * required and a driver that cannot produce one must skip the item rather than
 * invent one — a made-up id would write a new row on every run.
 */
final class InboundNotification
{
    public function __construct(
        public readonly string $remoteId,
        public readonly string $kind,
        public readonly ?string $actorHandle = null,
        public readonly ?string $excerpt = null,
        public readonly ?string $url = null,
        public readonly ?Carbon $occurredAt = null,
    ) {
        if (trim($remoteId) === '') {
            throw new \InvalidArgumentException('a notification needs a remote id to be idempotent');
        }
    }

    /**
     * Map a network's own word for the event onto Kargah's five kinds.
     *
     * Anything unrecognised becomes a mention rather than being dropped: a new
     * event type on the network's side should show up in the feed looking
     * slightly wrong, not disappear.
     */
    public static function kindFrom(string $native): string
    {
        return match (strtolower($native)) {
            'reply', 'comment' => SocialNotification::REPLY,
            'follow', 'follow_request' => SocialNotification::FOLLOW,
            'like', 'favourite', 'favorite' => SocialNotification::LIKE,
            'repost', 'reblog', 'quote' => SocialNotification::REPOST,
            default => SocialNotification::MENTION,
        };
    }

    /**
     * Plain text, short enough for a feed row.
     *
     * Mastodon sends HTML and Bluesky sends the post record's text, so both go
     * through here. Tags are stripped rather than escaped, because the excerpt
     * is rendered as text and a half-tag would show up as one.
     */
    public static function excerptFrom(?string $raw, int $characters = 240): ?string
    {
        if ($raw === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($raw)) ?? '');

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $characters ? mb_substr($text, 0, $characters - 1).'…' : $text;
    }
}
