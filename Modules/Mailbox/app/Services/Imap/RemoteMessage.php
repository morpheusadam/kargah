<?php

namespace Modules\Mailbox\Services\Imap;

use Illuminate\Support\Carbon;

/**
 * One message as it arrived from the server, with nothing of the library left
 * on it.
 *
 * The point of this class is that `SyncMailAccount` never sees a
 * `Webklex\PHPIMAP\Message`. Webklex's message is lazy — reading a property can
 * open a socket — so a job holding one has no way to know whether it is doing
 * network work or not, and a test holding one cannot exist without a server.
 * Everything is resolved by the time it reaches here.
 *
 * `messageId` is required and non-empty because it is the unique key the whole
 * idempotency guarantee rests on. A message that genuinely carries no
 * Message-ID header gets a synthesised one from whoever built this object; that
 * is the connector's problem, not the job's.
 */
final readonly class RemoteMessage
{
    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<RemoteAttachment>  $attachments
     */
    public function __construct(
        public int $uid,
        public string $messageId,
        public ?string $inReplyTo = null,
        public ?string $subject = null,
        public ?string $fromName = null,
        public ?string $fromEmail = null,
        public array $to = [],
        public array $cc = [],
        public ?string $textBody = null,
        public ?string $htmlBody = null,
        public ?Carbon $receivedAt = null,
        public bool $seen = false,
        public bool $flagged = false,
        public array $attachments = [],
    ) {
        if (trim($this->messageId) === '') {
            throw new \InvalidArgumentException(
                'A remote message needs a message id; the unique index on emails.message_id is what makes a resync safe.',
            );
        }
    }
}
