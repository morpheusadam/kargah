<?php

namespace Modules\Data\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Data\Models\Repository;

class RepositoryFactory extends Factory
{
    protected $model = Repository::class;

    public function definition(): array
    {
        $fullName = 'morpheusadam/'.$this->faker->unique()->slug(2);

        return [
            'provider' => 'github',
            'full_name' => $fullName,
            'description' => $this->faker->sentence(),
            'language' => $this->faker->randomElement(['PHP', 'TypeScript', 'JavaScript', 'Python']),
            'default_branch' => 'main',
            'stars' => $this->faker->numberBetween(0, 200),
            'forks' => $this->faker->numberBetween(0, 30),
            'open_issues' => $this->faker->numberBetween(0, 20),
            'is_private' => false,
            'is_archived' => false,
            'html_url' => 'https://github.com/'.$fullName,
            'pushed_at' => now()->subDays($this->faker->numberBetween(0, 120)),
            'synced_at' => now(),
        ];
    }

    public function private(): static
    {
        return $this->state(fn (): array => ['is_private' => true]);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['is_archived' => true]);
    }
}
