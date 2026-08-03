<?php

namespace Modules\Project\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Project\Models\Board;
use Modules\Project\Models\CustomField;
use Modules\Project\Support\CustomFieldType;

class CustomFieldFactory extends Factory
{
    protected $model = CustomField::class;

    public function definition(): array
    {
        $type = $this->faker->randomElement(CustomFieldType::cases());

        return [
            'board_id' => Board::factory(),
            'name' => $this->faker->randomElement([
                'Priority',
                'Estimated hours',
                'Client tier',
                'Sign-off needed',
                'Kick-off date',
            ]),
            'type' => $type,
            'options' => $type->hasOptions() ? [] : null,
            'position' => $this->faker->numberBetween(0, 20),
        ];
    }

    public function type(CustomFieldType $type): static
    {
        return $this->state(fn (): array => [
            'type' => $type,
            'options' => $type->hasOptions() ? [] : null,
        ]);
    }

    /** A dropdown pre-populated with the given option labels, ids assigned in order. */
    public function withOptions(array $labels): static
    {
        return $this->state(fn (): array => [
            'type' => CustomFieldType::Dropdown,
            'options' => collect($labels)
                ->values()
                ->map(fn (string $label, int $index): array => ['id' => $index + 1, 'label' => $label])
                ->all(),
        ]);
    }
}
