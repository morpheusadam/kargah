<?php

namespace Modules\Mailbox\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Mailbox\Jobs\SyncMailAccount;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Services\Imap\MailboxConnector;
use Modules\Mailbox\Services\Imap\MailboxState;
use Modules\Mailbox\Services\Imap\MailboxUnavailable;

/**
 * Queue the next slice of each mailbox. Never read one.
 *
 * The rule from `01-architecture.md` is that the scheduler dispatches and never
 * does the work, and this command is the shape of it: it asks each folder for
 * UIDNEXT — one EXAMINE, no message bodies, a few hundred bytes — and turns the
 * gap between that and the stored cursor into a small number of queued jobs.
 * A 2,000-message mailbox therefore costs this command the same as an empty
 * one, and the reading is spread across as many cron ticks as it takes.
 *
 * Three things are bounded, because on shared hosting anything unbounded
 * eventually meets `max_execution_time`: how many accounts are examined in a
 * tick, how many chunks are queued per account, and how many messages are in a
 * chunk. All three are config.
 *
 * The exit code is always zero. A mailbox with a wrong password is a fact about
 * that mailbox, recorded in `mail_accounts.last_error` where the owner will see
 * it; it is not a failure of the run, and a non-zero exit here would put a
 * daily error in the host's cron mail for a condition nobody can fix by
 * retrying. This is deliberately unlike `accounting:fetch-rates`, where a
 * failed provider means a figure is missing today and the exit code is the only
 * way anyone hears about it.
 */
class SyncImap extends Command
{
    protected $signature = 'mailbox:sync-imap
                            {--account= : Limit to one account, by id or address}';

    protected $description = 'Queue the next chunk of IMAP messages for each mail account due a sync';

    public function handle(): int
    {
        $accounts = MailAccount::query()
            ->dueForSync()
            ->when($this->option('account'), fn ($query, string $needle) => $query->where(
                fn ($match) => $match->where('id', $needle)->orWhere('email', $needle),
            ))
            ->limit(max(1, (int) config('mailbox.sync.accounts_per_tick', 5)))
            ->get();

        if ($accounts->isEmpty()) {
            $this->components->info('No mail accounts are due a sync.');

            return self::SUCCESS;
        }

        $queued = 0;

        foreach ($accounts as $account) {
            $queued += $this->queueFor($account);
        }

        $this->components->info(
            'Queued '.$queued.' '.str('chunk')->plural($queued).' across '
            .$accounts->count().' '.str('account')->plural($accounts->count()).'.',
        );

        return self::SUCCESS;
    }

    /** @return int the number of chunks queued for this account */
    private function queueFor(MailAccount $account): int
    {
        $folder = $account->default_folder ?: 'INBOX';

        try {
            $state = $this->connector()->open($account, $folder)->state();
        } catch (MailboxUnavailable $e) {
            $this->recordFailure($account, $e);

            return 0;
        }

        $this->reconcileValidity($account, $state);

        $cursor = (int) ($account->sync_cursor ?? 0);
        $highest = $state->highestUid();

        if ($highest <= $cursor) {
            // Up to date. Still touch the account so it rotates to the back of
            // `dueForSync` and a quiet mailbox does not hold a slot that a busy
            // one needs.
            $account->update(['last_synced_at' => now(), 'last_error' => null]);

            return 0;
        }

        $chunk = max(1, (int) config('mailbox.sync.chunk_size', 100));
        $chunks = max(1, (int) config('mailbox.sync.chunks_per_tick', 1));

        $from = $cursor + 1;
        $queued = 0;

        while ($queued < $chunks && $from <= $highest) {
            $to = min($from + $chunk - 1, $highest);

            SyncMailAccount::dispatch($account, $folder, $from, $to, $state->uidValidity);

            $from = $to + 1;
            $queued++;
        }

        $outstanding = $highest - $cursor;

        $this->components->info(
            $account->email.': queued '.$queued.' of '.$outstanding.' outstanding '
            .str('message')->plural($outstanding).' from '.$folder.'.',
        );

        return $queued;
    }

    /**
     * Keep the stored UIDVALIDITY in step with the server, resetting if it moved.
     *
     * UIDVALIDITY changing is the server saying that the UIDs it quoted before
     * now mean different messages — a mailbox restored from backup, a folder
     * deleted and recreated with the same name. A cursor expressed in UIDs is
     * then a number about nothing, and RFC 3501 requires the client to discard
     * what it cached. Kargah discards the cursor and reads the folder again from
     * the beginning, which is affordable precisely because `emails.message_id`
     * is unique: the second pass rewrites the rows it already has instead of
     * duplicating them, and only the stored UIDs actually change.
     *
     * A null stored value is a first sync, not a reset, and says nothing.
     */
    private function reconcileValidity(MailAccount $account, MailboxState $state): void
    {
        if ((int) $account->uid_validity === $state->uidValidity) {
            return;
        }

        if ($account->uid_validity !== null) {
            $message = $account->email.': the server changed UIDVALIDITY, so the cursor was discarded and the folder will be read again. No message will be stored twice.';

            $this->components->warn($message);
            Log::warning('mailbox:sync-imap: '.$message);
        }

        $account->update([
            'uid_validity' => $state->uidValidity,
            'sync_cursor' => null,
        ]);
    }

    /**
     * The same recording the job does, for the failure that happens one step
     * earlier — before there is a chunk to queue at all.
     *
     * `last_synced_at` moves even though nothing synced, so a mailbox that
     * cannot be opened rotates to the back of `dueForSync` rather than taking
     * the same slot from a working account every five minutes.
     */
    private function recordFailure(MailAccount $account, MailboxUnavailable $e): void
    {
        $this->components->warn($e->getMessage());
        Log::warning('mailbox:sync-imap: '.$e->getMessage());

        $account->update([
            'last_error' => Str::limit($e->getMessage(), 500),
            'last_synced_at' => now(),
        ]);
    }

    private function connector(): MailboxConnector
    {
        $connector = app(config('mailbox.sync.connector'));

        if (! $connector instanceof MailboxConnector) {
            throw new \RuntimeException('mailbox.sync.connector must name a class implementing MailboxConnector.');
        }

        return $connector;
    }
}
