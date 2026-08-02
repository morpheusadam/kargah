<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Unified inbox.
 *
 * Reads from the local mail store, never from IMAP directly — the sync job fills
 * that store on a schedule. That separation is the whole reason this page stays
 * fast on shared hosting.
 *
 * A row in the list is a *conversation*, not a message. The reading pane shows
 * the whole thread with earlier messages collapsed, and the reply composer stays
 * shut until you actually choose to reply, forward, or reply to everyone.
 */
new
#[Title('Inbox — Kargah')]
class extends Component
{
    #[Url]
    public string $folder = 'inbox';

    #[Url(as: 'q')]
    public string $search = '';

    public ?int $selected = 1;

    public bool $unreadOnly = false;

    /** @var int[] Conversation ids ticked for a bulk action. */
    public array $checked = [];

    /** null | 'reply' | 'replyAll' | 'forward' */
    public ?string $replyMode = null;

    public string $replyTo = '';

    public string $replyBody = '';

    /** Message ids inside the open thread that are expanded. */
    public array $expanded = [];

    public bool $showQuoted = false;

    // ---------------------------------------------------------------- fixtures

    private function folders(): array
    {
        return [
            ['key' => 'inbox',    'label' => 'Inbox',            'icon' => 'ki-sms',                 'count' => 3],
            ['key' => 'starred',  'label' => 'Starred',          'icon' => 'ki-star',                'count' => 0],
            ['key' => 'sent',     'label' => 'Sent',             'icon' => 'ki-paper-plane',         'count' => 0],
            ['key' => 'drafts',   'label' => 'Drafts',           'icon' => 'ki-notepad-edit',        'count' => 1],
            ['key' => 'replies',  'label' => 'Campaign replies', 'icon' => 'ki-message-programming', 'count' => 0],
            ['key' => 'bounces',  'label' => 'Bounces',          'icon' => 'ki-shield-cross',        'count' => 0],
            ['key' => 'archive',  'label' => 'Archive',          'icon' => 'ki-archive',             'count' => 0],
            ['key' => 'trash',    'label' => 'Trash',            'icon' => 'ki-trash',               'count' => 0],
        ];
    }

