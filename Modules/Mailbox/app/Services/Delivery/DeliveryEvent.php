<?php

namespace Modules\Mailbox\Services\Delivery;

use Illuminate\Support\Carbon;
use Modules\Mailbox\Models\Suppression;

/**
 * Something a provider reported about a message after it was accepted.
 *
 * Five providers describe the same four events in five vocabularies —
 * `hard_bounce`, `Bounce`, `permanent`, `HardBounce`, `spamcomplaint` — so each
 * driver translates into the words used here and nothing downstream has to know
 * which provider a callback came from.
 *
 * Only the kinds that change what Kargah will do are modelled. Opens and clicks
 * are deliberately absent: nothing acts on them, they arrive in far greater
 * volume than everything else combined, and a table of them would be the
 * largest thing in the database within a month.
 */
final readonly class DeliveryEvent
{
    /** The address is dead. It goes on the shared suppression list and is never retried. */
    public const HARD_BOUNCE = 'hard_bounce';

    /** A temporary refusal — a full mailbox, a greylist. Recorded, never suppressed. */
    public const SOFT_BOUNCE = 'soft_bounce';

    /** The person pressed 'this is spam'. Worse than a bounce, and it suppresses. */
    public const COMPLAINT = 'complaint';

    /** The provider handled an unsubscribe itself, or a list-unsubscribe went to them rather than to us. */
    public const UNSUBSCRIBE = 'unsubscribe';

    /** The provider confirmed the mailbox accepted it. Recorded so a report can show a delivery rate. */
    public const DELIVERED = 'delivered';

    public function __construct(
        public string $kind,
        public string $email,
        public ?string $messageId = null,
        public ?string $detail = null,
        public ?Carbon $occurredAt = null,
    ) {}

    /** Whether this event should block the address on every provider. */
    public function suppresses(): bool
    {
        return in_array($this->kind, [self::HARD_BOUNCE, self::COMPLAINT, self::UNSUBSCRIBE], true);
    }

    /** The reason to write into `suppressions`, or null when this event does not suppress. */
    public function suppressionReason(): ?string
    {
        return match ($this->kind) {
            self::HARD_BOUNCE => Suppression::HARD_BOUNCE,
            self::COMPLAINT => Suppression::COMPLAINT,
            self::UNSUBSCRIBE => Suppression::UNSUBSCRIBE,
            default => null,
        };
    }
}
