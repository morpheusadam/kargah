<?php

namespace Modules\Platform\Services\Assistant\Tools;

use Modules\Mailbox\Contracts\EmailReader;
use Modules\Platform\Support\Scopes;

/**
 * How much unread mail there is.
 *
 * One integer, and the cheapest useful thing in the catalogue: a model asked
 * "how am I doing" can have this for the price of one contract call instead of
 * pulling a mailbox through a provider.
 *
 * "Unread" means what `⚡inbox.blade.php` means by it — every folder, no
 * exception — because that is the definition `EmailReader::unreadCount()`
 * exists not to contradict, and an assistant that reports a different number
 * from the one on screen is worse than one that reports none.
 */
class UnreadMailCount implements Tool
{
    use ReadsArguments;

    public const NAME = 'unread_mail_count';

    public function __construct(private readonly EmailReader $emails) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'How many email messages across the whole mailbox are unread.';
    }

    public function scope(): string
    {
        return Scopes::MAILBOX_READ;
    }

    public function parameters(): array
    {
        return $this->noParameters();
    }

    public function execute(array $arguments): array
    {
        return ['unread' => $this->emails->unreadCount()];
    }
}
