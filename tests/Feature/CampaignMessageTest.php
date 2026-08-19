<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Modules\Mailbox\Services\Delivery\CampaignMessage;
use Modules\Mailbox\Services\Delivery\OutboundMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * The mailable, actually built.
 *
 * Every other test in Mailbox runs under `Mail::fake()`, which records a
 * mailable without ever calling `envelope()`, `content()` or `headers()`. That
 * is the right trade for asserting *what was sent* — but it means those three
 * methods were never executed by the suite, and one of them was broken from the
 * day it was written: `Envelope` takes `Illuminate\Mail\Mailables\Address`, the
 * file imported `Symfony\Component\Mime\Address`, and the two have the same
 * short name and the same constructor. Pressing Send threw a TypeError while
 * every test stayed green.
 *
 * So this file renders the message for real. It is deliberately the one place
 * in Mailbox that does, and it exists to fail if the mailable is ever again
 * wrong in a way a fake cannot see.
 */
class CampaignMessageTest extends TestCase
{
    public function test_the_envelope_is_built_from_the_outbound_message(): void
    {
        $mailable = new CampaignMessage($this->message());

        $envelope = $mailable->envelope();

        $this->assertSame('info@bineret.com', $envelope->from->address);
        $this->assertSame('Lavzen', $envelope->from->name);
        $this->assertSame('ada@example.com', $envelope->to[0]->address);
        $this->assertSame('Ada Lovelace', $envelope->to[0]->name);
        $this->assertSame('replies@bineret.com', $envelope->replyTo[0]->address);
        $this->assertSame('Quote for the side return', $envelope->subject);
    }

    /**
     * The whole mailable through Laravel's renderer, which is what a driver
     * hands to a transport. This is the assertion that would have caught the
     * TypeError: it constructs the same Symfony message a real send does.
     */
    public function test_the_message_renders_into_a_symfony_email(): void
    {
        $email = $this->render($this->message());

        $this->assertSame('Quote for the side return', $email->getSubject());
        $this->assertSame('info@bineret.com', $email->getFrom()[0]->getAddress());
        $this->assertSame('ada@example.com', $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('rough estimate', (string) $email->getHtmlBody());

        // The plain-text alternative, which `build()` attaches through the
        // Symfony message. A multipart message with only an HTML part is one of
        // the cheapest spam signals there is.
        $this->assertStringContainsString('rough estimate', (string) $email->getTextBody());
    }

    /**
     * `messageId` is minted by Kargah and is what a bounce callback is matched
     * against, so the header has to come out with exactly one pair of angle
     * brackets — Symfony adds them, which is why the builder's pair is stripped.
     */
    public function test_the_message_id_keeps_one_pair_of_brackets(): void
    {
        $email = $this->render($this->message());

        $this->assertSame(
            '<quote-1@bineret.com>',
            $email->getHeaders()->get('Message-ID')?->getBodyAsString(),
        );
    }

    /** The headers Gmail and Yahoo require of a bulk sender since 2024. */
    public function test_the_unsubscribe_headers_survive_rendering(): void
    {
        $email = $this->render($this->message());

        $this->assertSame(
            '<https://panel.bineret.com/mail/unsubscribe/abc>',
            $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString(),
        );
        $this->assertSame(
            'List-Unsubscribe=One-Click',
            $email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString(),
        );
    }

    /**
     * Send through Laravel's array transport and read back what it was handed.
     *
     * `render()` alone would not do: it renders the *view* and never builds the
     * envelope or the headers, which is exactly the blind spot that let the
     * TypeError live. Going through a mailer runs the whole path a real send
     * runs — `envelope()`, `content()`, `headers()` and `build()` — and the
     * array transport is the one place that keeps the result instead of
     * delivering it.
     */
    private function render(OutboundMessage $message): Email
    {
        config(['mail.mailers.probe' => ['transport' => 'array']]);

        $mailer = Mail::mailer('probe');

        $mailer->send(new CampaignMessage($message));

        $sent = $mailer->getSymfonyTransport()->messages()->first();

        $this->assertNotNull($sent, 'the array transport was handed nothing');

        $email = $sent->getOriginalMessage();

        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function message(): OutboundMessage
    {
        return new OutboundMessage(
            toEmail: 'ada@example.com',
            toName: 'Ada Lovelace',
            fromEmail: 'info@bineret.com',
            fromName: 'Lavzen',
            replyTo: 'replies@bineret.com',
            subject: 'Quote for the side return',
            html: '<p>Here is a rough estimate for the work.</p>',
            text: "Here is a rough estimate for the work.\n",
            messageId: '<quote-1@bineret.com>',
            headers: [
                'List-Unsubscribe' => '<https://panel.bineret.com/mail/unsubscribe/abc>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
        );
    }
}
