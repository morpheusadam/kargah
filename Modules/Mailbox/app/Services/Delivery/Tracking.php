<?php

namespace Modules\Mailbox\Services\Delivery;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\URL;
use Modules\Mailbox\Models\Campaign;
use Modules\Mailbox\Models\CampaignLink;
use Modules\Mailbox\Models\CampaignRecipient;
use Modules\Mailbox\Support\Tokens;

/**
 * What a campaign's HTML has done to it so that opens and clicks come back.
 *
 * Two changes, both to the HTML body only and neither to the plain-text
 * alternative. Text is left exactly as written because a rewritten link there is
 * visible — a person reading the text part would see a signed redirect instead
 * of the address they were promised, which is what a phishing message looks
 * like, and no pixel can load in a body that has no images.
 *
 * **Every link is registered before it is rewritten.** The rewritten URL carries
 * the id of a `campaign_links` row and nothing else; the destination lives in
 * that row and is read from the database at redirect time. The alternative —
 * putting the destination in the URL as `?url=…` — makes the sending domain an
 * open redirect, which is both a vulnerability and, because it is precisely what
 * a phishing campaign hunts for, a spam signal charged against the domain the
 * rest of this module works to keep clean. A link that cannot be registered is
 * left in the body untouched rather than rewritten into a redirect that would
 * have nowhere to go.
 *
 * **The URLs are signed routes carrying an HMAC token**, exactly as the
 * unsubscribe link is, rather than the random UUID listmonk uses for the same
 * job. A UUID that leaks is reusable by whoever has it and a database lookup is
 * the only thing that can say whether it was ever issued; a signed URL cannot be
 * forged at all, and the two halves of a click URL are signed separately so that
 * neither can be lifted out of one message into another's place.
 *
 * Neither URL expires. A newsletter sits in somebody's archive for years, and a
 * link that has timed out is a person landing nowhere for the sake of a
 * statistic.
 *
 * What this cannot do is tell a person from a machine. Corporate security
 * gateways fetch every link in a message before delivering it, and image proxies
 * load the pixel whether or not anybody looks at the message. Opens are
 * therefore a floor with noise on top and clicks are worth more than opens —
 * which is the ordinary state of email tracking everywhere, not a defect here.
 */
class Tracking
{
    /**
     * Links already registered, per campaign, for the life of this instance.
     *
     * A chunk builds fifty messages from the same body, so without this the
     * same four links would be looked up two hundred times. One instance lives
     * for one chunk, which is also short enough that a body edited mid-campaign
     * is picked up by the next one.
     *
     * @var array<int, array<string, CampaignLink>>
     */
    private array $links = [];

    public function opensEnabled(): bool
    {
        return (bool) config('mailbox.tracking.opens', true);
    }

    public function clicksEnabled(): bool
    {
        return (bool) config('mailbox.tracking.clicks', true);
    }

    /**
     * The HTML body as it will be sent: links rewritten, pixel appended.
     *
     * Called before the placeholders are substituted, which is what keeps the
     * unsubscribe link out of the rewriter's way — at this point it is still
     * `{{unsubscribe_url}}` and not a URL at all, and a one-click link behind a
     * redirect of ours is a link a mail client's automated check may not follow.
     */
    public function apply(Campaign $campaign, CampaignRecipient $recipient, ?string $html): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        if ($this->clicksEnabled()) {
            $html = $this->rewriteLinks($campaign, $recipient, $html);
        }

        if ($this->opensEnabled()) {
            $html = $this->appendPixel($recipient, $html);
        }

