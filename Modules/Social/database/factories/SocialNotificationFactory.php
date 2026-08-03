<?php

namespace Modules\Social\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Models\SocialNotification;

/**
 * Something a network said happened.
 *
 * `remote_id` is unique per account by construction, because the table's unique
 * index is the whole reason ingestion is safe to re-run and a factory that
 * produced collisions would hide that rather than exercise it.
 */
class SocialNotificationFactory extends Factory
{
    protected $model = SocialNotification::class;

    public function definition(): array
    {
        return [
            'social_account_id' => SocialAccount::factory(),
            'kind' => $this->faker->randomElement([
                SocialNotification::MENTION,
                SocialNotification::REPLY,
                SocialNotification::LIKE,
                SocialNotification::REPOST,
                SocialNotification::FOLLOW,
            ]),
            'remote_id' => 'remote-'.$this->faker->unique()->numberBetween(1, 999_999),
            'actor_handle' => '@'.$this->faker->userName(),
            'excerpt' => $this->faker->randomElement([
                'This is exactly the workflow I was missing.',
                'How are you handling the ordering when two people drag at once?',
                'Reading the build log every week. Keep going.',
            ]),
            'url' => 'https://example.test/notifications/'.$this->faker->unique()->numberBetween(1, 999_999),
            'is_read' => false,
            'occurred_at' => now()->subHours($this->faker->numberBetween(1, 72)),
        ];
    }

    public function read(): static
    {
        return $this->state(fn (): array => ['is_read' => true]);
    }
}
