<?php

namespace Modules\Project\Database\Factories;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Project\Models\Checklist;
use Modules\Project\Models\ChecklistItem;
use Modules\Project\Support\Position;

class ChecklistItemFactory extends Factory
{
    protected $model = ChecklistItem::class;

    public function definition(): array
    {
        return [
            'checklist_id' => Checklist::factory(),
            'text' => $this->faker->randomElement([
                'Confirm the day rate for next year',
                'Write the scope section',
                'Add the payment terms',
                'Internal read-through',
                'Export to PDF',
                'Send it to the client',
                'Chase a reply after three days',
                'File the signed copy',
            ]),
            'is_done' => false,
            'position' => Position::format(
                BigDecimal::of(Position::STEP)->multipliedBy($this->faker->unique()->numberBetween(1, 512)),
            ),
            'completed_at' => null,
            'created_by' => null,
        ];
    }

    /** Ticked. `completed_at` moves with `is_done`; the two are read together. */
    public function done(): static
    {
        return $this->state(fn () => [
            'is_done' => true,
            'completed_at' => now()->subDays($this->faker->numberBetween(0, 5)),
        ]);
    }
}
