<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * Unified social notifications.
 *
 * One feed for every connected network so you stop opening six apps to find
 * out whether anything needs a reply.
 */
new
#[Title('Notifications — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url]
    public string $network = 'all';

    public function with(): array
    {
        return [
            'networks' => [
                'all'       => ['label' => 'All',       'icon' => 'ki-element-11', 'tone' => 'text-primary'],
                'telegram'  => ['label' => 'Telegram',  'icon' => 'ki-paper-plane',       'tone' => 'text-info'],
                'linkedin'  => ['label' => 'LinkedIn',  'icon' => 'ki-abstract-41','tone' => 'text-info'],
                'x'         => ['label' => 'X',         'icon' => 'ki-abstract-39','tone' => 'text-foreground'],
                'instagram' => ['label' => 'Instagram', 'icon' => 'ki-instagram',  'tone' => 'text-destructive'],
            ],
            'items' => [
                ['network' => 'linkedin',  'actor' => 'Rita Vance',  'action' => 'commented on your post', 'text' => 'This is exactly the workflow I was missing.', 'time' => '2h', 'unread' => true],
                ['network' => 'telegram',  'actor' => '@kargah_bot', 'action' => 'received a message',     'text' => 'New lead submitted the contact form.',        'time' => '5h', 'unread' => true],
                ['network' => 'x',         'actor' => 'devsam',      'action' => 'reposted you',           'text' => 'Building a freelance OS in Laravel.',         'time' => '1d', 'unread' => false],
            ],
        ];
    }

    public function markAllRead(): void
    {
        // Clears the unread flag on every notification. Backend work.

        $this->toastInfo('Marking as read is not wired up yet', 'The unread notifications are unchanged.');
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Notifications</h1>
            <p class="text-sm text-secondary-foreground mt-1">Every network in one feed.</p>
        </div>
        <button wire:click="markAllRead" wire:loading.attr="disabled" class="kt-btn kt-btn-outline gap-2">
            <i class="ki-filled ki-check-circle"></i> Mark all read
        </button>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        @foreach ($networks as $key => $n)
            <button wire:click="$set('network', '{{ $key }}')"
                    class="kt-btn kt-btn-sm gap-2 {{ $network === $key ? 'kt-btn-primary' : 'kt-btn-outline' }}">
                <i class="ki-filled {{ $n['icon'] }} text-sm"></i> {{ $n['label'] }}
            </button>
        @endforeach
    </div>

    <div class="kt-card">
        <div class="kt-card-content p-0 divide-y divide-border">
            @forelse ($items as $item)
                <div class="flex items-start gap-3 px-5 py-4 hover:bg-accent/30 transition-colors {{ $item['unread'] ? 'bg-primary/[0.03]' : '' }}">
                    <span class="inline-flex items-center justify-center size-10 rounded-lg bg-muted shrink-0">
                        <i class="ki-filled {{ $networks[$item['network']]['icon'] }} {{ $networks[$item['network']]['tone'] }} text-lg"></i>
                    </span>
                    <div class="min-w-0 grow">
                        <div class="text-sm">
                            <span class="font-semibold text-mono">{{ $item['actor'] }}</span>
                            <span class="text-secondary-foreground">{{ $item['action'] }}</span>
                        </div>
                        <p class="text-sm text-secondary-foreground mt-1 line-clamp-2">{{ $item['text'] }}</p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <span class="text-xs text-muted-foreground">{{ $item['time'] }}</span>
                        @if ($item['unread'])<span class="size-2 rounded-full bg-primary"></span>@endif
                    </div>
                </div>
            @empty
                <div class="flex flex-col items-center py-14 text-center">
                    <i class="ki-filled ki-notification-status text-4xl text-muted-foreground mb-3"></i>
                    <p class="text-sm text-secondary-foreground">Nothing new.</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
