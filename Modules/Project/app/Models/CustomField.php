<?php

namespace Modules\Project\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Project\Database\Factories\CustomFieldFactory;
use Modules\Project\Support\CustomFieldType;
use RuntimeException;

/**
 * A field a board's cards can carry, defined once per board.
 *
 * `options` is only ever populated for a {@see CustomFieldType::Dropdown}
 * field: `[{"id": 1, "label": "Bronze"}, ...]`. The id is assigned once, when
 * the option is added, and never reused — renaming an option changes `label`
 * only, so every {@see CustomFieldValue} pointing at that id by number keeps
 * pointing at the right option after a rename. See the migration for why this
 * is JSON on the field rather than a third table.
 *
 * **The type cannot change after creation.** A dropdown whose values are
 * already scored numerically makes no sense as a number field and vice versa,
 * and the spec states the rule without qualification — not "once it has
 * values", always. `booted()` below refuses the write outright, so the guard
 * cannot be bypassed by going around the form.
 */
class CustomField extends Model
{
    use HasFactory;

    protected $fillable = [
        'board_id',
        'name',
        'type',
        'options',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'type' => CustomFieldType::class,
            'options' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (CustomField $field): void {
            if ($field->isDirty('type')) {
                throw new RuntimeException(
                    'A custom field\'s type is fixed once it is created — delete '.$field->name.' and add a new field instead.',
                );
            }
        });
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /** @return list<array{id: int, label: string}> */
    public function options(): array
    {
        return $this->options ?? [];
    }

    /** The label an option id currently carries, or null if it no longer exists. */
    public function optionLabel(?int $optionId): ?string
    {
        if ($optionId === null) {
            return null;
        }

        foreach ($this->options() as $option) {
            if ($option['id'] === $optionId) {
                return $option['label'];
            }
        }

        return null;
    }

    /** The next id to assign an option — one past the highest ever used on this field. */
    public function nextOptionId(): int
    {
        $ids = array_column($this->options(), 'id');

        return $ids === [] ? 1 : max($ids) + 1;
    }

    protected static function newFactory(): CustomFieldFactory
    {
        return CustomFieldFactory::new();
    }
}
