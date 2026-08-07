<?php

namespace Modules\Mailbox\Services\Imap;

use Webklex\PHPIMAP\Folder;

/**
 * One open folder on a real server.
 *
 * Every message is flattened into a `RemoteMessage` by `WebklexHydrator` before
 * it leaves this class. Webklex's `Message` is lazy — reading a property can
 * open a socket — so a job holding one has no way to tell whether it is doing
 * network work, and a test holding one cannot exist without a server. Resolving
 * everything at the boundary is what keeps the rest of the sync honest about
 * where the network is.
 */
class WebklexMailbox implements MailboxConnection
{
    public function __construct(
        private readonly string $account,
        private readonly Folder $folder,
        private readonly WebklexHydrator $hydrator = new WebklexHydrator,
    ) {}

    public function state(): MailboxState
    {
        try {
            $status = $this->folder->examine();
        } catch (\Throwable $e) {
            throw MailboxUnavailable::failed($this->account, $e->getMessage());
        }

        // A server that answers EXAMINE without UIDVALIDITY is out of spec, and
        // inventing a value would let a cursor survive a rebuild it must not
        // survive. Refuse to sync rather than sync against a number we guessed.
        if (! isset($status['uidvalidity'], $status['uidnext'])) {
            throw MailboxUnavailable::failed(
                $this->account,
                'the folder reported no UIDVALIDITY or UIDNEXT, so no cursor can be trusted against it',
            );
        }

        return new MailboxState((int) $status['uidvalidity'], (int) $status['uidnext']);
    }

    public function fetch(int $fromUid, int $toUid): iterable
    {
        if ($toUid < $fromUid) {
            return [];
        }

        try {
            $messages = $this->folder->query()
                ->whereUid($fromUid.':'.$toUid)
                ->setFetchOrderAsc()
                ->setFetchBody(true)
                ->setFetchFlags(true)
                // One malformed message in the window must not cost the other
                // ninety-nine. Soft fail collects the error and returns the
                // rest; the window is marked done either way, because a message
                // this library cannot parse will not parse on the next tick.
                ->softFail()
                ->get();
        } catch (\Throwable $e) {
            throw MailboxUnavailable::failed($this->account, $e->getMessage());
        }

        $out = [];

        foreach ($messages as $message) {
            $out[] = $this->hydrator->hydrate($message, (int) $message->getUid());
        }

        usort($out, fn (RemoteMessage $a, RemoteMessage $b) => $a->uid <=> $b->uid);

        return $out;
    }
}
