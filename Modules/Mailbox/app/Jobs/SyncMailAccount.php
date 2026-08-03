<?php

namespace Modules\Mailbox\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Mailbox\Models\Email;
use Modules\Mailbox\Models\EmailAttachment;
use Modules\Mailbox\Models\EmailThread;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Services\CustomerResolver;
use Modules\Mailbox\Services\Imap\MailboxConnector;
use Modules\Mailbox\Services\Imap\MailboxUnavailable;
use Modules\Mailbox\Services\Imap\RemoteMessage;

/**
 * Store one window of UIDs from one folder.
 *
 * This is the only thing in Mailbox that writes a received message, and it is
 * built on the assumption that it will be killed. Shared hosting kills
 * processes: the host's execution limit, a cron overlap, a memory cap, a reboot.
 * So the job holds no state between messages that a kill could lose, and it
 * derives everything it can rather than counting.
 *
 * Three properties make that work, in the order they matter:
 *
 * 1. **`emails.message_id` is unique**, and every write is keyed on it. A
 *    message that is already stored is found, not inserted, so re-running a
 *    window that half completed produces the same rows as running it once. This
 *    is the guarantee. Everything else is an optimisation on top of it.
 * 2. **The cursor is advanced once, at the end, and only forwards.** A job that
 *    dies partway leaves rows committed and the cursor where it was, so the next
 *    tick redoes the window — which point 1 makes free. A conditional update
 *    means a late job can never rewind a cursor a later one already moved, so no
 *    lock is needed to keep two chunks of the same account apart.
 * 3. **Thread aggregates are recomputed, never incremented.** An increment run
 *    twice is wrong twice; a count taken from the messages themselves is right
 *    however many times it runs.
 *
 * A window is closed even when it yields nothing. UIDs are not contiguous —
 * deleting a message removes its UID for ever — so a window of a hundred that
 * returns eleven messages is a normal window, and refusing to advance past it
 * would stall the account on a gap for ever.
 */
