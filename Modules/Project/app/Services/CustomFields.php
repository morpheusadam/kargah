<?php

namespace Modules\Project\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Project\Models\Board;
use Modules\Project\Models\Card;
use Modules\Project\Models\CustomField;
use Modules\Project\Models\CustomFieldValue;
use Modules\Project\Support\CustomFieldType;
use RuntimeException;

/**
 * Everything that defines a board's custom fields and values a card's answers
 * to them.
 *
 * Two caps are enforced here rather than at the database, because both need a
 * sentence a person can read, not a constraint violation: fifty fields per
 * board, fifty options on a dropdown. Both numbers come straight from the
 * spec.
 */
class CustomFields
{
    public const MAX_FIELDS_PER_BOARD = 50;

    public const MAX_OPTIONS_PER_FIELD = 50;

    /* Definitions -------------------------------------------------------- */

    /** Every field a board has, in the order board settings shows them. */
    public function fieldsFor(Board $board): Collection
    {
        return CustomField::query()->where('board_id', $board->id)->ordered()->get();
    }

    public function define(Board $board, string $name, CustomFieldType $type): CustomField
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException('A custom field needs a name.');
        }

        $count = CustomField::query()->where('board_id', $board->id)->count();

        if ($count >= self::MAX_FIELDS_PER_BOARD) {
            throw new RuntimeException(
                $board->name.' already has '.self::MAX_FIELDS_PER_BOARD.' custom fields, which is the most a board can carry.',
            );
        }

        $position = ((int) CustomField::query()->where('board_id', $board->id)->max('position')) + 1;

        return CustomField::query()->create([
            'board_id' => $board->id,
            'name' => $name,
            'type' => $type,
            'options' => $type->hasOptions() ? [] : null,
            'position' => $position,
        ]);
    }

    public function rename(CustomField $field, string $name): CustomField
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException('A custom field needs a name.');
        }

        if ($field->name !== $name) {
            $field->forceFill(['name' => $name])->save();
        }

        return $field;
    }

    /**
     * Move a field one place earlier or later among its board's fields.
     *
     * A plain integer swap, not the fractional column `Position` gives cards
     * and lists: fifty rows is not the scale that column exists for, and a
     * board's custom fields are reordered rarely enough that renumbering the
     * two neighbours involved is not a cost worth avoiding.
     */
    public function move(CustomField $field, int $direction): CustomField
    {
        $siblings = $this->fieldsFor($field->board)->values();
        $index = $siblings->search(fn (CustomField $candidate): bool => $candidate->id === $field->id);

        if ($index === false) {
            return $field;
        }

        $target = $index + $direction;

        if ($target < 0 || $target >= $siblings->count()) {
            return $field;
        }

        $swap = $siblings[$target];

        DB::transaction(function () use ($field, $swap): void {
            $fieldPosition = $field->position;
            $swapPosition = $swap->position;

            $field->forceFill(['position' => $swapPosition])->save();
            $swap->forceFill(['position' => $fieldPosition])->save();
        });

        return $field->refresh();
    }

    /**
     * Delete a definition and every value it has, in one transaction.
     *
     * Trello's own behaviour, and it is destructive on purpose: the caller is
     * expected to have already confirmed with the count this returns.
     *
     * @return int the number of card values deleted with the field
     */
    public function delete(CustomField $field): int
    {
        return DB::transaction(function () use ($field): int {
            $count = CustomFieldValue::query()->where('custom_field_id', $field->id)->count();

            CustomFieldValue::query()->where('custom_field_id', $field->id)->delete();
            $field->delete();

            return $count;
        });
    }

    /** How many values a definition currently carries — for the confirmation prompt. */
    public function valueCount(CustomField $field): int
    {
        return CustomFieldValue::query()->where('custom_field_id', $field->id)->count();
    }

    /* Dropdown options ----------------------------------------------------- */

    public function addOption(CustomField $field, string $label): CustomField
    {
        $this->assertDropdown($field);

        $label = trim($label);

        if ($label === '') {
            throw new RuntimeException('An option needs a label.');
        }

        $options = $field->options();

        if (count($options) >= self::MAX_OPTIONS_PER_FIELD) {
            throw new RuntimeException(
                $field->name.' already has '.self::MAX_OPTIONS_PER_FIELD.' options, which is the most a dropdown can carry.',
            );
        }

        $options[] = ['id' => $field->nextOptionId(), 'label' => $label];

        $field->forceFill(['options' => $options])->save();

        return $field;
    }

    /** Renaming keeps the option's id, so every card already set to it stays set to it. */
    public function renameOption(CustomField $field, int $optionId, string $label): CustomField
    {
        $this->assertDropdown($field);

        $label = trim($label);

        if ($label === '') {
            throw new RuntimeException('An option needs a label.');
        }

        $options = array_map(
            fn (array $option): array => $option['id'] === $optionId ? ['id' => $option['id'], 'label' => $label] : $option,
            $field->options(),
        );

        $field->forceFill(['options' => $options])->save();

        return $field;
    }

    /**
     * Remove an option. Any card set to it is cleared, not left pointing at an
     * id that no longer means anything.
     */
    public function removeOption(CustomField $field, int $optionId): CustomField
    {
        $this->assertDropdown($field);

        DB::transaction(function () use ($field, $optionId): void {
            $options = array_values(array_filter(
                $field->options(),
                fn (array $option): bool => $option['id'] !== $optionId,
            ));

            $field->forceFill(['options' => $options])->save();

            CustomFieldValue::query()
                ->where('custom_field_id', $field->id)
                ->where('value_option_id', $optionId)
                ->update(['value_option_id' => null]);
        });

        return $field->refresh();
    }

    private function assertDropdown(CustomField $field): void
    {
        if ($field->type !== CustomFieldType::Dropdown) {
            throw new RuntimeException($field->name.' is not a dropdown field.');
        }
    }

    /* Values ---------------------------------------------------------------- */

    public function valueFor(Card $card, CustomField $field): ?CustomFieldValue
    {
        return CustomFieldValue::query()
            ->where('custom_field_id', $field->id)
            ->where('card_id', $card->id)
            ->first();
    }

    /** @return Collection<int, CustomFieldValue> keyed by custom_field_id */
    public function valuesFor(Card $card, Board $board): Collection
    {
        return CustomFieldValue::query()
            ->where('card_id', $card->id)
            ->whereIn('custom_field_id', $this->fieldsFor($board)->pluck('id'))
            ->get()
            ->keyBy('custom_field_id');
    }

    /**
     * Write a card's answer to one field, validating the raw input against the
     * field's type.
     *
     * Idempotent by comparison, not by `updateOrCreate()`: the attributes are
     * assigned to the existing row (or a fresh one) and `save()` is only
     * called when something is actually dirty, so setting the same value twice
     * performs no second write — `save()` is never even reached, let alone an
     * `UPDATE` sent.
     *
     * Returns null when there was nothing before and nothing was given: no row
     * is created for an empty answer.
     */
    public function setValue(Card $card, CustomField $field, mixed $raw): ?CustomFieldValue
    {
        $attributes = $this->attributesFor($field->type, $raw);

        $value = CustomFieldValue::query()->firstOrNew([
            'custom_field_id' => $field->id,
            'card_id' => $card->id,
        ]);

        if (! $value->exists && $this->isBlank($attributes)) {
            return null;
        }

        $value->fill($attributes);

        if ($value->exists && ! $value->isDirty()) {
            return $value;
        }

        // Clearing a value that existed removes the row rather than saving one
        // full of nulls — a row with nothing set is not a fact about the card.
        if ($value->exists && $this->isBlank($attributes)) {
            $value->delete();

            return null;
        }

        $value->custom_field_id = $field->id;
        $value->card_id = $card->id;
        $value->save();

        return $value;
    }

    /** Remove a card's answer to a field entirely. Idempotent: nothing to delete writes nothing. */
    public function clearValue(Card $card, CustomField $field): bool
    {
        return CustomFieldValue::query()
            ->where('custom_field_id', $field->id)
            ->where('card_id', $card->id)
            ->delete() > 0;
    }

    /** @return array<string, mixed> */
    private function attributesFor(CustomFieldType $type, mixed $raw): array
    {
        $empty = $raw === null || $raw === '';

        $blank = [
            'value_text' => null,
            'value_number' => null,
            'value_date' => null,
            'value_boolean' => null,
            'value_option_id' => null,
        ];

        return match ($type) {
            CustomFieldType::Checkbox => [...$blank, 'value_boolean' => $empty ? null : filter_var($raw, FILTER_VALIDATE_BOOLEAN)],

            CustomFieldType::Date => [...$blank, 'value_date' => $empty ? null : $this->parseDate($raw)],

            CustomFieldType::Number => [...$blank, 'value_number' => $empty ? null : $this->parseNumber($raw)],

            CustomFieldType::Dropdown => [...$blank, 'value_option_id' => $empty ? null : (int) $raw],

            CustomFieldType::Text => [...$blank, 'value_text' => $empty ? null : trim((string) $raw)],
        };
    }

    private function parseDate(mixed $raw): string
    {
        try {
            return \Illuminate\Support\Carbon::parse((string) $raw)->toDateString();
        } catch (\Throwable) {
            throw new RuntimeException('That does not read as a date.');
        }
    }

    /**
     * A number typed into a form, kept a string until it reaches the decimal
     * cast. This is not the money layer's `string|float` guard against a
     * silently truncated float — a custom field number is never money — but
     * the same discipline costs nothing here either: `is_numeric()` on the raw
     * input, never an arithmetic float in between.
     */
    private function parseNumber(mixed $raw): string
    {
        $raw = is_string($raw) ? trim($raw) : $raw;

        if (! is_numeric($raw)) {
            throw new RuntimeException('That does not read as a number.');
        }

        return (string) $raw;
    }

    private function isBlank(array $attributes): bool
    {
        foreach ($attributes as $value) {
            if ($value !== null) {
                return false;
            }
        }

        return true;
    }
}
