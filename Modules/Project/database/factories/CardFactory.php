<?php

namespace Modules\Project\Database\Factories;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Support\Position;

class CardFactory extends Factory
{
    protected $model = Card::class;

    public function definition(): array
    {
        return [
            'board_list_id' => BoardList::factory(),
            'title' => $this->faker->randomElement([
                'Rewrite the portfolio landing copy',
                'Send the Northwind retainer proposal',
                'Fix invoice PDF margins',
                'Scope the Bluepeak booking widget',
                'Chase the Harbour & Finch deposit',
                'Migrate Acme Studio off shared hosting',
                'Draft the Q3 expense summary',
                'Renew the wildcard certificate',
                'Write the hand-over notes for Orbit Studio',
                'Reconcile the July card statement',
            ]),
            'description' => $this->faker->optional()->paragraph(3),
            // decimal(20,10), and every value that reaches it comes through
            // `Position` — a float literal here is how two cards end up sharing
            // a position and the list stops having an order.
            'position' => Position::format(
                BigDecimal::of(Position::STEP)->multipliedBy($this->faker->unique()->numberBetween(1, 512)),
            ),
            'customer_id' => null,
            'company_id' => null,
            'due_on' => null,
            'created_by' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()->subDays(10)]);
    }

    /** Past its due date and not ticked off — what the board colours red. */
    public function overdue(): static
    {
        return $this->state(fn () => [
            'due_on' => now()->subDays($this->faker->numberBetween(1, 14))->toDateString(),
            'completed_at' => null,
        ]);
    }

    /** Inside the seven-day window the board and the filter panel both use. */
    public function dueSoon(): static
    {
        return $this->state(fn () => [
            'due_on' => now()->addDays($this->faker->numberBetween(0, 7))->toDateString(),
            'completed_at' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => ['completed_at' => now()->subDays(2)]);
    }
}
