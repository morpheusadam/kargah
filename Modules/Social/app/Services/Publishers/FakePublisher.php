<?php

namespace Modules\Social\Services\Publishers;

use Illuminate\Support\Carbon;
use Modules\Social\Models\SocialAccount;

/**
 * A publisher that does not leave the process.
 *
 * The point of this class is that a test can assert *what was sent* rather than
 * only what was recorded. `Http::fake()` proves no request escaped; this proves
 * the second run of a job sent nothing at all, which is the property the whole
 * retry design turns on and which a recorded row cannot distinguish from a
 * resend that happened to write the same values.
 *
 * Registered through `Publishing::extend()`, which holds factories rather than
 * instances — so swapping a network for this one means the real driver for it
 * is never constructed.
 */
class FakePublisher implements IngestsNotifications, Publisher
{
    /** @var list<array{handle: string, body: string}> Every send, in order. */
    public array $sent = [];

    /** @var list<string> Every account the notification sync asked about. */
    public array $polled = [];

    private ?string $failWith = null;

    private ?string $unavailableBecause = null;

    /** @var list<InboundNotification> */
    private array $inbound = [];

    private int $issued = 0;

    public function __construct(private readonly string $network) {}

    public function network(): string
    {
        return $this->network;
    }

    /** Make the next and every following publish throw, as a real driver would. */
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

    /** Report as unconfigured without being asked to send, as an account with no token does. */
    public function unavailable(?string $reason): static
    {
        $this->unavailableBecause = $reason;

        return $this;
    }

    /** @param  list<InboundNotification>  $items */
    public function willReturnNotifications(array $items): static
    {
        $this->inbound = $items;

        return $this;
    }

    public function unavailableReason(SocialAccount $account): ?string
    {
        return $this->unavailableBecause;
    }

    public function publish(SocialAccount $account, string $body, array $media = []): PublishedPost
    {
        if ($this->failWith !== null) {
            throw PublishFailed::rejected($this->network, $this->failWith);
        }

        $this->sent[] = ['handle' => $account->handle, 'body' => $body];

        // Distinct per send on purpose: a test that retries has to be able to
        // tell a preserved remote id from a freshly issued one.
        $this->issued++;

        return new PublishedPost(
            $this->network.'-remote-'.$this->issued,
            'https://'.$this->network.'.test/posts/'.$this->issued,
        );
    }

    public function verify(SocialAccount $account): string
    {
        if ($this->failWith !== null) {
            throw PublishFailed::rejected($this->network, $this->failWith);
        }

        return $account->handle;
    }

    public function notifications(SocialAccount $account, int $limit = 40): array
    {
        $this->polled[] = $account->handle;

        if ($this->failWith !== null) {
            throw PublishFailed::rejected($this->network, $this->failWith);
        }

        return array_slice($this->inbound, 0, $limit);
    }

    /** How many times `publish()` actually sent, which is what a retry test asserts on. */
    public function sendCount(): int
    {
        return count($this->sent);
    }

    /** A notification fixture, so a test does not have to spell the constructor out. */
    public static function notification(string $remoteId, string $kind, ?Carbon $at = null): InboundNotification
    {
        return new InboundNotification(
            remoteId: $remoteId,
            kind: $kind,
            actorHandle: '@rita.vance',
            excerpt: 'This is exactly the workflow I was missing.',
            url: 'https://example.test/notifications/'.$remoteId,
            occurredAt: $at ?? now(),
        );
    }
}
