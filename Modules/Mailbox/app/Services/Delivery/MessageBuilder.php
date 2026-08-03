<?php

namespace Modules\Mailbox\Services\Delivery;

use Illuminate\Support\Facades\URL;
use Modules\Mailbox\Models\Campaign;
use Modules\Mailbox\Models\CampaignRecipient;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Support\Tokens;

/**
 * Turn a campaign and one recipient into the message that will actually go out.
 *
 * Everything that has to be true of a bulk message is decided here, once, so
 * that no driver has to be trusted to get it right:
 *
 * - **`List-Unsubscribe` and `List-Unsubscribe-Post`.** Since February 2024
 *   Gmail and Yahoo require both from anyone sending in volume, and a campaign
 *   without them is filtered regardless of how clean the sending domain is. The
 *   `Post` header is what makes the mail client show its own one-click button
 *   instead of hunting for a link in the body.
 * - **A `mailto:` alongside the URL.** Some clients still prefer it, and it
 *   costs one more header. The address carries the same token, so an
 *   unsubscribe that arrives as a message rather than a request identifies the
 *   same recipient.
 * - **A signed `Reply-To`.** The token sits in the local part after a plus, so
 *   a reply lands in the mailbox `mailbox:sync-imap` already reads and can be
 *   matched back to the campaign it answers.
 * - **`Precedence: bulk` and `Auto-Submitted`.** Between them they stop
 *   out-of-office replies and vacation responders bouncing back off a 500-person
 *   send, which is otherwise several hundred messages into the inbox for no
 *   reason.
 *
 * The unsubscribe URL is signed by Laravel *and* carries a token that is itself
 * an HMAC. Belt and braces on purpose: the signature stops the URL being edited
 * to name a different recipient, and the token means the route can still say
 * whose it was even if the signature middleware is ever relaxed.
 */
class MessageBuilder
{
    /**
     * Build the message for one recipient.
     *
     * The recipient's tokens are minted here if they are missing, which is the
     * last moment before they have to exist. They are derived from the row id
     * and therefore stable, so a chunk that runs twice builds the identical
     * message rather than invalidating a link already sitting in somebody's
     * inbox.
     */
    public function build(Campaign $campaign, CampaignRecipient $recipient, DeliveryProvider $provider): OutboundMessage
    {
        $recipient->ensureTokens();

        $unsubscribeUrl = $this->unsubscribeUrl($recipient);
        $fromEmail = (string) $provider->from_email;

        return new OutboundMessage(
            toEmail: (string) $recipient->email,
            toName: $recipient->name,
            fromEmail: $fromEmail,
            fromName: $provider->from_name,
            replyTo: Tokens::replyAddress((int) $recipient->id, $fromEmail),
            subject: (string) $campaign->subject,
            html: $this->render($campaign->body_html, $recipient, $unsubscribeUrl),
            text: $this->render($campaign->body_text, $recipient, $unsubscribeUrl),
            messageId: $this->messageId($campaign, $recipient, $provider),
            headers: $this->headers($unsubscribeUrl, $fromEmail, $recipient),
        );
    }

    /**
     * The URL a one-click unsubscribe posts to.
     *
     * Signed with the application key and never expiring, because the link has
     * to keep working for as long as the message exists in somebody's archive —
     * an unsubscribe that has timed out is an unsubscribe that becomes a
     * complaint.
     */
    public function unsubscribeUrl(CampaignRecipient $recipient): string
    {
        return URL::signedRoute('mail.unsubscribe', [
            'token' => $recipient->ensureTokens()->unsubscribe_token,
        ]);
    }

    /**
     * The identifier this message is known by, on both sides.
     *
     * Derived from the campaign, the recipient and the provider rather than
     * generated, so it exists *before* the send instead of after it. That is
     * what lets a bounce be matched to a row even when the worker that sent the
     * message never got to write anything down.
     *
     * The domain is the sending domain rather than the host's, because a
     * Message-ID whose right-hand side does not match the From domain is a spam
     * signal several filters weigh.
     */
    public function messageId(Campaign $campaign, CampaignRecipient $recipient, DeliveryProvider $provider): string
    {
        $domain = $provider->sending_domain
            ?: (str_contains((string) $provider->from_email, '@')
                ? substr((string) $provider->from_email, strrpos((string) $provider->from_email, '@') + 1)
                : 'kargah.local');

        return '<campaign-'.$campaign->getKey().'-'.$recipient->getKey().'@'.$domain.'>';
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $unsubscribeUrl, string $fromEmail, CampaignRecipient $recipient): array
    {
        $mailto = Tokens::replyAddress((int) $recipient->id, $fromEmail);

        return [
            // The URL first: clients that understand only one take the first.
            'List-Unsubscribe' => '<'.$unsubscribeUrl.'>, <mailto:'.$mailto.'?subject=unsubscribe>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            'Precedence' => 'bulk',
            'Auto-Submitted' => 'auto-generated',
            'X-Campaign-Id' => (string) $recipient->campaign_id,
        ];
    }

    /**
     * Substitute the placeholders a campaign body may carry.
     *
     * Four, and no more. A template language here would be a template language
     * a person writing a newsletter could break, and the only variables a
     * campaign genuinely needs are who it is going to and how to stop it.
     */
    private function render(?string $body, CampaignRecipient $recipient, string $unsubscribeUrl): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        return strtr($body, [
            Campaign::UNSUBSCRIBE_PLACEHOLDER => $unsubscribeUrl,
            '{{name}}' => $recipient->name ?: (string) $recipient->email,
            '{{email}}' => (string) $recipient->email,
            '{{first_name}}' => $this->firstName($recipient),
        ]);
    }

    /**
     * The first word of the stored name, falling back to the address.
     *
     * 'Hello {{first_name}}' addressed to an empty string is the single most
     * recognisable mark of a bad mail merge, so there is no case in which this
     * returns nothing.
     */
    private function firstName(CampaignRecipient $recipient): string
    {
        $name = trim((string) $recipient->name);

        if ($name === '') {
            return (string) $recipient->email;
        }

        return explode(' ', $name)[0];
    }
}
