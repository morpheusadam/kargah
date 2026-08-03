<?php

namespace Modules\Project\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\Card;
use Modules\Project\Models\CardPlacement;
use Modules\Project\Support\Position;

class CardFactory extends Factory
{
    protected $model = Card::class;

    public function definition(): array
    {
        return [
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
            'customer_id' => null,
            'company_id' => null,
            'due_on' => null,
            'created_by' => null,
        ];
    }

    /**
     * Every card the factory makes ends up in exactly one list.
     *
     * A card with no origin placement is on no board and in no archive, so it
     * is not a state anything should be able to produce by accident. `inList()`
     * names the list; without it the card gets one of its own, which is what a
     * bare `Card::factory()->create()` in another module's test relies on.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Card $card): void {
            if ($card->placements()->exists()) {
                return;
            }

            $list = $card->relationLoaded('list') && $card->getRelation('list') instanceof BoardList
                ? $card->getRelation('list')
                : BoardList::factory()->create();

            $last = CardPlacement::query()
                ->where('board_list_id', $list->id)
                ->orderByDesc('position')
                ->value('position');

            CardPlacement::query()->create([
                'card_id' => $card->id,
                'board_list_id' => $list->id,
                'position' => Position::after($last === null ? null : Position::format((string) $last)),
                'is_origin' => true,
                'created_by' => $card->created_by,
            ]);
        });
    }

    /**
     * Put the card at the bottom of a list that already exists.
     *
     * Set on the *made* model rather than passed to the creating hook, because
     * a hook added here would run after the one `configure()` registers and the
     * card would already have been placed in a list of its own.
     */
    public function inList(BoardList $list): static
    {
        return $this->afterMaking(fn (Card $card) => $card->setRelation('list', $list));
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
