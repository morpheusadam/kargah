<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Social accounts — Kargah')]
class extends Component
{
    public function with(): array
    {
        return [
            'accounts' => [
                ['network' => 'Telegram',  'handle' => '@kargah_bot',    'icon' => 'ki-send',        'connected' => true,  'note' => 'Bot API token'],
                ['network' => 'LinkedIn',  'handle' => 'in/morpheusadam','icon' => 'ki-abstract-41', 'connected' => true,  'note' => 'OAuth'],
                ['network' => 'X',         'handle' => '—',              'icon' => 'ki-abstract-39', 'connected' => false, 'note' => 'OAuth 2.0'],
                ['network' => 'Instagram', 'handle' => '—',              'icon' => 'ki-instagram',   'connected' => false, 'note' => 'Graph API'],
            ],
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div>
        <h1 class="text-xl font-semibold text-mono">Social accounts</h1>
        <p class="text-sm text-secondary-foreground mt-1">Connect a network once; publishing and notifications follow.</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
        @foreach ($accounts as $a)
            <div class="kt-card">
                <div class="kt-card-content p-5 flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="inline-flex items-center justify-center size-11 rounded-lg bg-primary/10 text-primary shrink-0">
                            <i class="ki-filled {{ $a['icon'] }} text-xl"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="font-semibold text-mono">{{ $a['network'] }}</div>
                            <div class="text-sm text-secondary-foreground truncate">{{ $a['handle'] }}</div>
                            <div class="text-xs text-muted-foreground">{{ $a['note'] }}</div>
                        </div>
                    </div>
                    @if ($a['connected'])
                        <div class="flex flex-col items-end gap-2 shrink-0">
                            <span class="kt-badge kt-badge-sm kt-badge-success">Connected</span>
                            <button class="kt-btn kt-btn-sm kt-btn-ghost text-destructive">Disconnect</button>
                        </div>
                    @else
                        <button class="kt-btn kt-btn-sm kt-btn-primary shrink-0">Connect</button>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
