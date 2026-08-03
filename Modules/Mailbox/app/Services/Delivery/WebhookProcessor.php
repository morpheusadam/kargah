<?php

namespace Modules\Mailbox\Services\Delivery;

use Modules\Mailbox\Models\CampaignRecipient;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Models\Suppression;

/**
 * What Kargah does with a bounce, a complaint or an unsubscribe once a driver
 * has translated it.
 *
 * Two things have to be true of this class and both are load bearing.
 *
 * **It is idempotent.** Every provider will deliver the same callback twice —
 * on a retry, on a redelivery from their console, on a network blip they could
 * not tell from a failure — and the second delivery must change nothing. Two
 * mechanisms give that, neither of them a flag on a request:
 *
 * - `suppressions` is unique on `email`, so `Suppression::block()` writes one
 *   row however many times it is called.
 * - The recipient's status only moves forward, and the counters on the provider
 *   and the campaign are only touched by an actual transition. A recipient
 *   already `bounced` cannot be moved to `bounced` again, so a doubled callback
 *   cannot double a bounce count.
 *
 * **A hard bounce on one provider blocks the address everywhere.** That is the
 * whole reason `suppressions` is a shared table with no provider column, and it
 * is why this class writes the suppression *before* it touches the recipient
 * row — if anything after that fails, the address is already blocked, which is
 * the side to fail on.
 */
class WebhookProcessor
{
    /**
     * Apply a batch of events from one provider.
     *
     * @param  list<DeliveryEvent>  $events
     * @return int How many of them changed anything. A second delivery of the
     *             same callback returns zero, which is what a test asserts on.
     */
    public function apply(DeliveryProvider $provider, array $events): int
    {
        $changed = 0;

        foreach ($events as $event) {
            $changed += $this->applyOne($provider, $event) ? 1 : 0;
        }

        return $changed;
    }

    private function applyOne(DeliveryProvider $provider, DeliveryEvent $event): bool
    {
        $email = Suppression::normalise($event->email);

        if ($email === '') {
            return false;
        }

        $recipient = $this->recipientFor($event, $email);

        // The suppression first, and unconditionally. A bounce for an address
        // that never went through a campaign — a transactional message, or a
        // recipient row since deleted — still has to block that address, and it
        // is exactly the case a recipient-driven design would drop on the floor.
        $suppressionWritten = false;

        if (($reason = $event->suppressionReason()) !== null) {
            $before = Suppression::query()->where('email', $email)->value('reason');

            Suppression::block($email, $reason, $provider->driver, $event->detail);

            $suppressionWritten = $before !== $reason;
        }

        if ($recipient === null) {
            return $suppressionWritten;
        }

        $status = match ($event->kind) {
            DeliveryEvent::HARD_BOUNCE, DeliveryEvent::SOFT_BOUNCE => CampaignRecipient::BOUNCED,
            DeliveryEvent::COMPLAINT => CampaignRecipient::COMPLAINED,
            default => null,
        };

        // A soft bounce is recorded against the recipient but never suppresses,
        // because a full mailbox is not a dead address — see the note in
        // `MailgunMailer::events()` about why conflating them is how a good
        // list gets destroyed.
        if ($status === null || $recipient->status === $status) {
            return $suppressionWritten;
        }

        // Only a recipient that actually reached a provider can bounce. A
        // `pending` row reported as bounced means the callback belongs to some
        // earlier send, and moving it backwards would put it out of the queue.
        if (! $recipient->wasDelivered()) {
            return $suppressionWritten;
        }

        $recipient->forceFill([
            'status' => $status,
            'error' => $event->detail,
            'failed_at' => $event->occurredAt ?? now(),
        ])->save();

        // Counters move only on the transition, which is what stops a doubled
        // callback double-counting. The provider charged is the one that
        // carried the message, not necessarily the one that reported it — a
        // report can only arrive from the carrier, but reading it from the row
        // is what keeps the figures honest if that ever stops being true.
        $carrier = $recipient->provider ?? $provider;

        if ($status === CampaignRecipient::COMPLAINED) {
            $carrier->recordComplaint();
        } elseif ($event->kind === DeliveryEvent::HARD_BOUNCE) {
            $carrier->recordBounce();
        }

        $recipient->campaign?->syncCounters();

        return true;
    }

    /**
     * The recipient row an event is about.
     *
     * By message id first, because that is the identifier Kargah minted before
     * the send and the only one that is certainly unique. Falling back to the
     * address is what covers a provider that quotes its own id instead — and it
     * is scoped to rows that actually reached a provider, newest first, so a
     * bounce is attributed to the most recent send to that address rather than
     * to a campaign from March.
     */
    private function recipientFor(DeliveryEvent $event, string $email): ?CampaignRecipient
    {
        $messageId = trim((string) $event->messageId);

        if ($messageId !== '') {
            // Matched with and without the angle brackets. Kargah stores the
            // header form; providers disagree about whether to strip them, and
            // a bounce that fails to match falls back to the address and gets
            // attributed to the wrong campaign.
            $bare = trim($messageId, '<>');

            $byId = CampaignRecipient::query()
                ->whereIn('message_id', [$messageId, $bare, '<'.$bare.'>'])
                ->first();

            if ($byId !== null) {
                return $byId;
            }
        }

        return CampaignRecipient::query()
            ->where('email', $email)
            ->whereIn('status', CampaignRecipient::deliveredStatuses())
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->first();
    }
}