        return $html;
    }

    /** Where the tracking pixel for one recipient lives. */
    public function openUrl(CampaignRecipient $recipient): string
    {
        return URL::signedRoute('mail.open', [
            'token' => Tokens::for(Tokens::OPEN, (int) $recipient->getKey()),
        ]);
    }

    /** Where one recipient's copy of one registered link points. */
    public function clickUrl(CampaignRecipient $recipient, CampaignLink $link): string
    {
        return URL::signedRoute('mail.click', [
            'token' => Tokens::for(Tokens::CLICK, (int) $recipient->getKey()),
            'link' => Tokens::for(Tokens::LINK, (int) $link->getKey()),
        ]);
    }

    /**
     * Register a destination against a campaign, or find the row already there.
     *
     * Public because this is the half that has to happen first, and reading it
     * at the call site is the point. Null means the URL got no row and so must
     * not be rewritten.
     */
    public function register(Campaign $campaign, string $url): ?CampaignLink
    {
        $campaignId = (int) $campaign->getKey();
        $hash = CampaignLink::fingerprint($url);

        if (isset($this->links[$campaignId][$hash])) {
            return $this->links[$campaignId][$hash];
        }

        try {
            $link = CampaignLink::query()->firstOrCreate(
                ['campaign_id' => $campaignId, 'url_hash' => $hash],
                ['url' => $url],
            );
        } catch (QueryException) {
            // Two workers building the same campaign at once both found nothing
            // and both inserted; one of them lost to the unique index. The row
            // it wanted exists now, which is the only outcome either cared
            // about.
            $link = CampaignLink::query()
                ->where('campaign_id', $campaignId)
                ->where('url_hash', $hash)
                ->first();
        }

        if ($link === null) {
            return null;
        }

        return $this->links[$campaignId][$hash] = $link;
    }

    /**
     * Point every trackable `href` at this campaign's redirect.
     *
     * Only quoted `href` attributes on an `<a>` are touched. An unquoted one is
     * left alone rather than guessed at: this is a regex over somebody's
     * newsletter HTML, and the failure mode of guessing wrong is a broken
     * message, which is far worse than an untracked link.
     */
    private function rewriteLinks(Campaign $campaign, CampaignRecipient $recipient, string $html): string
    {
        $rewritten = preg_replace_callback(
            '/(<a\b[^>]*?\bhref\s*=\s*)(["\'])(.*?)\2/i',
            function (array $match) use ($campaign, $recipient): string {
                // Decoded because the body holds `&amp;` where the destination
                // holds `&`, and it is the destination that has to be stored —
                // a person sent to `?a=1&amp;b=2` arrives at a different page.
                $url = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if (! $this->trackable($url)) {
                    return $match[0];
                }

                $link = $this->register($campaign, $url);

                if ($link === null) {
                    return $match[0];
                }

                return $match[1].$match[2]
                    .htmlspecialchars($this->clickUrl($recipient, $link), ENT_QUOTES, 'UTF-8')
                    .$match[2];
            },
            $html,
        );

        // preg_replace_callback returns null when it hits its backtrack limit,
        // which a pathological body can do. The untracked original is the right
        // answer then — a campaign that cannot be tracked still has to go out.
        return $rewritten ?? $html;
    }

    /**
     * Whether a destination is one this module will take responsibility for.
     *
     * Http and https only, which is the check that matters: it is what stops a
     * `javascript:` or `data:` href ever becoming a row in `campaign_links` and
     * therefore ever becoming somewhere the redirect will send a person.
     * Anything still carrying a placeholder is skipped too — `{{unsubscribe_url}}`
     * has not been substituted yet at this point, and the one-click link is
     * deliberately not put behind a redirect of ours.
     */
    private function trackable(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1 && ! str_contains($url, '{{');
    }

    /**
     * Put the pixel at the end of the body.
     *
     * Inside `</body>` where there is one, because an `<img>` after the closing
     * tag is something a few clients drop when they sanitise a message, and
     * appended plainly where there is not — a campaign body is often a fragment
     * rather than a document.
     */
    private function appendPixel(CampaignRecipient $recipient, string $html): string
    {
        $pixel = '<img src="'.htmlspecialchars($this->openUrl($recipient), ENT_QUOTES, 'UTF-8').'"'
            .' alt="" width="1" height="1" border="0"'
            // Four properties, none of them decoration. Between them they cover
            // what the different filters each look at: one client hides an image
            // by `display`, another only respects a zero dimension, and a third
            // strips `opacity` but honours `max-width`. A pixel that any of them
            // renders as a visible box is a pixel a person can see.
            .' style="display:none;max-height:0;max-width:0;opacity:0;">';

        $close = strripos($html, '</body>');

        if ($close === false) {
            return $html.$pixel;
        }

        return substr($html, 0, $close).$pixel.substr($html, $close);
    }
}
