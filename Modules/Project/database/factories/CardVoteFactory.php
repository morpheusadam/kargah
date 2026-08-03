<?php

namespace Modules\Project\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardVote;

class CardVoteFactory extends Factory
{
    protected $model = CardVote::class;

    public function definition(): array
    {
        return [
            'card_id' => Card::factory(),
            'user_id' => User::factory(),
        ];
    }

    /** The usual case in a test: a named person's vote, on a card that already exists. */
    public function by(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->id]);
    }
}
