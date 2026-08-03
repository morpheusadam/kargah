<?php

namespace Modules\Mailbox\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Mailbox\Models\Campaign;
use Modules\Mailbox\Models\DeliveryProvider;

/**
 * A campaign, ready to send.
 *
 * The default body **does** carry the unsubscribe placeholder, which is the
 * opposite of how `DeliveryProviderFactory` defaults. That asymmetry is
 * deliberate: an unverified provider is what a fresh install actually looks
 * like, whereas a body with no unsubscribe link is a mistake, and every test
 * that is not about the pre-flight should start from a campaign that would go
 * out. `withoutUnsubscribeLink()` is how a test asks for the mistake.
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    public function definition(): array
    {
        $name = $this->faker->randomElement([
            'Resume — design agencies UK',
            'Follow-up #1',
            'Resume — startups DE',
            'Availability from mid-August',
            'Quarterly note to past clients',
        ]);

        return [
            'name' => $name,
            'subject' => 'Freelance front-end capacity from mid-August',
            'preheader' => 'Two days a week, from the 18th.',
            'body_html' => '<p>Hello {{first_name}},</p>'
                .'<p>I have two days a week free from the 18th and thought of you.</p>'
                .'<p><a href="'.Campaign::UNSUBSCRIBE_PLACEHOLDER.'">Unsubscribe</a></p>',
            'body_text' => "Hello {{first_name}},\n\n"
                ."I have two days a week free from the 18th and thought of you.\n\n"
                .'Unsubscribe: '.Campaign::UNSUBSCRIBE_PLACEHOLDER,
            'delivery_provider_id' => null,
            'status' => Campaign::DRAFT,
            'scheduled_for' => null,
            'started_at' => null,
            'finished_at' => null,
            'recipient_count' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'bounced_count' => 0,
            'created_by' => null,
        ];
    }

    public function through(DeliveryProvider $provider): static
    {
        return $this->state(fn (): array => ['delivery_provider_id' => $provider->getKey()]);
    }

    public function status(string $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    public function sending(): static
    {
        return $this->state(fn (): array => [
            'status' => Campaign::SENDING,
            'started_at' => now(),
        ]);
    }

    public function scheduledFor(\DateTimeInterface|string $when): static
    {
        return $this->state(fn (): array => [
            'status' => Campaign::SCHEDULED,
            'scheduled_for' => $when,
        ]);
    }

    /** The one mistake the pre-flight exists to catch. */
    public function withoutUnsubscribeLink(): static
    {
        return $this->state(fn (): array => [
            'body_html' => '<p>Hello {{first_name}}, I have two days a week free from the 18th.</p>',
            'body_text' => 'Hello {{first_name}}, I have two days a week free from the 18th.',
        ]);
    }
}
