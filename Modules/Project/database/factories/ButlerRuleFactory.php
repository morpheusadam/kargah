<?php

namespace Modules\Project\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Project\Butler\Kind;
use Modules\Project\Butler\Triggers;
use Modules\Project\Models\Board;
use Modules\Project\Models\BoardList;
use Modules\Project\Models\ButlerRule;
use Modules\Project\Models\Label;

class ButlerRuleFactory extends Factory
{
    protected $model = ButlerRule::class;

    /**
     * The default is a rule that does nothing: a trigger, no conditions, no
     * actions. Deliberately inert — a factory whose default state moved cards
     * about would make every unrelated test that happened to create a board
     * mysteriously slower and occasionally wrong.
     */
    public function definition(): array
    {
        return [
            'board_id' => Board::factory(),
            'kind' => Kind::RULE,
            'name' => rtrim($this->faker->sentence(3), '.'),
            'trigger' => Triggers::CARD_CREATED,
            'trigger_config' => [],
            'conditions' => [],
            'actions' => [],
            'is_enabled' => true,
            'position' => 0,
        ];
    }

    public function on(Board $board): static
    {
        return $this->state(fn (): array => ['board_id' => $board->id]);
    }

    public function cardButton(): static
    {
        return $this->state(fn (): array => [
            'kind' => Kind::CARD_BUTTON,
            'trigger' => null,
            'trigger_config' => [],
        ]);
    }

    public function boardButton(): static
    {
        return $this->state(fn (): array => [
            'kind' => Kind::BOARD_BUTTON,
            'trigger' => null,
            'trigger_config' => [],
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (): array => ['is_enabled' => false]);
    }

    /** Listening for a card arriving in one particular list. */
    public function whenMovedInto(BoardList $list): static
    {
        return $this->state(fn (): array => [
            'trigger' => Triggers::CARD_MOVED_INTO_LIST,
            'trigger_config' => ['list_id' => $list->id],
        ]);
    }

    /** The chain, given as `[['action' => …, 'value' => …], …]`. */
    public function doing(array $actions): static
    {
        return $this->state(fn (): array => ['actions' => $actions]);
    }

    public function requiring(array $conditions): static
    {
        return $this->state(fn (): array => ['conditions' => $conditions]);
    }

    /** The self-triggering rule the loop guard exists for. */
    public function selfTriggering(Label $label): static
    {
        return $this->state(fn (): array => [
            'trigger' => Triggers::CARD_LABEL_ADDED,
            'trigger_config' => [],
            'actions' => [['action' => 'add_label', 'value' => $label->id]],
        ]);
    }
}
