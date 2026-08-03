<?php

namespace Modules\Data\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Data\Models\CredentialCategory;

class CredentialCategoryFactory extends Factory
{
    protected $model = CredentialCategory::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->randomElement(['Hosting', 'Email', 'Development', 'Banking', 'Clients', 'Domains']),
            'colour' => $this->faker->randomElement(['primary', 'success', 'info', 'warning', 'destructive', 'neutral']),
            'position' => $this->faker->numberBetween(0, 10),
        ];
    }
}
