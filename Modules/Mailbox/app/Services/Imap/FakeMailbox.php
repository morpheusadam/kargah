<?php

namespace Modules\Mailbox\Services\Imap;

/**
 * One folder of the fake, open.
 *
 * `fetch()` is a generator on purpose. It is what lets a test stop halfway
 * through a window and leave the database in the state a kill would leave it
 * in — some rows committed, the cursor untouched — which is the only way to
 * check that restarting produces no duplicates rather than assuming it.
 */
class FakeMailbox implements MailboxConnection
{
    /**
     * @param  list<RemoteMessage>  $messages  ordered by UID ascending
     */
    public function __construct(
        private readonly FakeConnector $connector,
        private readonly int $account,
        private readonly string $folder,
        private readonly array $messages,
        private readonly int $uidValidity,
    ) {}

    public function state(): MailboxState
    {
        $highest = 0;

        foreach ($this->messages as $message) {
            $highest = max($highest, $message->uid);
        }

        return new MailboxState($this->uidValidity, $highest + 1);
    }

    public function fetch(int $fromUid, int $toUid): iterable
    {
        $this->connector->windowOpened($this->account, $this->folder, $fromUid, $toUid);

        foreach ($this->messages as $message) {
            if ($message->uid < $fromUid || $message->uid > $toUid) {
                continue;
            }

            $this->connector->delivering();

            yield $message;
        }
    }
}
