<?php

namespace Modules\Mailbox\Services\Imap;

use Modules\Mailbox\Models\MailAccount;

/**
 * Turns a stored account into an open folder.
 *
 * The seam. Exactly one implementation talks to a real server, exactly one
 * serves messages from an array, and no other part of Mailbox knows which it
 * has. Every way an account can fail to open — wrong host, wrong password,
 * folder renamed, certificate expired — arrives at the caller as one exception
 * type carrying a sentence fit to store in `mail_accounts.last_error`.
 *
 * Which implementation is used is a config value rather than a container
 * binding, so an install can point Mailbox at a different IMAP library, or at a
 * fake while diagnosing a host, without editing code. See
 * `config('mailbox.sync.connector')`.
 */
interface MailboxConnector
{
    /**
     * Open one folder of one account.
     *
     * @throws MailboxUnavailable when the account cannot be reached, refuses
     *                            the credentials, or has no such folder
     */
    public function open(MailAccount $account, string $folder): MailboxConnection;
}
