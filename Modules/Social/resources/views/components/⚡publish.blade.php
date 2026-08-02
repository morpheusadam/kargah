<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Cross-network composer.
 *
 * Write once, pick targets, publish or schedule. Per-network character limits
 * are enforced live so a post is never silently truncated.
 */
new
#[Title('Publish — Kargah')]
class extends Component
{
    public string $body = '';

    public array $targets = ['telegram'];

    public string $schedule = 'now';

    public function with(): array
    {
        return [
            'networks' => [
                ['key' => 'telegram',  'label' => 'Telegram',  'icon' => 'ki-send',        'limit' => 4096, 'connected' => true],
                ['key' => 'linkedin',  'label' => 'LinkedIn',  'icon' => 'ki-abstract-41', 'limit' => 3000, 'connected' => true],
                ['key' => 'x',         'label' => 'X',         'icon' => 'ki-abstract-39', 'limit' => 280,  'connected' => false],
                ['key' => 'instagram', 'label' => 'Instagram', 'icon' => 'ki-instagram',   'limit' => 2200, 'connected' => false],
            ],
        ];
    }

    public function toggleTarget(string $key): void
    {
        $this->targets = in_array($key, $this->targets, true)
            ? array_values(array_diff($this->targets, [$key]))
            : [...$this->targets, $key];
    }
};

?>

<div class="flex flex-col gap-5">

    <div>
        <h1 class="text-xl font-semibold text-mono">Publish</h1>
        <p class="text-sm text-secondary-foreground mt-1">One post, every network you pick.</p>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        <div class="col-span-12 lg:col-span-8">
            <div class="kt-card">
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <textarea class="kt-textarea min-h-[220px] text-sm"
                              placeholder="What are you shipping today?"
                              wire:model.live="body"></textarea>

                    <div class="flex items-center justify-between">
                        <div class="flex gap-1">
                            <button class="kt-btn kt-btn-icon kt-btn-ghost size-9" title="Image"><i class="ki-filled ki-picture text-base"></i></button>
                            <button class="kt-btn kt-btn-icon kt-btn-ghost size-9" title="Link"><i class="ki-filled ki-link text-base"></i></button>
                            <button class="kt-btn kt-btn-icon kt-btn-ghost size-9" title="Emoji"><i class="ki-filled ki-emoji-happy text-base"></i></button>
                        </div>
                        <span class="text-xs text-muted-foreground">{{ strlen($body) }} characters</span>
                    </div>

                    <div class="border-t border-border pt-4 flex flex-wrap items-center justify-between gap-3">
                        <select class="kt-select max-w-[200px]" wire:model.live="schedule">
                            <option value="now">Publish now</option>
                            <option value="later">Schedule…</option>
                            <option value="draft">Save as draft</option>
                        </select>
                        <button class="kt-btn kt-btn-primary gap-2" @disabled(empty($targets) || $body === '')>
                            <i class="ki-filled ki-send"></i>
                            {{ $schedule === 'now' ? 'Publish' : ($schedule === 'later' ? 'Schedule' : 'Save draft') }}
                        </button>
                    </div>

                </div>
            </div>
        </div>

        <div class="col-span-12 lg:col-span-4">
            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Post to</h3></div>
                <div class="kt-card-content p-3 flex flex-col gap-1">
                    @foreach ($networks as $n)
                        @php
                            $active = in_array($n['key'], $targets, true);
                            $over = $n['limit'] < strlen($body);
                        @endphp
                        <button wire:click="toggleTarget('{{ $n['key'] }}')"
                                @disabled(! $n['connected'])
                                class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-start transition-colors
                                       {{ $active ? 'bg-primary/10' : 'hover:bg-accent/50' }}
                                       {{ $n['connected'] ? '' : 'opacity-50 cursor-not-allowed' }}">
                            <i class="ki-filled {{ $n['icon'] }} text-lg shrink-0 {{ $active ? 'text-primary' : 'text-muted-foreground' }}"></i>
                            <span class="min-w-0 grow">
                                <span class="block text-sm font-medium text-mono">{{ $n['label'] }}</span>
                                <span class="block text-xs {{ $over ? 'text-destructive' : 'text-muted-foreground' }}">
                                    {{ $n['connected'] ? strlen($body) . ' / ' . $n['limit'] : 'Not connected' }}
                                </span>
                            </span>
                            @if ($active)
                                <i class="ki-filled ki-check-circle text-primary text-base shrink-0"></i>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

    </div>
</div>
