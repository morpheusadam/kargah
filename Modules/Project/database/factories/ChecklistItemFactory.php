<?php

namespace Modules\Project\Database\Factories;

use App\Models\User;
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
            // The advanced pair is off by default. Most items carry neither,
            // and a factory that assigned everybody to everything would make
            // "an item with an assignee" impossible to test the absence of.
            'assigned_to' => null,
            'due_on' => null,
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

    /** An advanced item: somebody is carrying it, and it is owed by a day. */
    public function advanced(?int $userId = null): static
    {
        return $this->state(fn (): array => [
            'assigned_to' => $userId ?? User::factory(),
            'due_on' => now()->addDays($this->faker->numberBetween(1, 21))->toDateString(),
        ]);
    }

    /** Due on a day that has already passed, and not ticked. */
    public function overdue(): static
    {
        return $this->state(fn (): array => [
            'is_done' => false,
            'due_on' => now()->subDays($this->faker->numberBetween(1, 10))->toDateString(),
        ]);
    }
}