    private function threads(): array
    {
        return [
            [
                'id' => 1,
                'subject' => 'Re: Invoice INV-0041',
                'from' => 'Sam Okafor',
                'email' => 'sam@northwind.example',
                'company' => 'Northwind Ltd',
                'snippet' => 'Thanks — approved on our side, payment goes out Friday.',
                'time' => '09:24',
                'fullTime' => 'Today at 09:24',
                'unread' => true,
                'starred' => false,
                'labels' => ['client', 'invoice'],
                'attachments' => [],
                'messages' => [
                    [
                        'id' => 101, 'from' => 'You', 'email' => 'me@kargah.dev', 'to' => 'sam@northwind.example',
                        'time' => 'Mon 14:02', 'avatar' => 'K',
                        'body' => "Hi Sam,\n\nInvoice INV-0041 is attached, covering the July retainer and the two extra landing pages we agreed on the 12th. Payment terms are 30 days, so it falls due on 19 August.\n\nShout if anything looks off.\n\nNima",
                    ],
                    [
                        'id' => 102, 'from' => 'Sam Okafor', 'email' => 'sam@northwind.example', 'to' => 'me@kargah.dev',
                        'time' => 'Today at 09:24', 'avatar' => 'S',
                        'body' => "Thanks — approved on our side, payment goes out Friday.\n\nOne thing: finance wants the PO number (NW-2026-338) on the invoice line rather than in the notes. Could you reissue with that change? No rush, Friday's run is going ahead either way.\n\nAlso, we're likely to need another two pages in September. I'll know for certain after the 20th.\n\nSam",
                    ],
                ],
            ],
            [
                'id' => 2,
                'subject' => 'Scope change for the landing page',
                'from' => 'Rita Vance',
                'email' => 'rita@acme.example',
                'company' => 'Acme Studio',
                'snippet' => 'Can we add two more sections before launch?',
                'time' => 'Yesterday',
                'fullTime' => 'Yesterday at 16:41',
                'unread' => true,
                'starred' => true,
                'labels' => ['client'],
                'attachments' => [
                    ['name' => 'wireframes-v3.pdf', 'size' => '1.2 MB', 'icon' => 'ki-document', 'tone' => 'text-destructive'],
                ],
                'messages' => [
                    [
                        'id' => 201, 'from' => 'Rita Vance', 'email' => 'rita@acme.example', 'to' => 'me@kargah.dev',
                        'time' => 'Yesterday at 16:41', 'avatar' => 'R',
                        'body' => "Hi,\n\nCan we add two more sections before launch? Marketing wants a comparison table and a short FAQ under the pricing block. Wireframes attached.\n\nI know this lands outside the original scope — happy for it to be quoted separately. What would that do to the 15 August date?\n\nRita",
                    ],
                ],
            ],
            [
                'id' => 3,
                'subject' => 'Security alert for kargah',
                'from' => 'GitHub',
                'email' => 'noreply@github.com',
                'company' => null,
                'snippet' => 'A dependency in your repository has a known vulnerability.',
                'time' => 'Jul 30',
                'fullTime' => '30 July at 03:12',
                'unread' => true,
                'starred' => false,
                'labels' => [],
                'attachments' => [],
                'messages' => [
                    [
                        'id' => 301, 'from' => 'GitHub', 'email' => 'noreply@github.com', 'to' => 'me@kargah.dev',
                        'time' => '30 July at 03:12', 'avatar' => 'G',
                        'body' => "Known vulnerability found in morpheusadam/kargah.\n\nDependabot found a moderate severity advisory affecting a transitive dependency of your Composer lock file. No fix is required if you are on the latest patch release.\n\nReview the alert on GitHub.",
                    ],
                ],
            ],
            [
                'id' => 4,
                'subject' => 'Contract signed',
                'from' => 'Jonas Reyes',
                'email' => 'jonas@bluepeak.example',
                'company' => 'Bluepeak',
                'snippet' => 'Attached the countersigned PDF. Good working with you.',
                'time' => 'Jul 28',
                'fullTime' => '28 July at 11:05',
                'unread' => false,
                'starred' => false,
                'labels' => ['client', 'contract'],
                'attachments' => [
                    ['name' => 'bluepeak-agreement-signed.pdf', 'size' => '288 KB', 'icon' => 'ki-document', 'tone' => 'text-destructive'],
                ],
                'messages' => [
                    [
                        'id' => 401, 'from' => 'Jonas Reyes', 'email' => 'jonas@bluepeak.example', 'to' => 'me@kargah.dev',
                        'time' => '28 July at 11:05', 'avatar' => 'J',
                        'body' => "Attached the countersigned PDF. Good working with you.\n\nWe start in earnest on the 4th. I'll send over access to the staging environment on Monday morning.\n\nJonas",
                    ],
                ],
            ],
        ];
    }

    public function with(): array
    {
        $threads = collect($this->threads())
            ->when($this->unreadOnly, fn ($c) => $c->where('unread', true))
            ->when($this->search !== '', function ($c) {
                $q = mb_strtolower($this->search);

                return $c->filter(fn ($t) => str_contains(mb_strtolower($t['subject'].' '.$t['from'].' '.$t['snippet']), $q));
            })
            ->values();

        $open = $threads->firstWhere('id', $this->selected)
            ?? collect($this->threads())->firstWhere('id', $this->selected);

        return [
            'folders' => $this->folders(),
            'threads' => $threads,
            'open' => $open,
            'unreadTotal' => collect($this->threads())->where('unread', true)->count(),
        ];
    }

    // ----------------------------------------------------------------- actions

    public function select(int $id): void
    {
        $this->selected = $id;
        $this->cancelReply();
        $this->expanded = [];
        $this->showQuoted = false;
    }

    public function toggleExpanded(int $messageId): void
    {
        $this->expanded = in_array($messageId, $this->expanded, true)
            ? array_values(array_diff($this->expanded, [$messageId]))
            : [...$this->expanded, $messageId];
    }

    public function toggleChecked(int $id): void
    {
        $this->checked = in_array($id, $this->checked, true)
            ? array_values(array_diff($this->checked, [$id]))
            : [...$this->checked, $id];
    }

    public function checkAll(): void
    {
        $all = collect($this->threads())->pluck('id')->all();
        $this->checked = count($this->checked) === count($all) ? [] : $all;
    }

