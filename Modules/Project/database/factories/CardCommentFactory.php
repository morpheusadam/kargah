<?php

namespace Modules\Project\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardComment;

class CardCommentFactory extends Factory
{
    protected $model = CardComment::class;

    public function definition(): array
    {
        return [
            'card_id' => Card::factory(),
            // `created_by` is nullable in the schema, but a thread of unsigned
            // comments is not something the drawer has any use for.
            'created_by' => User::factory(),
            'body' => $this->faker->randomElement([
                'Northwind replied the same day. Two lines, already usable.',
                'Rate looks low next to what we quoted Bluepeak for the same work.',
                'Blocked until the credentials store lands.',
                'Client asked for the invoice to be split across two months.',
                'Moved the call to Thursday at their request.',
                'Deposit cleared this morning, so we can start.',
                'This only reproduces on A4 — letter is fine.',
                'Quoting the export separately, it is out of scope for the first release.',
            ]),
        ];
    }

    /** An unattributed comment, for the nullable `created_by` path. */
    public function anonymous(): static
    {
        return $this->state(fn () => ['created_by' => null]);
    }
}
