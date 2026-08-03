<?php

namespace Modules\Project\Support;

/**
 * The five kinds of custom field Trello parity asks for, and nothing else.
 *
 * A field's type is immutable after creation — enforced on
 * `Modules\Project\Models\CustomField` itself, not only here — so this enum
 * only needs to describe a type, never to migrate a value from one to another.
 */
enum CustomFieldType: string
{
    case Checkbox = 'checkbox';
    case Date = 'date';
    case Dropdown = 'dropdown';
    case Number = 'number';
    case Text = 'text';

    /** What the picker in board settings shows. */
    public function label(): string
    {
        return match ($this) {
            self::Checkbox => 'Checkbox',
            self::Date => 'Date',
            self::Dropdown => 'Dropdown',
            self::Number => 'Number',
            self::Text => 'Text',
        };
    }

    /** A keenicons name, already checked against the bundle. */
    public function icon(): string
    {
        return match ($this) {
            self::Checkbox => 'ki-check-squared',
            self::Date => 'ki-calendar',
            self::Dropdown => 'ki-down-square',
            self::Number => 'ki-text-number',
            self::Text => 'ki-text',
        };
    }

    /** Only a dropdown carries an `options` array. */
    public function hasOptions(): bool
    {
        return $this === self::Dropdown;
    }
}
