<?php

namespace Modules\Mailbox\Services\Delivery;

/**
 * One message, fully rendered, on its way to a provider.
 *
 * Built once per recipient by `MessageBuilder` and handed to whichever driver
 * the router picked. Drivers do not read the campaign, the recipient or the
 * provider's from-address — everything they need is here, which is what lets a
 * test assert exactly what was sent without standing up half the module.
 *
 * `messageId` is minted by Kargah rather than left to the provider. It is
 * derived from the recipient row, so re-running a chunk produces the same
 * identifier, and it is the string a bounce callback is matched against — a
 * provider-assigned id would not exist until after the send, which is the one
 * moment a killed worker cannot record anything.
 *
 * `headers` carries `List-Unsubscribe` and `List-Unsubscribe-Post`. Both are
 * required by Gmail and Yahoo for bulk senders since 2024, and a campaign
 * without them is a campaign that goes to spam regardless of how good the
 * sending domain is.
 */
final readonly class OutboundMessage
{
    /**
     * @param  array<string, string>  $headers
     * @param  list<array{path: string, name: string, mime: string|null}>  $attachments  Files already on disk.
     */
    public function __construct(
        public string $toEmail,
        public ?string $toName,
        public string $fromEmail,
        public ?string $fromName,
        public ?string $replyTo,
        public string $subject,
        public ?string $html,
        public ?string $text,
        public string $messageId,
        public array $headers = [],
        public array $attachments = [],
    ) {}

    /** The address as a mail client shows it, for a log line or a fake's record. */
    public function to(): string
    {
        return $this->toName === null || $this->toName === ''
            ? $this->toEmail
            : $this->toName.' <'.$this->toEmail.'>';
    }
}
