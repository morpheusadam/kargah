<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Appearance — Kargah')]
class extends Component
{
    public string $theme = 'light';

    public string $accent = 'blue';

    public string $density = 'comfortable';

    public bool $sidebarCollapsed = false;

    public bool $reduceMotion = false;

    public function with(): array
    {
        return [
            'themes' => [
                ['key' => 'light',  'label' => 'Light',  'icon' => 'ki-sun'],
                ['key' => 'dark',   'label' => 'Dark',   'icon' => 'ki-moon'],
                ['key' => 'system', 'label' => 'System', 'icon' => 'ki-screen'],
            ],
            'accents' => [
                ['key' => 'blue',   'label' => 'Blue',   'class' => 'bg-primary'],
                ['key' => 'green',  'label' => 'Green',  'class' => 'bg-success'],
                ['key' => 'amber',  'label' => 'Amber',  'class' => 'bg-warning'],
                ['key' => 'rose',   'label' => 'Rose',   'class' => 'bg-destructive'],
            ],
            'densities' => [
                ['key' => 'compact',     'label' => 'Compact',     'hint' => 'More rows on screen'],
                ['key' => 'comfortable', 'label' => 'Comfortable', 'hint' => 'Default spacing'],
                ['key' => 'relaxed',     'label' => 'Relaxed',     'hint' => 'Easier to scan'],
            ],
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div>
        <h1 class="text-xl font-semibold text-mono">Settings</h1>
        <p class="text-sm text-secondary-foreground mt-1">How Kargah behaves for you.</p>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        <div class="col-span-12 lg:col-span-3">
            @include('partials.settings-nav')
        </div>

        <div class="col-span-12 lg:col-span-9 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Theme</h3></div>
                <div class="kt-card-content p-5">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        @foreach ($themes as $t)
                            <button wire:click="$set('theme', '{{ $t['key'] }}')"
                                    class="flex flex-col items-center gap-3 p-4 rounded-lg border transition-colors
                                           {{ $theme === $t['key'] ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40' }}">
                                <div class="w-full h-20 rounded-md border border-border overflow-hidden flex">
                                    <div class="w-1/3 {{ $t['key'] === 'dark' ? 'bg-neutral-800' : 'bg-muted' }}"></div>
                                    <div class="w-2/3 {{ $t['key'] === 'dark' ? 'bg-neutral-900' : 'bg-background' }}"></div>
                                </div>
                                <span class="flex items-center gap-2 text-sm font-medium {{ $theme === $t['key'] ? 'text-primary' : 'text-mono' }}">
                                    <i class="ki-filled {{ $t['icon'] }}"></i> {{ $t['label'] }}
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Accent colour</h3></div>
                <div class="kt-card-content p-5 flex flex-wrap gap-3">
                    @foreach ($accents as $a)
                        <button wire:click="$set('accent', '{{ $a['key'] }}')"
                                title="{{ $a['label'] }}"
                                class="flex items-center gap-2 px-3 py-2 rounded-lg border transition-colors
                                       {{ $accent === $a['key'] ? 'border-primary' : 'border-border hover:border-primary/40' }}">
                            <span class="size-4 rounded-full {{ $a['class'] }}"></span>
                            <span class="text-sm">{{ $a['label'] }}</span>
                            @if ($accent === $a['key'])<i class="ki-filled ki-check text-primary text-sm"></i>@endif
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Density</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-2">
                    @foreach ($densities as $d)
                        <button wire:click="$set('density', '{{ $d['key'] }}')"
                                class="flex items-center justify-between px-4 py-3 rounded-lg border text-start transition-colors
                                       {{ $density === $d['key'] ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40' }}">
                            <span>
                                <span class="block text-sm font-medium text-mono">{{ $d['label'] }}</span>
                                <span class="block text-xs text-muted-foreground">{{ $d['hint'] }}</span>
                            </span>
                            @if ($density === $d['key'])<i class="ki-filled ki-check-circle text-primary"></i>@endif
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Behaviour</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-4">
                    <label class="flex items-center justify-between gap-4">
                        <span>
                            <span class="block text-sm font-medium text-mono">Start with the sidebar collapsed</span>
                            <span class="block text-xs text-muted-foreground">More room for boards and the inbox.</span>
                        </span>
                        <span class="kt-switch shrink-0"><input type="checkbox" wire:model="sidebarCollapsed"></span>
                    </label>
                    <label class="flex items-center justify-between gap-4">
                        <span>
                            <span class="block text-sm font-medium text-mono">Reduce motion</span>
                            <span class="block text-xs text-muted-foreground">Disables slide and fade transitions.</span>
                        </span>
                        <span class="kt-switch shrink-0"><input type="checkbox" wire:model="reduceMotion"></span>
                    </label>
                </div>
            </div>

        </div>
    </div>
</div>
