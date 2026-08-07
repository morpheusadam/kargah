<?php

namespace Modules\Mailbox\Services;

use Illuminate\Support\Facades\DB;
use Modules\Mailbox\Models\Email;
use Modules\Mailbox\Models\EmailAttachment;
use Modules\Mailbox\Models\EmailThread;
use Modules\Mailbox\Models\MailAccount;
use Modules\Mailbox\Services\Imap\RemoteMessage;

/**
 * The one place a received message becomes rows.
 *
 * There are two ways mail reaches Kargah — `SyncMailAccount` pulls it from an
 * IMAP server, `InboundMailController` is handed it by a Cloudflare Email
 * Worker — and exactly one way it is written down. Threading, deduplication and
 * attachment metadata are decisions about what a message *is*, not about how it
 * arrived, and a second copy of them would drift: the copy the tests exercise
 * would stay right and the other would quietly stop threading replies.
 *
 * Everything here rests on one property, which callers may not work around:
 * **`emails.message_id` is unique**, and every write is keyed on it. Storing the
 * same message twice finds the existing row instead of inserting a second one.
 * That is what makes an IMAP window safe to re-run after a kill, and it is also
 * what makes the Worker safe to retry — a sending server that never sees our
 * 200 will deliver the same message again, and it must not appear twice.
 */
class MessageStore
{
    public function __construct(private readonly CustomerResolver $customers) {}

    /**
     * Store one message, and say which thread it landed in.
     *
     * Per message rather than per batch, on purpose. A transaction around a
     * whole window would make a kill lose the window's work, which is safe but
     * wasteful; per message, a kill costs at most the message in flight and
     * everything already committed stays committed.
     */
    public function store(MailAccount $account, RemoteMessage $message, string $folder): int
    {
        return DB::transaction(function () use ($account, $message, $folder): int {
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
                'folder' => $folder,
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
             * not be undone by the next tick.
             */
            $email = Email::withTrashed()->firstOrCreate(
                ['message_id' => $message->messageId],
                $attributes + ['is_read' => $message->seen, 'is_starred' => $message->flagged],
            );

            if (! $email->wasRecentlyCreated) {
                // A no-op when nothing changed: Eloquent skips the UPDATE and
                // leaves `updated_at` alone, which is what makes a second write
                // of the same message observably do nothing.
                $email->fill($attributes)->save();
            }

            $this->customers->resolveAndAttach($email);

            $this->storeAttachments($email, $message);

            return $threadId;
        });
    }

    /**
     * Recompute the aggregates on the threads a batch touched.
     *
     * Once per thread at the end, not once per message. A chunk of a hundred
     * messages in one conversation would otherwise recount that conversation a
     * hundred times, and the last count is the only one anybody sees.
     *
     * Recomputed rather than incremented, too: an increment run twice is wrong
     * twice, and both callers can be run twice over the same message.
     *
     * @param  list<int>  $threadIds
     */
    public function refreshThreads(array $threadIds): void
    {
        EmailThread::query()->whereKey($threadIds)->get()->each->refreshCounters();
    }

    /**
     * Find the conversation this message belongs to, or start one.
     *
     * Threading is by `Message-ID` and `In-Reply-To` rather than by subject,
     * because subjects are not identifiers — two people writing "Invoice" in
     * the same week are not in a conversation, and a reply that drops "Re:" is.
     *
     * The second lookup exists because mail does not arrive in order. A reply
     * can be stored before the message it answers, either because the parent is
     * in an older UID window or because it lives in another folder; when the
     * parent finally arrives it must join the thread its reply already started,
     * not open a second one.
     *
     * The third is what keeps a re-store inert: a message already held is
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
}
