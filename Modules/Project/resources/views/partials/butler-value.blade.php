{{--
    One argument control, shaped by what the trigger, condition or action asks
    for. Included from ⚡butler.blade.php, which passes `$arg`, `$model` and an
    optional `$placeholder`; `$lists`, `$labels`, `$people` and `$sorts` come
    from the component's own `with()` and are inherited by the include.

    Deliberately not a Livewire component: it holds no state of its own and
    binds straight into the parent's arrays. A nested component here would need
    a round trip per keystroke to tell the parent what was typed.
--}}
@switch($arg)
    @case('list')
        <select class="kt-select w-full" wire:model="{{ $model }}" aria-label="{{ $placeholder ?? 'List' }}">
            <option value="">Any list</option>
            @foreach ($lists as $list)
                <option value="{{ $list->id }}">{{ $list->name }}</option>
            @endforeach
        </select>
        @break

    @case('label')
        <select class="kt-select w-full" wire:model="{{ $model }}" aria-label="{{ $placeholder ?? 'Label' }}">
            <option value="">Any label</option>
            @foreach ($labels as $label)
                <option value="{{ $label->id }}">{{ $label->name ?: \Modules\Project\Support\Palette::name($label->colour) }}</option>
            @endforeach
        </select>
        @break

    @case('member')
        <select class="kt-select w-full" wire:model="{{ $model }}" aria-label="{{ $placeholder ?? 'Member' }}">
            <option value="">Anybody</option>
            @foreach ($people as $person)
                <option value="{{ $person->id }}">{{ $person->name }}</option>
            @endforeach
        </select>
        @break

    @case('sort')
        <select class="kt-select w-full" wire:model="{{ $model }}" aria-label="{{ $placeholder ?? 'Order' }}">
            <option value="">Pick an order…</option>
            @foreach ($sorts as $key => $sortLabel)
                <option value="{{ $key }}">{{ $sortLabel }}</option>
            @endforeach
        </select>
        @break

    @case('number')
        <input type="number" class="kt-input w-full" wire:model="{{ $model }}"
               placeholder="{{ $placeholder ?? 'a number' }}">
        @break

    @case('date')
        <input type="date" class="kt-input w-full" wire:model="{{ $model }}">
        @break

    @default
        <input type="text" class="kt-input w-full" wire:model="{{ $model }}"
               placeholder="{{ $placeholder ?? 'text' }}">
@endswitch
