<?php

namespace Modules\Core\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Notification;

class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        $events = [
            'card.commented' => 'Sam Okafor commented on “Rebuild the pricing page”',
            'card.due_soon' => '“Send the Q3 retainer” is due tomorrow',
            'invoice.overdue' => 'INV-0041 to Northwind Ltd is 12 days overdue',
            'email.received' => 'New message from jonas@meridianstudio.example',
        ];

        $event = $this->faker->randomElement(array_keys($events));

        return [
            'user_id' => User::factory(),
            'subject_type' => null,
            'subject_id' => null,
            'event' => $event,
            'title' => $events[$event],
            'body' => null,
            'url' => '/dashboard',
            'actor_id' => null,
            'read_at' => null,
            'dedupe_key' => null,
            'created_at' => now()->subMinutes($this->faker->numberBetween(1, 4320)),
        ];
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()->subHour()]);
    }

    public function olderThan(int $days): static
    {
        return $this->state(fn () => ['created_at' => now()->subDays($days)->subHour()]);
    }
}
