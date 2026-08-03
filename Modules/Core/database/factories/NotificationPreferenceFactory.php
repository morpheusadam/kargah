<?php

namespace Modules\Core\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\NotificationPreference;
use Modules\Core\Support\NotificationEvents;

class NotificationPreferenceFactory extends Factory
{
    protected $model = NotificationPreference::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event' => $this->faker->randomElement(array_keys(NotificationEvents::all())),
            'in_app' => true,
            'email' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['in_app' => false, 'email' => false]);
    }
}
