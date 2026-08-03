<?php

namespace Modules\Project\Database\Factories;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Project\Models\Card;
use Modules\Project\Models\Checklist;
use Modules\Project\Support\Position;

class ChecklistFactory extends Factory
{
    protected $model = Checklist::class;

    public function definition(): array
    {
        return [
            'card_id' => Card::factory(),
            // The drawer flattens checklists, so a card carrying one list keeps
            // the column's own default name and reads as a bare list of items.
            'name' => 'Checklist',
            'position' => Position::format(
                BigDecimal::of(Position::STEP)->multipliedBy($this->faker->unique()->numberBetween(1, 512)),
            ),
        ];
    }

    /** A second, named checklist — 'Acceptance', 'Pre-flight', and so on. */
    public function named(?string $name = null): static
    {
        return $this->state(fn () => [
            'name' => $name ?? $this->faker->randomElement([
                'Acceptance',
                'Pre-flight',
                'Hand-over',
                'Billing',
            ]),
        ]);
    }
}
