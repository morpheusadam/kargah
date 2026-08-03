<?php

namespace Modules\Project\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardUserState;

class BoardUserStateFactory extends Factory
{
    protected $model = BoardUserState::class;

    /**
     * Defaults to a board somebody has looked at but not starred — the common
     * row, and the one that would otherwise be easy to forget exists. Reach
     * for `->starred()` when the test is about the star.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'board_id' => Board::factory(),
            'starred_at' => null,
            'last_viewed_at' => now()->subHours($this->faker->numberBetween(1, 72)),
        ];
    }

    public function starred(): static
    {
        return $this->state(fn (): array => ['starred_at' => now()->subDays(3)]);
    }

    /** Starred without ever having been opened — the row `unstarFor()` must leave behind rather than delete. */
    public function neverViewed(): static
    {
        return $this->state(fn (): array => ['last_viewed_at' => null]);
    }
}
