<?php

namespace Modules\Mailbox\Services\Imap;

/**
 * What the server says about a folder, before a single message is fetched.
 *
 * These two numbers are the whole basis of chunking. `uidNext` bounds the work
 * outstanding without downloading anything, so the scheduled command can size
 * the queue from one cheap EXAMINE rather than by opening 2,000 messages.
 *
 * `uidValidity` is the server's promise that the UIDs it just quoted mean the
 * same thing they meant last time. When it changes — a mailbox rebuilt from
 * backup, a folder recreated with the same name — every stored UID becomes a
 * number about a different message, and a cursor expressed in UIDs is
 * meaningless. RFC 3501 requires clients to discard their cache at that point,
 * which for Kargah means resyncing from the beginning. That is cheap to do
 * safely, because `emails.message_id` is unique and a full resync therefore
 * rewrites rows instead of duplicating them.
 */
final readonly class MailboxState
{
    public function __construct(
        public int $uidValidity,
        public int $uidNext,
    ) {}

    /** The highest UID that can currently exist in the folder. */
    public function highestUid(): int
    {
        return max(0, $this->uidNext - 1);
    }
}
