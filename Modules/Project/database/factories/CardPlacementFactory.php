<?php

namespace Modules\Project\Database\Factories;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Support\Position;

class CardPlacementFactory extends Factory
{
    protected $model = CardPlacement::class;

    public function definition(): array
    {
        return [
            'card_id' => Card::factory(),
            'board_list_id' => BoardList::factory(),
            // decimal(20,10), and every value that reaches it comes through
            // `Position` — a float literal here is how two placements end up
            // sharing a position and the list stops having an order.
            'position' => Position::format(
                BigDecimal::of(Position::STEP)->multipliedBy($this->faker->unique()->numberBetween(1, 512)),
            ),
            'is_origin' => false,
            'created_by' => null,
        ];
    }

    /** Where the card lives. Exactly one placement per card may be this. */
    public function origin(): static
    {
        return $this->state(fn (): array => ['is_origin' => true]);
    }

    /** The card shown somewhere other than where it lives. */
    public function mirror(): static
    {
        return $this->state(fn (): array => ['is_origin' => false]);
    }
}
