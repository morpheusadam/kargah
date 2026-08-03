<?php

namespace Modules\Social\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * An account, connected or not.
 *
 * The default has **no credentials**, because that is the ordinary state of a
 * fresh install and of this developer's machine: nothing here should need a
 * secret to be exercised. `connected()` fills in whatever fields the network's
 * driver looks for, so a test never has to know that Mastodon wants an instance
 * and Bluesky wants a handle.
 */
class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    public function definition(): array
    {
        $network = $this->faker->randomElement(Networks::keys());

        return [
            'network' => $network,
            'handle' => $this->handleFor($network),
            'display_name' => 'Nima Fazlipour',
            'avatar_url' => null,
            'credentials' => null,
            'token_expires_at' => null,
            'company_id' => null,
            'is_active' => true,
            'connected_at' => null,
            'last_checked_at' => null,
            'last_error' => null,
            'created_by' => null,
        ];
    }

    public function onNetwork(string $network): static
    {
        return $this->state(fn (): array => [
            'network' => $network,
            'handle' => $this->handleFor($network),
        ]);
    }

    /** With every credential the network's driver requires, so it publishes. */
    public function connected(string $secret = 'test-credential'): static
    {
        return $this->state(function (array $attributes) use ($secret): array {
            $credentials = [];

            foreach (Networks::credentialFields($attributes['network']) as $field) {
                $credentials[$field] = match ($field) {
                    'instance' => 'https://mastodon.test',
                    'identifier' => 'kargah.bsky.social',
                    'member_urn' => 'urn:li:person:AbCdEfGh',
                    'chat_id' => '@kargah_buildlog',
                    default => $secret,
                };
            }

            return [
                'credentials' => $credentials,
                'connected_at' => now(),
            ];
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    /**
     * A handle that reads like the network's own and cannot collide.
     *
     * `social_accounts` is unique on (network, handle), so a test that makes
     * two accounts on one network would otherwise fail on a constraint rather
     * than on whatever it was testing. The number is what keeps them apart; a
     * test that cares what the handle says sets it itself.
     */
    private function handleFor(string $network): string
    {
        $n = $this->faker->unique()->numberBetween(1, 99_999);

        return match ($network) {
            Networks::MASTODON => '@kargah'.$n.'@mastodon.test',
            Networks::BLUESKY => '@kargah'.$n.'.bsky.social',
            Networks::LINKEDIN => 'in/morpheusadam-'.$n,
            Networks::TELEGRAM => '@kargah_buildlog_'.$n,
            default => '@kargah'.$n,
        };
    }
}
