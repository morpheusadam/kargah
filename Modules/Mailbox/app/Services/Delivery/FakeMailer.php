<?php

namespace Modules\Mailbox\Services\Delivery;

use Illuminate\Http\Request;
use Modules\Mailbox\Models\DeliveryProvider;

/**
 * A driver that does not leave the process.
 *
 * The point of this class is that a test can assert *what was sent* rather than
 * only what was recorded. `Mail::fake()` proves no message reached a transport;
 * this proves the second run of a chunk sent nothing at all, which is the
 * property the whole idempotency design turns on and which a recorded row
 * cannot distinguish from a resend that happened to write the same values.
 *
 * Registered through `Delivery::extend()`, which holds factories rather than
 * instances — so swapping a driver for this one means the real driver for it is
 * never constructed, and therefore no Symfony transport for it is ever built.
 *
 * `dieAfter()` is what makes the killed-worker test honest. It throws a
 * `WorkerKilled` — an `Error`, which `CampaignSender` deliberately does not
 * contain — at the top of the next recipient, before that recipient has been
 * claimed. That is the state a stopped worker leaves: everything it finished is
 * recorded, everything it had not started is still `pending`, and the next
 * cron tick picks up from there.
 */
class FakeMailer implements HandlesWebhooks, Mailer
{
    /** @var list<array{email: string, provider: int, subject: string, headers: array<string, string>, replyTo: string|null}> Every send, in order. */
    public array $sent = [];

    private ?string $failWith = null;

    private ?string $unavailableBecause = null;

    private ?int $dieAfter = null;

    private bool $verifies = true;

    /** @var list<DeliveryEvent> */
    private array $inbound = [];

    public function __construct(private readonly string $driver) {}

    public function driver(): string
    {
        return $this->driver;
    }

    /** Make every following send throw, as a provider that has started refusing does. */
    public function failWith(string $message): static
    {
        $this->failWith = $message;

        return $this;
    }

    /** Stop failing, which is what a test does between the failed run and the retry. */
    public function succeed(): static
    {
        $this->failWith = null;

        return $this;
    }

    /** Report as unconfigured without being asked to send, as a provider with no credentials does. */
    public function unavailable(?string $reason): static
    {
        $this->unavailableBecause = $reason;

        return $this;
    }

    /**
     * Stop the process once this many messages have gone out.
     *
     * The stop lands at the top of the next recipient rather than inside a
     * send, because that is where the claim has not yet been taken — see the
     * class docblock and `WorkerKilled`.
     */
    public function dieAfter(int $sends): static
    {
        $this->dieAfter = $sends;

        return $this;
    }

    /** Bring the worker back, which is what the next cron tick amounts to. */
    public function revive(): static
    {
        $this->dieAfter = null;

        return $this;
    }

    /** Refuse to verify callbacks, as a provider whose signing key is wrong does. */
    public function rejectWebhooks(): static
    {
        $this->verifies = false;

        return $this;
    }

    /** @param  list<DeliveryEvent>  $events */
    public function willReport(array $events): static
    {
        $this->inbound = $events;

        return $this;
    }

    public function unavailableReason(DeliveryProvider $provider): ?string
    {
        if ($this->dieAfter !== null && count($this->sent) >= $this->dieAfter) {
            throw new WorkerKilled('The worker was stopped after '.count($this->sent).' messages.');
        }

        return $this->unavailableBecause;
    }

    public function send(DeliveryProvider $provider, OutboundMessage $message): SentMessage
    {
        if ($this->failWith !== null) {
            throw SendFailed::rejected($provider->label(), $this->failWith);
        }

        $this->sent[] = [
            'email' => $message->toEmail,
            'provider' => (int) $provider->getKey(),
            'subject' => $message->subject,
            'headers' => $message->headers,
            'replyTo' => $message->replyTo,
        ];

        return new SentMessage($message->messageId);
    }

    public function verify(Request $request, DeliveryProvider $provider): bool
    {
        return $this->verifies;
    }

    /** @return list<DeliveryEvent> */
    public function events(Request $request): array
    {
        return $this->inbound;
    }

    /** How many messages actually went out, which is what an idempotency test asserts on. */
    public function sendCount(): int
    {
        return count($this->sent);
    }

    /** @return list<string> Every address this driver was given, in order, duplicates included. */
    public function recipients(): array
    {
        return array_map(fn (array $send): string => $send['email'], $this->sent);
    }

    /** How many messages this provider row carried, for the quota split assertions. */
    public function countFor(DeliveryProvider $provider): int
    {
        return count(array_filter($this->sent, fn (array $send): bool => $send['provider'] === (int) $provider->getKey()));
    }
}
