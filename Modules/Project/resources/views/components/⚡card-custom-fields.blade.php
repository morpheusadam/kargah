<?php

use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Project\Models\Board;
use Modules\Project\Models\Card;
use Modules\Project\Models\CustomField;
use Modules\Project\Services\CustomFields;
use Modules\Project\Support\CustomFieldType;

// No `use RuntimeException;` — Livewire 4 compiles this file's class block
// into a namespaced class, where PHP's warning about a no-effect import of a
// root-namespace name gets promoted to a fatal `ErrorException` by Laravel's
// error handler. `\RuntimeException` at the throw site instead.

/**
 * The custom fields section of the card back.
 *
 * Nested inside the card drawer: `<livewire:project::card-custom-fields :card-id="$card->id" />`.
 *
 * Takes a card id only, on purpose — the same contract the drawer itself uses
 * for labels, which come from `$card->list->board` rather than from whichever
 * board's canvas happened to open it. A mirrored card therefore always shows
 * the custom fields belonging to the board it *lives on*, its origin, never a
 * board it is merely mirrored onto. One rule ("the origin board's fields")
 * instead of "whichever board opened the drawer", and it matches how this
 * card back already treats labels.
 *
 * Every write goes through `Modules\Project\Services\CustomFields`, which is
 * also what makes typing the same value in twice a no-op the second time.
 *
 * No toast on a successful save: the field already shows what was typed or
 * picked, so a save toast here would be reporting something the user is
 * already looking at. A failed save still toasts — that is not otherwise
 * visible.
 */
new class extends Component
{
    use InteractsWithToasts;

    public int $cardId;

    /** custom_field_id => the value being typed or picked, before it is saved. */
    public array $drafts = [];

    private ?Card $resolvedCard = null;

    public function mount(int $cardId): void
    {
        $this->cardId = $cardId;

        $card = $this->card();
        $board = $card?->list?->board;

        if ($card === null || $board === null) {
            return;
        }

        $service = app(CustomFields::class);
        $values = $service->valuesFor($card, $board);

        foreach ($service->fieldsFor($board) as $field) {
            $this->drafts[$field->id] = $this->draftFor($field, $values->get($field->id));
        }
    }

    /* Reading -------------------------------------------------------------- */

    private function card(): ?Card
    {
        return $this->resolvedCard ??= Card::query()->with('list.board')->find($this->cardId);
    }

    private function board(): ?Board
    {
        return $this->card()?->list?->board;
    }

    public function with(): array
    {
        $card = $this->card();
        $board = $this->board();

        if ($card === null || $board === null) {
            return ['fields' => collect(), 'values' => collect()];
        }

        $service = app(CustomFields::class);

        return [
            'fields' => $service->fieldsFor($board),
            'values' => $service->valuesFor($card, $board),
        ];
    }

    private function draftFor(CustomField $field, mixed $value): mixed
    {
        return match ($field->type) {
            CustomFieldType::Checkbox => (bool) ($value?->value_boolean),
            CustomFieldType::Date => $value?->value_date?->toDateString() ?? '',
            CustomFieldType::Number => $value?->value_number === null ? '' : (string) $value->value_number,
            CustomFieldType::Dropdown => $value?->value_option_id === null ? '' : (string) $value->value_option_id,
            CustomFieldType::Text => $value?->value_text ?? '',
        };
    }

    /* Writing ---------------------------------------------------------------- */

    /** A checkbox saves the instant it is clicked — nothing else to confirm. */
    public function toggleCheckbox(int $fieldId): void
    {
        $field = $this->fieldOnThisBoard($fieldId);

        if ($field === null) {
            return;
        }

        $this->drafts[$fieldId] = ! ($this->drafts[$fieldId] ?? false);

        $this->write($field, $this->drafts[$fieldId] ? '1' : '');
    }

    /** A date, dropdown, number or text field saves on blur, change, or Enter. */
    public function setValue(int $fieldId): void
    {
        $field = $this->fieldOnThisBoard($fieldId);

        if ($field === null) {
            return;
        }

        $this->write($field, $this->drafts[$fieldId] ?? '');
    }

    private function fieldOnThisBoard(int $fieldId): ?CustomField
    {
        $board = $this->board();

        return $board === null ? null : CustomField::query()->where('board_id', $board->id)->find($fieldId);
    }

    private function write(CustomField $field, mixed $raw): void
    {
        $card = $this->card();

        if ($card === null) {
            return;
        }

        try {
            app(CustomFields::class)->setValue($card, $field, $raw);
        } catch (\RuntimeException $e) {
            $this->toastError('Could not save '.$field->name, $e->getMessage());
        }
    }
};

?>

<div>
    @if ($fields->isNotEmpty())
        <div class="flex flex-col gap-3">
            <div class="flex items-center gap-2">
                <i class="ki-filled ki-setting-4 text-sm text-muted-foreground"></i>
                <h3 class="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Custom fields</h3>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach ($fields as $field)
                    <div class="flex flex-col gap-1.5" wire:key="custom-field-{{ $field->id }}">
                        <label class="kt-form-label text-xs flex items-center gap-1.5" for="custom-field-{{ $field->id }}">
                            <i class="ki-filled {{ $field->type->icon() }} text-[11px] text-muted-foreground"></i>
                            {{ $field->name }}
                        </label>

                        @switch($field->type)
                            @case(CustomFieldType::Checkbox)
                                <label class="inline-flex items-center gap-2 py-1.5">
                                    <input type="checkbox" class="kt-checkbox" id="custom-field-{{ $field->id }}"
                                           wire:click="toggleCheckbox({{ $field->id }})"
                                           wire:loading.attr="disabled" wire:target="toggleCheckbox({{ $field->id }})"
                                           @checked($drafts[$field->id] ?? false)>
                                    <span class="text-sm text-secondary-foreground">
                                        {{ ($drafts[$field->id] ?? false) ? 'Yes' : 'No' }}
                                    </span>
                                </label>
                                @break

                            @case(CustomFieldType::Date)
                                <input type="date" id="custom-field-{{ $field->id }}" class="kt-input"
                                       wire:model="drafts.{{ $field->id }}" wire:change="setValue({{ $field->id }})"
                                       wire:loading.attr="disabled" wire:target="setValue({{ $field->id }})">
                                @break

                            @case(CustomFieldType::Dropdown)
                                <select id="custom-field-{{ $field->id }}" class="kt-select"
                                        wire:model="drafts.{{ $field->id }}" wire:change="setValue({{ $field->id }})"
                                        wire:loading.attr="disabled" wire:target="setValue({{ $field->id }})">
                                    <option value="">—</option>
                                    @foreach ($field->options() as $option)
                                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                    @endforeach
                                </select>
                                @break

                            @case(CustomFieldType::Number)
                                <input type="text" inputmode="decimal" id="custom-field-{{ $field->id }}" class="kt-input"
                                       wire:model="drafts.{{ $field->id }}"
                                       wire:blur="setValue({{ $field->id }})" wire:keydown.enter="setValue({{ $field->id }})"
                                       wire:loading.attr="disabled" wire:target="setValue({{ $field->id }})">
                                @break

                            @default
                                <input type="text" id="custom-field-{{ $field->id }}" class="kt-input"
                                       wire:model="drafts.{{ $field->id }}"
                                       wire:blur="setValue({{ $field->id }})" wire:keydown.enter="setValue({{ $field->id }})"
                                       wire:loading.attr="disabled" wire:target="setValue({{ $field->id }})">
                        @endswitch

                        @error('drafts.'.$field->id)
                            <span class="text-xs text-destructive">{{ $message }}</span>
                        @enderror
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
