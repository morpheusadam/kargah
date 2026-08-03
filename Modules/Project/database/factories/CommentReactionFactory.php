<?php

namespace Modules\Project\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Project\Models\CardComment;
use Modules\Project\Models\CommentReaction;
use Modules\Project\Support\Reactions;

class CommentReactionFactory extends Factory
{
    protected $model = CommentReaction::class;

    public function definition(): array
    {
        return [
            'card_comment_id' => CardComment::factory(),
            'user_id' => User::factory(),
            // Always one of the eight the picker offers — a factory that could
            // write an emoji the UI has no chip for would be manufacturing a
            // state the application cannot reach.
            'emoji' => $this->faker->randomElement(Reactions::SET),
        ];
    }

    /** A specific emoji, for the grouped-tally assertions that need to know which. */
    public function withEmoji(string $emoji): static
    {
        return $this->state(fn (): array => ['emoji' => $emoji]);
    }

    public function by(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->id]);
    }
}
