<?php

namespace Modules\Mailbox\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Mailbox\Models\Campaign;
use Modules\Mailbox\Models\CampaignRecipient;
use Modules\Mailbox\Models\DeliveryProvider;

/**
 * One row of a campaign's audience.
 *
 * The address is unique across the factory, not merely within a campaign,
 * because `campaign_recipients` is unique on (campaign_id, email) and a test
 * that makes five hundred of them must not fail on a constraint rather than on
 * whatever it was testing.
 *
 * Tokens are deliberately left null. They are derived from the row id and
 * minted on the way past by `ensureTokens()`, so a factory that invented its
 * own would be inventing a value the signature check would then reject.
 */
class CampaignRecipientFactory extends Factory
{
    protected $model = CampaignRecipient::class;

    public function definition(): array
    {
        $n = $this->faker->unique()->numberBetween(1, 9_999_999);

        return [
            'campaign_id' => Campaign::factory(),
            'contact_id' => null,
            'email' => 'contact'.$n.'@studio-nord.example',
            'name' => 'Contact '.$n,
            'status' => CampaignRecipient::PENDING,
            'delivery_provider_id' => null,
            'message_id' => null,
            'unsubscribe_token' => null,
            'reply_token' => null,
            'attempts' => 0,
            'error' => null,
            'claimed_at' => null,
            'sent_at' => null,
            'failed_at' => null,
        ];
    }

    public function forCampaign(Campaign $campaign): static
    {
        return $this->state(fn (): array => ['campaign_id' => $campaign->getKey()]);
    }

    public function withEmail(string $email): static
    {
        return $this->state(fn (): array => ['email' => $email]);
    }

    public function status(string $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function sent(?DeliveryProvider $provider = null): static
    {
        return $this->state(fn (): array => [
            'status' => CampaignRecipient::SENT,
            'delivery_provider_id' => $provider?->getKey(),
            'sent_at' => now(),
        ]);
    }

    /** A row a stopped worker left behind, which is what `staleClaims` looks for. */
    public function claimedAt(\DateTimeInterface|string $when): static
    {
        return $this->state(fn (): array => [
            'status' => CampaignRecipient::CLAIMED,
            'claimed_at' => $when,
        ]);
    }
}
