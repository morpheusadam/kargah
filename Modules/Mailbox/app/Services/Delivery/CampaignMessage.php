<?php

namespace Modules\Mailbox\Services\Delivery;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * An `OutboundMessage` in the shape Laravel's mailer takes.
 *
 * A thin adapter and nothing more: every decision — what the body says, which
 * address replies come back to, which headers travel — was made by
 * `MessageBuilder` before this object existed. Keeping it that way is what
 * lets a test assert on the message without rendering a mailable, and what
 * stops five drivers each growing their own idea of what a campaign email
 * looks like.
 *
 * The body arrives pre-rendered, so there is no Blade view here. `htmlString`
 * takes the HTML as it stands; the plain-text alternative is attached through
 * the Symfony message, because `Content::$text` names a view and this body has
 * already been through one.
 */
class CampaignMessage extends Mailable
{
    public function __construct(public readonly OutboundMessage $message) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->message->fromEmail, $this->message->fromName ?? ''),
            to: [new Address($this->message->toEmail, $this->message->toName ?? '')],
            replyTo: $this->message->replyTo === null ? [] : [new Address($this->message->replyTo)],
            subject: $this->message->subject,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->message->html ?? '');
    }

    /**
     * The headers that make this a well-behaved bulk message.
     *
     * `messageId` is stripped of its angle brackets because Symfony adds them
     * back, and a doubled pair produces a header no bounce processor matches.
     * The rest — `List-Unsubscribe`, `List-Unsubscribe-Post`, `Precedence` —
     * come from the builder as plain text headers, which is the only way to set
     * a header Laravel has no first-class name for.
     */
    public function headers(): Headers
    {
        return new Headers(
            messageId: trim($this->message->messageId, '<>'),
            text: $this->message->headers,
        );
    }

    /**
     * Attach the plain-text alternative.
     *
     * Not a courtesy. A multipart message with only an HTML part is one of the
     * cheapest spam signals there is, and a campaign that skips it loses more
     * deliverability than any amount of SPF tuning wins back.
     */
    public function build(): static
    {
        if ($this->message->text !== null && $this->message->text !== '') {
            $this->withSymfonyMessage(fn (Email $email) => $email->text($this->message->text));
        }

        // Only a one-to-one message ever carries these. A campaign attachment
        // is a way to multiply a mailbox's storage by the size of the list, and
        // a link is both smaller and measurable.
        foreach ($this->message->attachments as $file) {
            if (is_file($file['path'])) {
                $this->attach($file['path'], array_filter([
                    'as' => $file['name'],
                    'mime' => $file['mime'],
                ]));
            }
        }

        return $this;
    }
}
