<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Unified inbox.
 *
 * Reads from the local mail store, never from IMAP directly — the sync job
 * fills that store on a schedule. That separation is the whole reason this page
 * stays fast on shared hosting.
 */
new
#[Title('Inbox — Kargah')]
class extends Component
{
    #[Url]
    public string $folder = 'inbox';

    public ?int $selected = 1;

    public string $search = '';

    public function with(): array
    {
        return [
            'folders' => [
                ['key' => 'inbox',    'label' => 'Inbox',    'icon' => 'ki-sms',            'count' => 3],
                ['key' => 'starred',  'label' => 'Starred',  'icon' => 'ki-star',           'count' => 0],
                ['key' => 'sent',     'label' => 'Sent',     'icon' => 'ki-send',           'count' => 0],
                ['key' => 'drafts',   'label' => 'Drafts',   'icon' => 'ki-notepad-edit',   'count' => 1],
                ['key' => 'replies',  'label' => 'Campaign replies', 'icon' => 'ki-message-programming', 'count' => 0],
                ['key' => 'bounces',  'label' => 'Bounces',  'icon' => 'ki-shield-cross',   'count' => 0],
                ['key' => 'archive',  'label' => 'Archive',  'icon' => 'ki-archive',        'count' => 0],
            ],
            'messages' => [
                ['id' => 1, 'from' => 'Sam Okafor', 'email' => 'sam@northwind.example', 'subject' => 'Re: Invoice INV-0041', 'preview' => 'Thanks — approved on our side, payment goes out Friday.', 'time' => '09:24', 'unread' => true,  'starred' => false, 'tag' => null],
                ['id' => 2, 'from' => 'Rita Vance', 'email' => 'rita@acme.example',     'subject' => 'Scope change for the landing page', 'preview' => 'Can we add two more sections before launch?', 'time' => 'Yesterday', 'unread' => true, 'starred' => true, 'tag' => 'client'],
                ['id' => 3, 'from' => 'GitHub',     'email' => 'noreply@github.com',    'subject' => 'Security alert for kargah', 'preview' => 'A dependency in your repository has a known vulnerability.', 'time' => 'Jul 30', 'unread' => true, 'starred' => false, 'tag' => null],
                ['id' => 4, 'from' => 'Jonas Reyes','email' => 'jonas@bluepeak.example','subject' => 'Contract signed', 'preview' => 'Attached the countersigned PDF. Good working with you.', 'time' => 'Jul 28', 'unread' => false, 'starred' => false, 'tag' => 'client'],
            ],
        ];
    }

