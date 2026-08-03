<?php

namespace Modules\Mailbox\Services\Imap;

use Modules\Mailbox\Models\MailAccount;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Folder;

/**
 * The real one, on `webklex/php-imap`.
 *
 * That library is a pure-PHP IMAP client rather than a wrapper around ext-imap,
 * which is the reason it was chosen: shared hosts frequently do not compile the
 * extension, and it is deprecated as of PHP 8.4, so a design that needs it is a
 * design that stops working on the target platform.
 *
 * This class does one thing — turn a stored account into an open folder, and
 * turn every way that can go wrong into one sentence worth storing. The reading
 * lives in `WebklexMailbox`.
 */
class WebklexConnector implements MailboxConnector
{
    public function open(MailAccount $account, string $folder): MailboxConnection
    {
        $client = $this->connect($account);

        try {
            $box = $client->getFolderByPath($folder);
        } catch (\Throwable $e) {
            throw MailboxUnavailable::failed($account->email, $e->getMessage());
        }

        if (! $box instanceof Folder) {
            throw MailboxUnavailable::noSuchFolder($account->email, $folder);
        }

        return new WebklexMailbox($account->email, $box);
    }

    /**
     * @throws MailboxUnavailable
     */
    private function connect(MailAccount $account): Client
    {
        try {
            $client = (new ClientManager)->make([
                'host' => $account->imap_host,
                'port' => $account->imap_port,
                'protocol' => 'imap',
                // The column stores `none` for a plaintext connection, which
                // webklex spells as boolean false.
                'encryption' => $account->imap_encryption === 'none' ? false : $account->imap_encryption,
                'validate_cert' => (bool) $account->imap_validate_cert,
                'username' => $account->imap_username,
                'password' => $account->password,
                // A hung socket on shared hosting is worse than a failed sync:
                // the sync retries in five minutes, whereas a process that will
                // not exit is what gets an account suspended.
                'timeout' => (int) config('mailbox.sync.timeout', 20),
            ]);

            $client->connect();
        } catch (AuthFailedException $e) {
            throw MailboxUnavailable::rejected($account->email, $e->getMessage());
        } catch (ConnectionFailedException $e) {
            throw MailboxUnavailable::unreachable($account->email, $e->getMessage());
        } catch (\Throwable $e) {
            throw MailboxUnavailable::failed($account->email, $e->getMessage());
        }

        return $client;
    }
}
