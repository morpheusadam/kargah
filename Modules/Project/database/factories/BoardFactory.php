<?php

namespace Modules\Project\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Project\Models\Board;
use Modules\Project\Support\Palette;

class BoardFactory extends Factory
{
    protected $model = Board::class;

    public function definition(): array
    {
        $name = $this->faker->randomElement([
            'Client Work',
            'Outreach',
            'Personal',
            'Studio Operations',
            'Website Rebuild',
            'Retainers',
            'Content Pipeline',
            'Invoicing',
        ]);

        return [
            // The slug is unique in the schema and the board name is drawn from
            // a short list, so the name alone would collide on the second row.
            // A random suffix keeps `Board::factory()->count(5)->create()` safe
            // without the factory having to query the table it is filling.
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'name' => $name,
            'colour' => $this->faker->randomElement(Palette::keys()),
            'description' => $this->faker->optional()->sentence(10),
            'company_id' => null,
            'position' => $this->faker->numberBetween(0, 20),
            'created_by' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()->subDays(21)]);
    }
}
