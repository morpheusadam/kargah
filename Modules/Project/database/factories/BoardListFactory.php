<?php

namespace Modules\Project\Database\Factories;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Support\Position;

class BoardListFactory extends Factory
{
    protected $model = BoardList::class;

    public function definition(): array
    {
        return [
            'board_id' => Board::factory(),
            'name' => $this->faker->randomElement([
                'Backlog',
                'To Do',
                'In Progress',
                'Review',
                'Blocked',
                'Waiting on Client',
                'Done',
            ]),
            // A whole multiple of the step, never a float literal: the column is
            // decimal(20,10) and the arithmetic that reorders it is decimal too.
            // `unique()` keeps a batch of lists from landing on one another.
            'position' => Position::format(
                BigDecimal::of(Position::STEP)->multipliedBy($this->faker->unique()->numberBetween(1, 512)),
            ),
            'created_by' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()->subDays(7)]);
    }
}
