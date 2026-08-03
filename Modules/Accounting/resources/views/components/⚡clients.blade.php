<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

new
#[Title('Clients — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public string $search = '';

    public function with(): array
    {
        return [
            'clients' => [
                ['id' => 1, 'name' => 'Northwind Ltd', 'contact' => 'Sam Okafor',  'email' => 'sam@northwind.example',  'country' => 'UK', 'billed' => '$14,200.00', 'open' => 1],
                ['id' => 2, 'name' => 'Acme Studio',   'contact' => 'Rita Vance',  'email' => 'rita@acme.example',      'country' => 'US', 'billed' => '$6,880.00',  'open' => 1],
                ['id' => 3, 'name' => 'Bluepeak',      'contact' => 'Jonas Reyes', 'email' => 'jonas@bluepeak.example', 'country' => 'DE', 'billed' => '$5,150.00',  'open' => 0],
            ],
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Clients</h1>
            <p class="text-sm text-secondary-foreground mt-1">Everyone you invoice.</p>
        </div>
        <div class="flex items-center gap-2">
            <div class="kt-input max-w-[220px]">
                <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                <input type="text" placeholder="Search clients…" wire:model.live.debounce.300ms="search">
            </div>
            <button class="kt-btn kt-btn-primary gap-2">
                <i class="ki-filled ki-plus"></i> Add client
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        @foreach ($clients as $c)
            <div class="kt-card">
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <div class="flex items-start justify-between gap-3">
                        <a href="{{ route('accounting.client-show', ['client' => $c['id']]) }}" wire:navigate
                           class="flex items-center gap-3 min-w-0 group">
                            <span class="inline-flex items-center justify-center size-11 rounded-lg bg-primary/10 text-primary font-semibold shrink-0">
                                {{ strtoupper(substr($c['name'], 0, 2)) }}
                            </span>
                            <div class="min-w-0">
                                <div class="font-semibold text-mono truncate group-hover:text-primary">{{ $c['name'] }}</div>
                                <div class="text-sm text-secondary-foreground truncate">{{ $c['contact'] }}</div>
                            </div>
                        </a>
                        <span class="kt-badge kt-badge-sm kt-badge-outline shrink-0">{{ $c['country'] }}</span>
                    </div>

                    <a href="mailto:{{ $c['email'] }}" class="text-sm text-primary hover:underline truncate">{{ $c['email'] }}</a>

                    <div class="flex items-center justify-between pt-3 border-t border-border">
                        <div>
                            <div class="text-xs text-muted-foreground">Billed to date</div>
                            <div class="font-semibold text-mono">{{ $c['billed'] }}</div>
                        </div>
                        <div class="text-end">
                            <div class="text-xs text-muted-foreground">Open invoices</div>
                            <div class="font-semibold {{ $c['open'] > 0 ? 'text-warning' : 'text-success' }}">{{ $c['open'] }}</div>
                        </div>
                    </div>

                </div>
            </div>
        @endforeach
    </div>
</div>
