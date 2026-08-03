<?php

namespace Modules\Mailbox\Services\Imap;

/**
 * A mailbox was asked for messages and did not produce any.
 *
 * Thrown rather than returned so a half-opened connection cannot be mistaken
 * for an empty inbox — an empty result and a refused connection must never
 * advance the cursor in the same way.
 *
 * The message is written verbatim to `mail_accounts.last_error` and shown on
 * the account screen, so it names the account and says what actually happened.
 * The owner reading it is usually trying to work out whether they typed the
 * password wrongly or the host is down, and those need different answers.
 */
class MailboxUnavailable extends \RuntimeException
{
    public static function unreachable(string $account, string $detail): self
    {
        return new self($account.' could not be reached: '.$detail);
    }

    public static function rejected(string $account, string $detail): self
    {
        return new self($account.' refused the credentials: '.$detail);
    }

    public static function noSuchFolder(string $account, string $folder): self
    {
        return new self($account.' has no folder named '.$folder.'.');
    }

    public static function failed(string $account, string $detail): self
    {
        return new self($account.' answered, but not with messages: '.$detail);
    }
}
