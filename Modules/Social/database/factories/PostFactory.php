<?php

namespace Modules\Social\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Social\Models\Post;

/**
 * A post, in whichever state a test needs it.
 *
 * `status` is never set to something the targets do not support: `published()`
 * only makes sense once a target says so, so the states here set the post's
 * summary and leave the targets to `PostTargetFactory`. That mirrors how
 * `PostPublisher` works, where the post's column is derived and the targets are
 * the truth.
 */
class PostFactory extends Factory
{
    protected $model = Post::class;

    public function definition(): array
    {
        return [
            'body' => $this->faker->randomElement($this->bodies()),
            'media' => null,
            'status' => Post::DRAFT,
            'scheduled_for' => null,
            'published_at' => null,
            'company_id' => null,
            'created_by' => null,
        ];
    }

    /** Scheduled for a time that has not arrived, so `Post::due()` ignores it. */
    public function scheduled(?string $when = null): static
    {
        return $this->state(fn (): array => [
            'status' => Post::SCHEDULED,
            'scheduled_for' => $when === null ? now()->addHours(3) : now()->parse($when),
        ]);
    }

    /** Scheduled for a time that has passed, which is what `social:publish-due` picks up. */
    public function overdue(int $minutesAgo = 5): static
    {
        return $this->state(fn (): array => [
            'status' => Post::SCHEDULED,
            'scheduled_for' => now()->subMinutes($minutesAgo),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => Post::PUBLISHED,
            'scheduled_for' => now()->subDays(2),
            'published_at' => now()->subDays(2),
        ]);
    }

    /** @return list<string> */
    private function bodies(): array
    {
        return [
            'Shipped the drag-and-drop board this week. Cards keep their order after a refresh, which sounds trivial until you try it without a full page reload.',
            'Build log: invoice PDF templates now render right to left without the layout collapsing. Two evenings and one very stubborn font.',
            'Benchmarked the whole application on a small shared host. Median page render 84 ms with no cache warm-up, and no single-page framework anywhere.',
            'Client onboarding checklist I use before writing a line of code. It saves at least one awkward call per project.',
        ];
    }
}
