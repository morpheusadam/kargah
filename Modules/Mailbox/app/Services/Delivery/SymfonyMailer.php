<?php

namespace Modules\Mailbox\Services\Delivery;

use Illuminate\Support\Facades\Mail;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Support\Senders;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * The sending policy every real driver shares.
 *
 * All five providers are Symfony mailer transports underneath — that is what
 * Laravel's `smtp`, `ses`, `postmark` and `mailgun` mailers are — so the only
 * thing a driver has to supply is the transport configuration for one row of
 * `delivery_providers`. Everything else is here, because it is a hosting
 * constraint rather than a per-provider detail.
 *
 * Two things about the way the transport is reached are deliberate.
 *
 * **The configuration is registered under a name and the message is sent
 * through `Mail::mailer($name)`.** `Mail::build()` would be shorter and is
 * wrong: `MailFake` forwards unknown calls to the real manager, so a test with
 * `Mail::fake()` would still construct a live transport and, given credentials,
 * still send. Naming a mailer is intercepted by the fake, so under `Mail::fake()`
 * nothing is constructed at all.
 *
 * **`unavailableReason()` is asked before anything is built.** On a fresh
 * Kargah none of the four bridge packages is installed and no credentials
 * exist, and both of those are ordinary states rather than exceptions — they
 * belong in `campaign_recipients.error` where the owner can read them, not in a
 * class-not-found five minutes into a campaign. It is asked again inside
 * `send()` so that a driver reached by some other path cannot skip the check.
 */
abstract class SymfonyMailer implements Mailer
{
    /** Seconds to wait for a provider that has accepted the connection but stopped talking. */
    protected const TIMEOUT = 20;

    /**
     * The Laravel mailer configuration for this provider.
     *
     * Returned rather than registered, so a test can read what a driver would
     * have configured without a transport ever being built from it.
     *
     * @return array<string, mixed>
     */
    abstract protected function transportConfig(DeliveryProvider $provider): array;

    public function unavailableReason(DeliveryProvider $provider): ?string
    {
        if (! $provider->is_active) {
            return $provider->label().' is switched off in Kargah, so nothing was sent through it.';
        }

        if ($package = $this->missingPackage()) {
            return $provider->label().' needs the '.$package.' package, which is not installed. '
                .'Run composer require '.$package.' before sending through it.';
        }

        $missing = $provider->missingCredentials();

        if ($missing !== []) {
            return $provider->label().' credentials are not configured — '
                .implode(' and ', $missing).' '.(count($missing) === 1 ? 'is' : 'are')
                .' missing, so the message was not sent.';
        }

        if ($provider->from_email === null || $provider->from_email === '') {
            return $provider->label().' has no from address, so there was nothing to send the message as.';
        }

        return null;
    }

    public function send(DeliveryProvider $provider, OutboundMessage $message): SentMessage
    {
        // Defence in depth. `CampaignSender` has already asked, but a driver
        // reached any other way must not be the thing that builds a live
        // transport out of half a configuration.
        if ($reason = $this->unavailableReason($provider)) {
            throw SendFailed::misconfigured($provider->label(), $reason);
        }

        $name = $this->mailerName($provider);

        config(['mail.mailers.'.$name => $this->transportConfig($provider)]);

        try {
            Mail::mailer($name)->send(new CampaignMessage($message));
        } catch (TransportExceptionInterface $e) {
            throw SendFailed::unreachable($provider->label(), $e->getMessage());
        } catch (\RuntimeException $e) {
            // A bridge that is present but unhappy — a bad region, an endpoint
            // that does not exist — reports itself this way. It is this
            // message's failure rather than the job's; see the class docblock
            // on `Mailer`.
            throw SendFailed::rejected($provider->label(), $e->getMessage());
        }

        return new SentMessage($message->messageId);
    }

    /**
     * The composer package this transport needs and does not have.
     *
     * Checked by class rather than by reading `composer.json`, because the
     * question is whether the bridge is autoloadable right now — a package
     * listed but not installed would answer the wrong way round.
     */
    protected function missingPackage(): ?string
    {
        $package = Senders::package($this->driver());

        if ($package === null) {
            return null;
        }

        return class_exists($this->bridgeClass()) ? null : $package;
    }

    /** The class whose presence proves the bridge is installed. */
    protected function bridgeClass(): string
    {
        return \stdClass::class;
    }

    /**
     * A configuration name that is unique per provider row.
     *
     * Per row rather than per driver because two Brevo accounts with different
     * quotas is an ordinary setup, and a shared name would have the second
     * send inherit the first one's credentials.
     */
    protected function mailerName(DeliveryProvider $provider): string
    {
        return 'mailbox_'.$this->driver().'_'.$provider->getKey();
    }
}
