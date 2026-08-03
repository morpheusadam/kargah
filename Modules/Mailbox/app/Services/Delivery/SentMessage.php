<?php

namespace Modules\Mailbox\Services\Delivery;

/**
 * What a provider hands back when it has accepted a message.
 *
 * The message id is required, and it is normally the one Kargah put in the
 * headers rather than one the provider invented — that is deliberate. The
 * bounce callback has to be matched to a recipient row, and matching on an
 * identifier that only exists after a successful send would leave every message
 * whose worker died unmatchable.
 *
 * A provider that returns its own id (Postmark and SES both do) may report it
 * as `providerMessageId`, which is what a support ticket to that provider needs
 * and what their callbacks quote. Nullable because two of the five never say.
 */
final readonly class SentMessage
{
    public function __construct(
        public string $messageId,
        public ?string $providerMessageId = null,
    ) {
        if (trim($messageId) === '') {
            throw new \InvalidArgumentException('an accepted message needs a message id');
        }
    }
}
