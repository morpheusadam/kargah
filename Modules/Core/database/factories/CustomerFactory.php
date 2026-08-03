<?php

namespace Modules\Core\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Customer;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'company_id' => null,
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->optional()->phoneNumber(),
            'role' => $this->faker->optional()->jobTitle(),
            'timezone' => $this->faker->randomElement(['Europe/London', 'Europe/Berlin', 'Asia/Istanbul', 'UTC']),
            'notes' => null,
        ];
    }

    public function withoutEmail(): static
    {
        return $this->state(fn () => ['email' => null]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()->subDays(14)]);
    }
}
