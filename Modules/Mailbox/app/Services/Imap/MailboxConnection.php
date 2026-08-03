<?php

namespace Modules\Mailbox\Services\Imap;

/**
 * One folder of one account, open and ready to be asked for a bounded slice.
 *
 * Deliberately two methods. Everything the sync needs to decide *how much* work
 * there is comes from `state()`, which costs one EXAMINE; everything that
 * actually costs bandwidth comes from `fetch()`, which will never return more
 * than it was asked for. Keeping those apart is what lets the scheduled command
 * size the queue without doing the work, which is the rule in
 * `01-architecture.md` that the whole shared-hosting design depends on.
 *
 * There is no `all()`, and there is no iterator. An unbounded read is the one
 * thing this interface must make impossible to write by accident.
 */
interface MailboxConnection
{
    /**
     * The folder's UIDVALIDITY and UIDNEXT.
     *
     * @throws MailboxUnavailable
     */
    public function state(): MailboxState;

    /**
     * Fetch the messages whose UID falls in `[$fromUid, $toUid]`, oldest first.
     *
     * Gaps are normal and not an error: UIDs are not contiguous, because
     * deleting a message removes its UID for ever. A window of a hundred UIDs
     * returning eleven messages means eighty-nine were deleted, and the caller
     * must still treat the window as done.
     *
     * Iterable rather than array so an implementation may stream. A hundred
     * messages with bodies is tens of megabytes, and a shared host's
     * `memory_limit` is often 128 MB; holding the window open one message at a
     * time is the difference between a chunk that fits and one that does not.
     *
     * @return iterable<int, RemoteMessage> ordered by UID ascending, never
     *                                      beyond `[$fromUid, $toUid]`
     *
     * @throws MailboxUnavailable
     */
    public function fetch(int $fromUid, int $toUid): iterable;
}