    public function select(int $id): void
    {
        $this->selected = $id;
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Mail</h1>
            <p class="text-sm text-secondary-foreground mt-1">Every account in one place.</p>
        </div>
        <div class="flex items-center gap-2">
            <button class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-arrows-circle"></i> Sync now
            </button>
            <button class="kt-btn kt-btn-primary gap-2">
                <i class="ki-filled ki-pencil"></i> Compose
            </button>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        {{-- Folders --}}
        <div class="col-span-12 lg:col-span-2">
            <div class="kt-card">
                <div class="kt-card-content p-2 flex flex-col gap-0.5">
                    @foreach ($folders as $f)
                        <button wire:click="$set('folder', '{{ $f['key'] }}')"
                                class="kt-btn kt-btn-ghost justify-between gap-2 w-full {{ $folder === $f['key'] ? 'bg-accent/60 text-primary' : '' }}">
                            <span class="flex items-center gap-2 min-w-0">
                                <i class="ki-filled {{ $f['icon'] }} text-base shrink-0"></i>
                                <span class="truncate text-sm">{{ $f['label'] }}</span>
                            </span>
                            @if ($f['count'] > 0)
                                <span class="kt-badge kt-badge-sm kt-badge-primary shrink-0">{{ $f['count'] }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Message list --}}
        <div class="col-span-12 lg:col-span-4">
            <div class="kt-card">
                <div class="kt-card-header">
                    <div class="kt-input w-full">
                        <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                        <input type="text" placeholder="Search mail…" wire:model.live.debounce.300ms="search">
                    </div>
                </div>
                <div class="kt-card-content p-0 divide-y divide-border max-h-[620px] overflow-y-auto kt-scrollable-y">
                    @foreach ($messages as $m)
                        <button wire:click="select({{ $m['id'] }})"
                                class="w-full text-start px-4 py-3.5 hover:bg-accent/40 transition-colors {{ $selected === $m['id'] ? 'bg-accent/60' : '' }}">
                            <div class="flex items-center justify-between gap-2 mb-1">
                                <span class="text-sm truncate {{ $m['unread'] ? 'font-semibold text-mono' : 'text-secondary-foreground' }}">
                                    {{ $m['from'] }}
                                </span>
                                <span class="text-xs text-muted-foreground shrink-0">{{ $m['time'] }}</span>
                            </div>
                            <div class="text-sm truncate {{ $m['unread'] ? 'text-mono' : 'text-secondary-foreground' }}">
                                {{ $m['subject'] }}
                            </div>
                            <div class="text-xs text-muted-foreground truncate mt-0.5">{{ $m['preview'] }}</div>
                            <div class="flex items-center gap-2 mt-1.5">
                                @if ($m['unread'])<span class="size-1.5 rounded-full bg-primary"></span>@endif
                                @if ($m['starred'])<i class="ki-filled ki-star text-warning text-xs"></i>@endif
                                @if ($m['tag'])<span class="kt-badge kt-badge-sm kt-badge-outline">{{ $m['tag'] }}</span>@endif
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Reading pane --}}
        <div class="col-span-12 lg:col-span-6">
            <div class="kt-card min-h-[620px]">
                @php $msg = collect($messages)->firstWhere('id', $selected); @endphp

                @if ($msg)
                    <div class="kt-card-header flex-col items-start gap-2 py-4">
                        <div class="flex items-start justify-between w-full gap-3">
                            <h3 class="text-base font-semibold text-mono">{{ $msg['subject'] }}</h3>
                            <div class="flex items-center gap-1 shrink-0">
                                <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Star"><i class="ki-filled ki-star text-base"></i></button>
                                <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Convert to task"><i class="ki-filled ki-check-squared text-base"></i></button>
                                <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Archive"><i class="ki-filled ki-archive text-base"></i></button>
                                <button class="kt-btn kt-btn-icon kt-btn-ghost size-8 text-destructive" title="Delete"><i class="ki-filled ki-trash text-base"></i></button>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="inline-flex items-center justify-center size-9 rounded-full bg-primary/10 text-primary text-sm font-semibold">
                                {{ strtoupper(substr($msg['from'], 0, 1)) }}
                            </span>
                            <div class="min-w-0">
                                <div class="text-sm font-medium text-mono">{{ $msg['from'] }}</div>
                                <div class="text-xs text-muted-foreground truncate">{{ $msg['email'] }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="kt-card-content p-6 text-sm leading-relaxed text-secondary-foreground">
                        <p>{{ $msg['preview'] }}</p>
                        <p class="mt-4 text-muted-foreground italic">Full message body renders here once the IMAP sync job stores parsed MIME.</p>
                    </div>

                    <div class="kt-card-footer flex-col items-stretch gap-3 p-4">
                        <textarea class="kt-textarea min-h-[90px]" placeholder="Write a reply…"></textarea>
                        <div class="flex items-center justify-between">
                            <div class="flex gap-1">
                                <button class="kt-btn kt-btn-icon kt-btn-ghost size-8"><i class="ki-filled ki-paper-clip text-base"></i></button>
                                <button class="kt-btn kt-btn-icon kt-btn-ghost size-8"><i class="ki-filled ki-picture text-base"></i></button>
                            </div>
                            <button class="kt-btn kt-btn-primary gap-2">
                                <i class="ki-filled ki-send"></i> Send reply
                            </button>
                        </div>
                    </div>
                @else
                    <div class="kt-card-content flex flex-col items-center justify-center min-h-[620px] text-center">
                        <i class="ki-filled ki-sms text-4xl text-muted-foreground mb-3"></i>
                        <p class="text-sm text-secondary-foreground">Select a message to read it.</p>
                    </div>
                @endif
            </div>
        </div>

    </div>
</div>