class SyncMailAccount implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt, because cron is the retry.
     *
     * The scheduled command comes round every five minutes and recomputes the
     * window from the cursor, so a chunk that failed is picked up again anyway.
     * Retrying inside the worker instead would mean hammering a mail server
     * that has just refused us three times in the same fifty seconds, which is
     * how an IP ends up rate limited.
     */
    public int $tries = 1;

    /**
     * An account deleted between dispatch and running is not an error worth a
     * failed job — it is someone removing a mailbox while the queue was busy.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public MailAccount $account,
        public string $folder,
        public int $fromUid,
        public int $toUid,
        public int $uidValidity,
    ) {}

    public function handle(CustomerResolver $customers): void
    {
        $account = $this->account;

        if (! $account->is_active) {
            return;
        }

        /*
         * The window was computed against a UIDVALIDITY. If the account has
         * since been reset to a different one — the mailbox was rebuilt between
         * this job being queued and being run — then `fromUid` and `toUid` name
         * different messages than they did, and running them would file the
         * wrong UIDs against the right message ids. Drop it; the next tick
         * recomputes from a cursor that has already been cleared.
         */
        if ((int) $account->uid_validity !== $this->uidValidity) {
            Log::info('mailbox: dropped a chunk for '.$account->email.' because UIDVALIDITY changed under it.');

            return;
        }

        try {
            $connection = $this->connector()->open($account, $this->folder);

            $threads = [];

            foreach ($connection->fetch($this->fromUid, $this->toUid) as $message) {
                $threads[$this->store($account, $message, $customers)] = true;
            }
        } catch (MailboxUnavailable $e) {
            $this->recordFailure($account, $e);

            return;
        }

        /*
         * Once per thread at the end of the window, not once per message.
         * A chunk of a hundred messages in one conversation would otherwise
         * recount that conversation a hundred times, and the last count is the
         * only one anybody sees.
         */
        EmailThread::query()->whereKey(array_keys($threads))->get()->each->refreshCounters();

        $this->advance($account);
    }

    /**
     * Store one message, and say which thread it landed in.
     *
     * Per message rather than per chunk, on purpose. A transaction around the
     * whole window would make a kill lose the window's work, which is safe but
     * wasteful; per message, a kill costs at most the message in flight and
     * everything already committed stays committed. That is also the state the
     * restart test reproduces.
     */
    private function store(MailAccount $account, RemoteMessage $message, CustomerResolver $customers): int
    {
        return DB::transaction(function () use ($account, $message, $customers): int {
            $threadId = $this->threadFor($message);

            $attributes = [
                'mail_account_id' => $account->getKey(),
                'email_thread_id' => $threadId,
                'in_reply_to' => $message->inReplyTo,
                'uid' => $message->uid,
                'subject' => $message->subject,
                'from_name' => $message->fromName,
                'from_email' => $message->fromEmail,
                'to' => $message->to,
                'cc' => $message->cc,
                'body_text' => $message->textBody,
                'body_html' => $message->htmlBody,
                'has_attachments' => $message->attachments !== [],
                'folder' => $this->folder,
                'received_at' => $message->receivedAt,
            ];

            /*
             * `withTrashed()`, because a message the owner deleted locally is
             * still on the server and will be offered again. Without it the
             * lookup misses the soft-deleted row and the insert hits the unique
             * index instead. The row is updated in place and left deleted —
             * re-syncing must not resurrect something someone threw away.
             *
             * `is_read` and `is_starred` are set only at creation. After that
             * they are local state: marking a message read in the inbox must
             * not be undone by the next tick. Writing flags back to the server
             * is phase 5's problem.
             */
            $email = Email::withTrashed()->firstOrCreate(
                ['message_id' => $message->messageId],
                $attributes + ['is_read' => $message->seen, 'is_starred' => $message->flagged],
            );

            if (! $email->wasRecentlyCreated) {
                // A no-op when nothing changed: Eloquent skips the UPDATE and
                // leaves `updated_at` alone, which is what makes a second run
                // of the same chunk observably do nothing.
                $email->fill($attributes)->save();
            }

            $customers->resolveAndAttach($email);

            $this->storeAttachments($email, $message);

            return $threadId;
        });
    }

    /**
     * Find the conversation this message belongs to, or start one.
     *
     * Threading is by `Message-ID` and `In-Reply-To` rather than by subject,
     * because subjects are not identifiers — two people writing "Invoice" in
     * the same week are not in a conversation, and a reply that drops "Re:" is.
     *
     * The second lookup exists because mail does not arrive in order. A reply
     * can be synced before the message it answers, either because the parent is
     * in an older UID window or because it lives in another folder; when the
     * parent finally arrives it must join the thread its reply already started,
     * not open a second one.
     *
     * The third is what keeps a re-sync inert: a message already stored is
     * returned to its own thread rather than given a new one.
     */
    private function threadFor(RemoteMessage $message): int
    {
        if ($message->inReplyTo !== null) {
            $parent = Email::withTrashed()->where('message_id', $message->inReplyTo)->value('email_thread_id');

            if ($parent !== null) {
                return (int) $parent;
            }
        }

        $child = Email::withTrashed()->where('in_reply_to', $message->messageId)->value('email_thread_id');

        if ($child !== null) {
            return (int) $child;
        }

        $own = Email::withTrashed()->where('message_id', $message->messageId)->value('email_thread_id');

        if ($own !== null) {
            return (int) $own;
        }

        return EmailThread::create([
            'subject' => $message->subject,
            'participants' => [],
            'message_count' => 0,
            'last_message_at' => $message->receivedAt,
        ])->getKey();
    }

    /**
     * Metadata only — Data owns the disk and phase 6 fetches the bytes.
     *
     * Keyed on the part number as well as the filename because a message can
     * carry two files of the same name in different parts, and because the part
     * number is what phase 6 will use to go and get exactly this one.
     */
    private function storeAttachments(Email $email, RemoteMessage $message): void
    {
        foreach ($message->attachments as $attachment) {
            EmailAttachment::updateOrCreate(
                [
                    'email_id' => $email->getKey(),
                    'filename' => $attachment->filename,
                    'part_number' => $attachment->partNumber,
                ],
                [
                    'mime' => $attachment->mime,
                    'size_bytes' => $attachment->sizeBytes,
                    'content_id' => $attachment->contentId,
                ],
            );
        }
    }

    /**
     * Record where the sync got to, forwards only.
     *
     * Conditional rather than read-modify-write: two chunks of the same account
     * running at once would otherwise be able to write the lower cursor last
     * and lose the higher one's progress. Expressed as one statement, the
     * database settles it and no lock is needed. The UIDVALIDITY condition
     * stops a job finishing after a reset from writing a cursor that belongs to
     * a mailbox that no longer exists.
     */
    private function advance(MailAccount $account): void
    {
        MailAccount::query()
            ->whereKey($account->getKey())
            ->where('uid_validity', $this->uidValidity)
            ->where(fn ($query) => $query->whereNull('sync_cursor')->orWhere('sync_cursor', '<', $this->toUid))
            ->update(['sync_cursor' => $this->toUid]);

        MailAccount::query()
            ->whereKey($account->getKey())
            ->update(['last_synced_at' => now(), 'last_error' => null]);
    }

    /**
     * Write the failure where the owner will see it, and stop.
     *
     * Not rethrown. One mailbox with a wrong password must not fill the failed
     * jobs table every five minutes, and it must not stop the account synced
     * after it. `last_synced_at` moves even on failure, so a permanently broken
     * account goes to the back of the `dueForSync` queue instead of taking the
     * same slot every tick.
     */
    private function recordFailure(MailAccount $account, MailboxUnavailable $e): void
    {
        Log::warning('mailbox:sync-imap: '.$e->getMessage());

        MailAccount::query()->whereKey($account->getKey())->update([
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
