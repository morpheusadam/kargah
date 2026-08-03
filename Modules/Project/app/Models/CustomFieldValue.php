<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Project\Database\Factories\CustomFieldValueFactory;
use Modules\Project\Support\CustomFieldType;

/**
 * One card's answer to one board's custom field.
 *
 * Four typed columns, not one text column holding everything as a string. A
 * text column sorts "2", "9", "10" lexically — 10 before 2 — which is exactly
 * the wrong answer for "sort this list by a number custom field". A NUMERIC
 * column sorts by value. Only the column matching the field's type is ever
 * non-null; {@see \Modules\Project\Services\CustomFields} is what enforces
 * that on every write, this model does not re-derive it from `field->type` on
 * read because a value never outlives the field's type — the type cannot
 * change (see `CustomField`) and deleting the field deletes every value with
 * it in the same transaction.
 */
class CustomFieldValue extends Model
{
    use HasFactory;

    protected $table = 'custom_field_values';

    protected $fillable = [
        'custom_field_id',
        'card_id',
        'value_text',
        'value_number',
        'value_date',
        'value_boolean',
        'value_option_id',
    ];

    protected function casts(): array
    {
        return [
            // decimal:6 rather than a float cast — Eloquent's decimal cast reads
            // and writes a string, so a value that started as a string typed
            // into a form never becomes a PHP float at any point in this class.
            'value_number' => 'decimal:6',
            'value_date' => 'date',
            'value_boolean' => 'boolean',
        ];
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    /**
     * The value, formatted for the card back. Null means "nothing set", which
     * is different from a checkbox explicitly unset — this project stores that
     * the same way Trello shows it: as absence.
     */
    public function display(?CustomFieldType $type = null): ?string
    {
        $type ??= $this->field?->type;

        return match ($type) {
            CustomFieldType::Checkbox => $this->value_boolean === null ? null : ($this->value_boolean ? 'Yes' : 'No'),
            CustomFieldType::Date => $this->value_date?->format('j M Y'),
            CustomFieldType::Number => $this->value_number === null ? null : rtrim(rtrim((string) $this->value_number, '0'), '.'),
            CustomFieldType::Dropdown => $this->field?->optionLabel($this->value_option_id),
            CustomFieldType::Text => $this->value_text === null || $this->value_text === '' ? null : $this->value_text,
            default => null,
        };
    }

    protected static function newFactory(): CustomFieldValueFactory
    {
        return CustomFieldValueFactory::new();
    }
}
