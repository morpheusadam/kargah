<?php

namespace Modules\Mailbox\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Mailbox\Models\DeliveryProvider;
use Modules\Mailbox\Support\Senders;

/**
 * A provider, configured or not.
 *
 * The default has **no credentials and no verified DNS**, because that is the
 * ordinary state of a fresh install and of this developer's machine: nothing
 * here should need a secret to be exercised, and a test that wants the
 * pre-flight to refuse gets that for free.
 *
 * `configured()` fills in whatever fields the driver looks for, so a test never
 * has to know that Brevo wants an SMTP login and SES wants a region.
 * `verified()` is separate from it on purpose — credentials and DNS are two
 * different things to be missing, and the pre-flight test needs to be able to
 * have one without the other.
 */
class DeliveryProviderFactory extends Factory
{
    protected $model = DeliveryProvider::class;

    public function definition(): array
    {
        $driver = $this->faker->randomElement(Senders::keys());
        $n = $this->faker->unique()->numberBetween(1, 99_999);

        return [
            'name' => Senders::label($driver),
            'driver' => $driver,
            'sending_domain' => 'news.kargah.dev',
            'from_email' => 'nima'.$n.'@news.kargah.dev',
            'from_name' => 'Nima Fazlipour',
            'credentials' => null,
            // Zero means unmetered rather than blocked, so the default provider
            // sends without a test having to think about quotas at all. A test
            // about quotas sets them.
            'daily_quota' => 0,
            'hourly_quota' => 0,
            'sent_today' => 0,
            'sent_this_hour' => 0,
            'quota_window_started_at' => null,
            'health_score' => 100,
            'bounce_count' => 0,
            'complaint_count' => 0,
            // Deterministic, not random. A test that asserts the pre-flight
            // refuses has to be able to make a provider and know the answer.
            'spf_verified' => false,
            'dkim_verified' => false,
            'dns_checked_at' => null,
            'is_active' => true,
            'priority' => 10,
            'last_error' => null,
        ];
    }

    public function driver(string $driver): static
    {
        return $this->state(fn (): array => [
            'driver' => $driver,
            'name' => Senders::label($driver),
        ]);
    }

    /** With every credential the driver requires, so it will send. */
    public function configured(string $secret = 'test-credential'): static
    {
        return $this->state(function (array $attributes) use ($secret): array {
            $credentials = [];

            foreach (Senders::credentialFields($attributes['driver']) as $field) {
                $credentials[$field] = match ($field) {
                    'host' => 'mail.kargah.test',
                    'port' => '587',
                    'username' => 'nima@kargah.test',
                    'region' => 'eu-central-1',
                    'domain' => 'mg.kargah.test',
                    'endpoint' => 'api.eu.mailgun.net',
                    'message_stream' => 'broadcast',
                    default => $secret,
                };
            }

            return ['credentials' => $credentials];
        });
    }

    /** SPF and DKIM signed off, which is what the pre-flight insists on. */
    public function verified(): static
    {
        return $this->state(fn (): array => [
            'spf_verified' => true,
            'dkim_verified' => true,
            'dns_checked_at' => now(),
        ]);
    }

    /** Configured, verified and ready — the state most sending tests want. */
    public function ready(): static
    {
        return $this->configured()->verified();
    }

    public function withDailyQuota(int $quota): static
    {
        return $this->state(fn (): array => ['daily_quota' => $quota]);
    }

    public function withHourlyQuota(int $quota): static
    {
        return $this->state(fn (): array => ['hourly_quota' => $quota]);
    }

    public function priority(int $priority): static
    {
        return $this->state(fn (): array => ['priority' => $priority]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function health(int $score): static
    {
        return $this->state(fn (): array => ['health_score' => $score]);
    }
}
