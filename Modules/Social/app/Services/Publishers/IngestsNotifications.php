<?php

namespace Modules\Social\Services\Publishers;

use Modules\Social\Models\SocialAccount;

/**
 * A network whose API lets Kargah read notifications back.
 *
 * Separate from `Publisher` because it is genuinely optional and the honest
 * answer differs per network. Mastodon and Bluesky publish a notifications
 * endpoint any account holder can call. LinkedIn's needs partner access nobody
 * self-serving has, and Telegram's `getUpdates` consumes the update queue the
 * bot itself depends on — so those drivers do not implement this, and
 * `social:sync-notifications` skips them by name rather than showing an empty
 * feed that looks like nothing happened.
 */
interface IngestsNotifications
{
    /**
     * The most recent notifications for this account, newest first.
     *
     * One page, bounded by `$limit`. Ingestion is idempotent on
     * (social_account_id, remote_id), so an overlapping page costs nothing and
     * is preferred to a large one that risks missing the gap.
     *
     * @return list<InboundNotification>
     *
     * @throws PublishFailed when the network is unreachable or answers with
     *                       something that is not a notification list
     */
    public function notifications(SocialAccount $account, int $limit = 40): array;
}
