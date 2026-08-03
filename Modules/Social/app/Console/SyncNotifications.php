<?php

namespace Modules\Social\Console;

use Illuminate\Console\Command;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Models\SocialNotification;
use Modules\Social\Services\Publishers\InboundNotification;
use Modules\Social\Services\Publishers\PublishFailed;
use Modules\Social\Services\Publishing;
use Modules\Social\Support\Networks;

/**
 * Bring back what happened on the networks that will say.
 *
 * Only two of the four can. Mastodon and Bluesky publish a notifications
 * endpoint any account holder may call; LinkedIn's needs partner access nobody
 * self-serving has, and Telegram's `getUpdates` would consume the update queue
 * the bot itself depends on. Those two are skipped by name rather than left to
 * show an empty feed that reads as 'nothing happened'.
 *
 * Running it twice leaves the same rows: every write is an `updateOrCreate` on
 * (social_account_id, remote_id), which is the unique index the migration
 * added for exactly this. That property is what makes it safe on cron, where a
 * missed run and a doubled run are both normal.
 *
 * `is_read` is never written here. It belongs to the person reading the feed,
 * and a sync that reset it would undo their afternoon.
 */
class SyncNotifications extends Command
{
    protected $signature = 'social:sync-notifications {--limit= : How many notifications to ask each network for}';

    protected $description = 'Read notifications back from the networks whose API allows it';

    public function handle(Publishing $publishing): int
    {
        $limit = (int) ($this->option('limit') ?? config('social.notification_batch', 40));

        $accounts = SocialAccount::query()->active()->inReadingOrder()->get();

        if ($accounts->isEmpty()) {
            $this->components->info('No accounts are connected.');

            return self::SUCCESS;
        }

        $written = 0;
        $failed = [];

        foreach ($accounts as $account) {
            $ingester = $publishing->ingesterFor($account->network);

            if ($ingester === null) {
                $this->components->info(
                    $account->label().' has no notifications API Kargah can use, so '.$account->handle.' was skipped.',
                );

                continue;
            }

            // Absent credentials are the ordinary state of a fresh install.
            // Said out loud and moved past, so one unconfigured account does
            // not cost the owner the feed from the ones that are configured.
            if ($reason = $ingester->unavailableReason($account)) {
                $this->components->warn($account->handle.' was skipped. '.$reason);

                continue;
            }

            try {
                $items = $ingester->notifications($account, max(1, $limit));
            } catch (PublishFailed $e) {
                $failed[] = $account->handle;

                $account->forceFill(['last_checked_at' => now(), 'last_error' => $e->getMessage()])->save();

                $this->components->warn($e->getMessage());

                continue;
            }

            $written += $this->store($account, $items);

            $account->forceFill(['last_checked_at' => now(), 'last_error' => null])->save();
        }

        $summary = 'Recorded '.$written.' '.str('notification')->plural($written).'.';

        if ($failed !== []) {
            // Non-zero so cron, and whoever reads its mail, sees that a network
            // is down. What was read is already committed — the exit code
            // reports the gap, it does not undo the rest.
            $this->components->warn($summary.' '.implode(' and ', $failed).' failed and will be retried on the next run.');

            return self::FAILURE;
        }

        $this->components->info($summary);

        return self::SUCCESS;
    }

    /**
     * Write a page of notifications, keeping what the reader has already done.
     *
     * @param  list<InboundNotification>  $items
     * @return int how many rows this account contributed
     */
    private function store(SocialAccount $account, array $items): int
    {
        foreach ($items as $item) {
            SocialNotification::query()->updateOrCreate(
                [
                    'social_account_id' => $account->id,
                    'remote_id' => $item->remoteId,
                ],
                [
                    'kind' => $item->kind,
                    'actor_handle' => $item->actorHandle,
                    'excerpt' => $item->excerpt,
                    'url' => $item->url,
                    'occurred_at' => $item->occurredAt,
                ],
            );
        }

        if ($items !== []) {
            $this->components->info(
                Networks::label($account->network).' '.$account->handle.': '
                .count($items).' '.str('notification')->plural(count($items)).'.',
            );
        }

        return count($items);
    }
}
