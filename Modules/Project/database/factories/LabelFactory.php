<?php

namespace Modules\Project\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Project\Models\Board;
use Modules\Project\Models\Label;
use Modules\Project\Support\Palette;

class LabelFactory extends Factory
{
    protected $model = Label::class;

    public function definition(): array
    {
        return [
            'board_id' => Board::factory(),
            'name' => $this->faker->randomElement([
                'Copywriting',
                'Outreach',
                'Development',
                'Bug',
                'Finance',
                'Admin',
                'Design',
                'Hosting',
            ]),
            // A palette key, resolved to classes by `Palette`. Drawn from
            // Trello's own ten rather than the full key set — a freshly made
            // label picks from what the board settings UI actually offers.
            // Anything outside the whole palette falls back to grey, which
            // reads as a bug in the UI.
            'colour' => $this->faker->randomElement(Palette::labelColours()),
            'position' => $this->faker->numberBetween(0, 20),
        ];
    }

    /** Pin the label to one palette key, for tests that assert on colour. */
    public function colour(string $colour): static
    {
        return $this->state(fn () => ['colour' => $colour]);
    }
}
