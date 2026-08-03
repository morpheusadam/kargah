<?php

namespace Modules\Project\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Project\Models\Card;
use Modules\Project\Models\CustomField;
use Modules\Project\Models\CustomFieldValue;

class CustomFieldValueFactory extends Factory
{
    protected $model = CustomFieldValue::class;

    /**
     * No type-appropriate default: a row made without a state is a bug in
     * whatever test made it, not a plausible fixture, so this deliberately
     * leaves every value column null rather than guessing one.
     */
    public function definition(): array
    {
        return [
            'custom_field_id' => CustomField::factory(),
            'card_id' => Card::factory(),
            'value_text' => null,
            'value_number' => null,
            'value_date' => null,
            'value_boolean' => null,
            'value_option_id' => null,
        ];
    }

    public function text(string $value): static
    {
        return $this->state(fn (): array => ['value_text' => $value]);
    }

    public function number(string|int|float $value): static
    {
        return $this->state(fn (): array => ['value_number' => (string) $value]);
    }

    public function date(string $value): static
    {
        return $this->state(fn (): array => ['value_date' => $value]);
    }

    public function boolean(bool $value): static
    {
        return $this->state(fn (): array => ['value_boolean' => $value]);
    }

    public function option(int $optionId): static
    {
        return $this->state(fn (): array => ['value_option_id' => $optionId]);
    }
}