    public function clearChecked(): void
    {
        $this->checked = [];
    }

    /** Open the composer. Prefills the recipient from the message being answered. */
    public function startReply(string $mode): void
    {
        $thread = collect($this->threads())->firstWhere('id', $this->selected);

        if (! $thread) {
            return;
        }

        $this->replyMode = $mode;
        $this->replyTo = $mode === 'forward' ? '' : $thread['email'];
        $this->replyBody = '';
    }

    public function cancelReply(): void
    {
        $this->replyMode = null;
        $this->replyTo = '';
        $this->replyBody = '';
    }

    // Everything below is queued by the backend phase; the signatures are final.
    public function send(): void {}

    public function toggleStar(int $id): void {}

    public function archive(): void {}

    public function delete(): void {}

    public function markUnread(): void {}

    public function convertToTask(): void {}

    public function sync(): void {}
};

?>

<div class="flex flex-col gap-5">

    {{-- Page header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Mail</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                {{ $unreadTotal }} unread across every connected account.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button wire:click="sync" class="kt-btn kt-btn-outline gap-2" wire:loading.attr="disabled" wire:target="sync">
                <i class="ki-filled ki-arrows-circle" wire:loading.class="animate-spin" wire:target="sync"></i>
                <span wire:loading.remove wire:target="sync">Sync</span>
                <span wire:loading wire:target="sync">Syncing…</span>
            </button>
            <button class="kt-btn kt-btn-primary gap-2" wire:click="$dispatch('open-compose')">
                <i class="ki-filled ki-pencil"></i> Compose
            </button>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        {{-- ────────────────────────────── Folders ────────────────────────────── --}}
        <aside class="col-span-12 lg:col-span-2">
            <div class="kt-card">
                <div class="kt-card-content p-2 flex flex-col gap-0.5">
                    @foreach ($folders as $f)
                        @php $on = $folder === $f['key']; @endphp
                        <button wire:click="$set('folder', '{{ $f['key'] }}')"
                                class="flex items-center justify-between gap-2 w-full px-3 py-2 rounded-lg text-start transition-colors {{ $on ? 'bg-accent/60' : 'hover:bg-accent/40' }}">
                            <span class="flex items-center gap-2.5 min-w-0">
                                <i class="ki-filled {{ $f['icon'] }} text-base shrink-0 {{ $on ? 'text-primary' : 'text-muted-foreground' }}"></i>
                                <span class="truncate text-sm {{ $on ? 'text-primary font-semibold' : 'text-foreground' }}">{{ $f['label'] }}</span>
                            </span>
                            @if ($f['count'] > 0)
                                <span class="text-xs font-semibold shrink-0 {{ $on ? 'text-primary' : 'text-muted-foreground' }}">{{ $f['count'] }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="kt-card mt-5">
                <div class="kt-card-content p-4">
                    <div class="text-xs font-semibold uppercase tracking-wide text-muted-foreground mb-3">Storage</div>
                    <div class="h-1.5 w-full rounded-full bg-muted overflow-hidden">
                        <div class="h-full bg-primary rounded-full" style="width: 12%"></div>
                    </div>
                    <div class="text-xs text-muted-foreground mt-2">1.4 GB of 12 GB used</div>
                </div>
            </div>
        </aside>

        {{-- ──────────────────────────── Message list ─────────────────────────── --}}
        <section class="col-span-12 lg:col-span-4">
            <div class="kt-card">

                <div class="kt-card-header flex-col items-stretch gap-3 py-3">
                    <div class="kt-input w-full">
                        <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                        <input type="text" placeholder="Search mail…" wire:model.live.debounce.300ms="search">
                        @if ($search !== '')
                            <button wire:click="$set('search', '')" class="kt-btn kt-btn-icon kt-btn-ghost size-6" aria-label="Clear search">
                                <i class="ki-filled ki-cross text-xs"></i>
                            </button>
                        @endif
                    </div>

                    {{-- Toolbar. Bulk actions replace the filters once anything is ticked. --}}
                    <div class="flex items-center justify-between gap-2">
                        @if (count($checked))
                            <div class="flex items-center gap-1">
                                <button wire:click="clearChecked" class="kt-btn kt-btn-icon kt-btn-ghost size-7" aria-label="Clear selection">
                                    <i class="ki-filled ki-cross text-sm"></i>
                                </button>
                                <span class="text-xs font-medium text-mono">{{ count($checked) }} selected</span>
                            </div>
                            <div class="flex items-center gap-1">
                                <button wire:click="archive" class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Archive"><i class="ki-filled ki-archive text-sm"></i></button>
                                <button wire:click="markUnread" class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Mark unread"><i class="ki-filled ki-sms text-sm"></i></button>
                                <button wire:click="delete" class="kt-btn kt-btn-icon kt-btn-ghost size-7 text-destructive" title="Delete"><i class="ki-filled ki-trash text-sm"></i></button>
                            </div>
                        @else
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" class="kt-checkbox kt-checkbox-sm" wire:click="checkAll">
                                <span class="text-xs text-muted-foreground">Select all</span>
                            </label>
                            <div class="flex items-center gap-1">
                                <button wire:click="$toggle('unreadOnly')"
                                        class="kt-btn kt-btn-sm {{ $unreadOnly ? 'kt-btn-primary' : 'kt-btn-ghost' }}">
                                    Unread
                                </button>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="kt-card-content p-0 divide-y divide-border max-h-[640px] overflow-y-auto kt-scrollable-y">
                    @forelse ($threads as $t)
                        @php
                            $isOpen = $selected === $t['id'];
                            $isChecked = in_array($t['id'], $checked, true);
                        @endphp
                        <div class="relative flex items-start gap-2.5 px-3 py-3 transition-colors {{ $isOpen ? 'bg-accent/60' : 'hover:bg-accent/30' }}">

                            @if ($isOpen)
                                <span class="absolute inset-y-0 start-0 w-[3px] bg-primary"></span>
                            @endif

                            <div class="flex flex-col items-center gap-2 pt-0.5 shrink-0">
                                <input type="checkbox"
                                       class="kt-checkbox kt-checkbox-sm"
                                       wire:click="toggleChecked({{ $t['id'] }})"
                                       @checked($isChecked)
                                       aria-label="Select conversation">
                                <button wire:click="toggleStar({{ $t['id'] }})" aria-label="Star conversation">
                                    <i class="ki-filled ki-star text-sm {{ $t['starred'] ? 'text-warning' : 'text-muted-foreground/40 hover:text-muted-foreground' }}"></i>
                                </button>
                            </div>

                            <button wire:click="select({{ $t['id'] }})" class="min-w-0 grow text-start">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="flex items-center gap-2 min-w-0">
                                        @if ($t['unread'])<span class="size-1.5 rounded-full bg-primary shrink-0"></span>@endif
                                        <span class="text-sm truncate {{ $t['unread'] ? 'font-semibold text-mono' : 'text-secondary-foreground' }}">{{ $t['from'] }}</span>
                                        @if (count($t['messages']) > 1)
                                            <span class="text-[11px] text-muted-foreground shrink-0">{{ count($t['messages']) }}</span>
                                        @endif
                                    </span>
                                    <span class="text-[11px] text-muted-foreground shrink-0">{{ $t['time'] }}</span>
                                </div>

                                <div class="text-sm truncate mt-0.5 {{ $t['unread'] ? 'text-mono font-medium' : 'text-secondary-foreground' }}">
                                    {{ $t['subject'] }}
                                </div>

                                <div class="text-xs text-muted-foreground truncate mt-0.5">{{ $t['snippet'] }}</div>

                                @if (count($t['labels']) || count($t['attachments']))
                                    <div class="flex items-center gap-1.5 mt-1.5">
                                        @foreach ($t['labels'] as $label)
                                            <span class="text-[10px] px-1.5 py-0.5 rounded bg-muted text-secondary-foreground">{{ $label }}</span>
                                        @endforeach
                                        @if (count($t['attachments']))
                                            <i class="ki-filled ki-paper-clip text-xs text-muted-foreground"></i>
                                        @endif
                                    </div>
                                @endif
                            </button>
                        </div>
                    @empty
                        <div class="flex flex-col items-center py-16 text-center px-6">
                            <i class="ki-filled ki-sms text-4xl text-muted-foreground mb-3"></i>
                            <p class="text-sm font-medium text-mono">
                                {{ $search !== '' ? 'No conversations match that search.' : 'This folder is empty.' }}
                            </p>
                            @if ($search !== '')
                                <button wire:click="$set('search', '')" class="kt-btn kt-btn-sm kt-btn-ghost mt-3">Clear search</button>
                            @endif
                        </div>
                    @endforelse
                </div>
            </div>
        </section>

        {{-- ──────────────────────────── Reading pane ─────────────────────────── --}}
        <section class="col-span-12 lg:col-span-6">
            <div class="kt-card min-h-[700px] flex flex-col">

                @if ($open)
                    {{-- Thread header --}}
                    <div class="border-b border-border px-6 py-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <h2 class="text-lg font-semibold text-mono leading-snug">{{ $open['subject'] }}</h2>
                                <div class="flex flex-wrap items-center gap-1.5 mt-2">
                                    @foreach ($open['labels'] as $label)
                                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-muted text-secondary-foreground">{{ $label }}</span>
                                    @endforeach
                                    @if ($open['company'])
                                        <span class="text-xs text-muted-foreground">{{ $open['company'] }}</span>
                                    @endif
                                    <span class="text-xs text-muted-foreground">·</span>
                                    <span class="text-xs text-muted-foreground">{{ count($open['messages']) }} {{ \Illuminate\Support\Str::plural('message', count($open['messages'])) }}</span>
                                </div>
                            </div>

                            <div class="flex items-center gap-0.5 shrink-0">
                                <button wire:click="toggleStar({{ $open['id'] }})" class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Star">
                                    <i class="ki-filled ki-star text-base {{ $open['starred'] ? 'text-warning' : '' }}"></i>
                                </button>
                                <button wire:click="convertToTask" class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Turn into a task">
                                    <i class="ki-filled ki-check-squared text-base"></i>
                                </button>
                                <button wire:click="archive" class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Archive">
                                    <i class="ki-filled ki-archive text-base"></i>
                                </button>
                                <button wire:click="markUnread" class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Mark unread">
                                    <i class="ki-filled ki-sms text-base"></i>
                                </button>
                                <button wire:click="delete" class="kt-btn kt-btn-icon kt-btn-ghost size-8 text-destructive" title="Delete">
                                    <i class="ki-filled ki-trash text-base"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- Thread body --}}
                    <div class="grow overflow-y-auto kt-scrollable-y">
                        @foreach ($open['messages'] as $i => $m)
                            @php
                                $last = $i === count($open['messages']) - 1;
                                $isExpanded = $last || in_array($m['id'], $expanded, true);
                                $mine = $m['email'] === 'me@kargah.dev';
                            @endphp

                            <article class="border-b border-border last:border-b-0">
                                <button wire:click="toggleExpanded({{ $m['id'] }})"
                                        class="w-full flex items-start gap-3 px-6 py-4 text-start hover:bg-accent/20 transition-colors"
                                        @if ($last) disabled @endif>
                                    <span class="inline-flex items-center justify-center size-9 rounded-full text-sm font-semibold shrink-0 {{ $mine ? 'bg-muted text-secondary-foreground' : 'bg-primary/10 text-primary' }}">
                                        {{ $m['avatar'] }}
                                    </span>
                                    <span class="min-w-0 grow">
                                        <span class="flex items-center justify-between gap-2">
                                            <span class="text-sm font-semibold text-mono truncate">{{ $m['from'] }}</span>
                                            <span class="text-xs text-muted-foreground shrink-0">{{ $m['time'] }}</span>
                                        </span>
                                        @if ($isExpanded)
                                            <span class="block text-xs text-muted-foreground truncate mt-0.5">
                                                {{ $m['email'] }} <span class="mx-1">→</span> {{ $m['to'] }}
                                            </span>
                                        @else
                                            <span class="block text-xs text-muted-foreground truncate mt-0.5">
                                                {{ \Illuminate\Support\Str::limit(str_replace("\n", ' ', $m['body']), 90) }}
                                            </span>
                                        @endif
                                    </span>
                                </button>

                                @if ($isExpanded)
                                    <div class="px-6 pb-5 ps-[76px]">
                                        <div class="text-sm leading-relaxed text-secondary-foreground whitespace-pre-line">{{ $m['body'] }}</div>

                                        @if ($last && count($open['attachments']))
                                            <div class="flex flex-wrap gap-2 mt-4">
                                                @foreach ($open['attachments'] as $a)
                                                    <a href="#" class="flex items-center gap-2.5 px-3 py-2 rounded-lg border border-border hover:border-primary/40 transition-colors">
                                                        <i class="ki-filled {{ $a['icon'] }} text-lg {{ $a['tone'] }}"></i>
                                                        <span class="min-w-0">
                                                            <span class="block text-xs font-medium text-mono truncate max-w-[200px]">{{ $a['name'] }}</span>
                                                            <span class="block text-[11px] text-muted-foreground">{{ $a['size'] }}</span>
                                                        </span>
                                                        <i class="ki-filled ki-exit-down text-sm text-muted-foreground ms-1"></i>
                                                    </a>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </article>
                        @endforeach
                    </div>

                    {{-- Action bar, or the composer once you choose to write --}}
                    <div class="border-t border-border p-4 shrink-0">
                        @if ($replyMode === null)
                            <div class="flex flex-wrap items-center gap-2">
                                <button wire:click="startReply('reply')" class="kt-btn kt-btn-outline gap-2">
                                    <i class="ki-filled ki-arrow-left"></i> Reply
                                </button>
                                <button wire:click="startReply('replyAll')" class="kt-btn kt-btn-outline gap-2">
                                    <i class="ki-filled ki-arrows-loop"></i> Reply all
                                </button>
                                <button wire:click="startReply('forward')" class="kt-btn kt-btn-outline gap-2">
                                    <i class="ki-filled ki-arrow-right"></i> Forward
                                </button>
                            </div>
                        @else
                            <div class="flex flex-col gap-3">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="text-sm font-semibold text-mono">
                                        @if ($replyMode === 'forward') Forward
                                        @elseif ($replyMode === 'replyAll') Reply to everyone
                                        @else Reply
                                        @endif
                                    </span>
                                    <button wire:click="cancelReply" class="kt-btn kt-btn-icon kt-btn-ghost size-7" aria-label="Close composer">
                                        <i class="ki-filled ki-cross text-sm"></i>
                                    </button>
                                </div>

                                <div class="flex items-center gap-2 text-sm">
                                    <span class="text-muted-foreground w-8 shrink-0">To</span>
                                    <input type="text" class="kt-input" placeholder="name@example.com" wire:model="replyTo">
                                </div>

                                <div class="flex items-center gap-2 text-sm">
                                    <span class="text-muted-foreground w-8 shrink-0">Re</span>
                                    <span class="text-mono truncate">{{ $open['subject'] }}</span>
                                </div>

                                <textarea class="kt-textarea min-h-[140px] text-sm"
                                          placeholder="Write your message…"
                                          wire:model="replyBody"
                                          autofocus></textarea>

                                <div class="flex items-center justify-between">
                                    <div class="flex gap-1">
                                        <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Attach a file"><i class="ki-filled ki-paper-clip text-base"></i></button>
                                        <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Insert an image"><i class="ki-filled ki-picture text-base"></i></button>
                                        <button class="kt-btn kt-btn-icon kt-btn-ghost size-8" title="Save as draft"><i class="ki-filled ki-notepad-edit text-base"></i></button>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button wire:click="cancelReply" class="kt-btn kt-btn-ghost">Discard</button>
                                        <button wire:click="send" class="kt-btn kt-btn-primary gap-2" wire:loading.attr="disabled" wire:target="send">
                                            <i class="ki-filled ki-paper-plane"></i>
                                            <span wire:loading.remove wire:target="send">Send</span>
                                            <span wire:loading wire:target="send">Sending…</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center grow text-center px-6 py-20">
                        <i class="ki-filled ki-sms text-5xl text-muted-foreground mb-4"></i>
                        <p class="text-base font-medium text-mono">No conversation open</p>
                        <p class="text-sm text-secondary-foreground mt-1 max-w-[280px]">
                            Pick something from the list, or start a new message.
                        </p>
                        <button class="kt-btn kt-btn-primary gap-2 mt-5" wire:click="$dispatch('open-compose')">
                            <i class="ki-filled ki-pencil"></i> Compose
                        </button>
                    </div>
                @endif
            </div>
        </section>

    </div>

    {{-- Full composer. Opened by the Compose buttons via the `open-compose` event. --}}
    <livewire:mailbox::compose />
</div>
