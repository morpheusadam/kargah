<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Models\SoftBounceTally;
use Modules\Mailbox\Models\Suppression;
use Modules\Mailbox\Services\Delivery\DeliveryEvent;
use Modules\Mailbox\Services\Delivery\WebhookProcessor;
use Tests\TestCase;

/**
 * When "not now" has been said often enough to mean "not ever".
 *
 * A soft bounce is a full mailbox or a greylist, and suppressing on one would
 * destroy a good list — that judgement is unchanged and the first test here
 * pins it. What is new is that a *run* of them is counted, because an address
 * that refuses every campaign is dead however politely it says so, and each
 * further attempt is another point of bounce rate charged against the sending
 * domain.
 *
 * The property that matters most is the reset. A delivery clears the tally, so
 * this counts consecutive refusals rather than lifetime ones; without it every
 * address that has ever been away from its desk eventually gets blocked.
 */
class SoftBounceThresholdTest extends TestCase
{
    use RefreshDatabase;

    private DeliveryProvider $provider;

    private WebhookProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-08-07 10:00:00');

        $this->provider = DeliveryProvider::factory()->create();
        $this->processor = new WebhookProcessor;

        config(['mailbox.bounce.soft_threshold' => 3]);
    }

    public function test_one_soft_bounce_still_does_not_suppress(): void
    {
        $this->soft('busy@example.com');

        $this->assertFalse(Suppression::blocks('busy@example.com'));
        $this->assertSame(1, SoftBounceTally::countFor('busy@example.com'));
    }

    public function test_the_threshold_blocks_the_address(): void
    {
        $this->soft('busy@example.com');
        $this->soft('busy@example.com');

        $this->assertFalse(Suppression::blocks('busy@example.com'));

        $this->soft('busy@example.com');

        $this->assertTrue(Suppression::blocks('busy@example.com'));

        $suppression = Suppression::query()->where('email', 'busy@example.com')->sole();

        $this->assertSame(Suppression::REPEATED_SOFT_BOUNCE, $suppression->reason);
        $this->assertStringContainsString('3 soft bounces in a row', (string) $suppression->detail);
    }

    /**
     * The reset, which is the whole reason the count is consecutive. Two
     * refusals, then a message gets through, then two more: five soft bounces
     * in a lifetime and not one of them consecutive enough to act on.
     */
    public function test_a_delivery_clears_the_run(): void
    {
        $this->soft('holiday@example.com');
        $this->soft('holiday@example.com');

        $this->processor->apply($this->provider, [
            new DeliveryEvent(kind: DeliveryEvent::DELIVERED, email: 'holiday@example.com'),
        ]);

        $this->assertSame(0, SoftBounceTally::countFor('holiday@example.com'));

        $this->soft('holiday@example.com');
        $this->soft('holiday@example.com');

        $this->assertFalse(Suppression::blocks('holiday@example.com'));
        $this->assertSame(2, SoftBounceTally::countFor('holiday@example.com'));
    }

    /**
     * A complaint must never be quietly downgraded to a bounce. Someone who
     * pressed 'this is spam' is a different fact about the relationship than a
     * mailbox that was full, and the page shows the reason.
     */
    public function test_an_existing_suppression_keeps_its_reason(): void
    {
        Suppression::block('angry@example.com', Suppression::COMPLAINT, 'smtp');

        $this->soft('angry@example.com');
        $this->soft('angry@example.com');
        $this->soft('angry@example.com');
        $this->soft('angry@example.com');

        $this->assertSame(
            Suppression::COMPLAINT,
            Suppression::query()->where('email', 'angry@example.com')->value('reason'),
        );
    }

    /** Zero switches the behaviour off: soft bounces are recorded and never acted on. */
    public function test_a_zero_threshold_never_blocks(): void
    {
        config(['mailbox.bounce.soft_threshold' => 0]);

        for ($i = 0; $i < 10; $i++) {
            $this->soft('patient@example.com');
        }

        $this->assertFalse(Suppression::blocks('patient@example.com'));
        $this->assertSame(10, SoftBounceTally::countFor('patient@example.com'));
    }

    /**
     * The same callback twice is one bounce, not two. Providers redeliver, and
     * a tally that counted redeliveries would reach the threshold on an address
     * that only ever refused once.
     */
    public function test_the_tally_is_keyed_on_the_address_not_the_row(): void
    {
        $this->soft('Busy@Example.COM');
        $this->soft('busy@example.com');

        $this->assertSame(2, SoftBounceTally::countFor('busy@example.com'));
        $this->assertSame(1, SoftBounceTally::query()->count());
    }

    /** A hard bounce blocks on the first one, as it always has. */
    public function test_a_hard_bounce_is_unaffected(): void
    {
        $this->processor->apply($this->provider, [
            new DeliveryEvent(kind: DeliveryEvent::HARD_BOUNCE, email: 'gone@example.com'),
        ]);

        $this->assertTrue(Suppression::blocks('gone@example.com'));
        $this->assertSame(
            Suppression::HARD_BOUNCE,
            Suppression::query()->where('email', 'gone@example.com')->value('reason'),
        );
        $this->assertSame(0, SoftBounceTally::countFor('gone@example.com'));
    }

    private function soft(string $email): void
    {
        $this->processor->apply($this->provider, [
            new DeliveryEvent(
                kind: DeliveryEvent::SOFT_BOUNCE,
                email: $email,
                detail: 'mailbox full',
            ),
        ]);
    }
}
