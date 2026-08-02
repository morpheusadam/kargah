<?php

use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Global command palette (Ctrl/Cmd + K).
 *
 * Navigation targets are resolved from named routes, so a module that is
 * disabled simply drops out of the list instead of throwing.
 */
new class extends Component
{
    public bool $open = false;

    public string $query = '';

    #[On('palette-open')]
    public function openPalette(): void
    {
        $this->open = true;
        $this->query = '';
    }

    #[On('palette-close')]
    public function close(): void
    {
        $this->open = false;
        $this->query = '';
    }

    private function commands(): array
    {
        return [
            ['group' => 'Go to', 'label' => 'Dashboard',    'icon' => 'ki-element-11',   'route' => 'dashboard',            'keywords' => 'home overview'],
            ['group' => 'Go to', 'label' => 'Boards',       'icon' => 'ki-abstract-26',  'route' => 'projects.boards',      'keywords' => 'trello kanban tasks cards'],
            ['group' => 'Go to', 'label' => 'Inbox',        'icon' => 'ki-sms',          'route' => 'mail.inbox',           'keywords' => 'mail email messages'],
            ['group' => 'Go to', 'label' => 'Campaigns',    'icon' => 'ki-paper-plane',         'route' => 'mail.campaigns',       'keywords' => 'bulk newsletter blast'],
            ['group' => 'Go to', 'label' => 'Contacts',     'icon' => 'ki-people',       'route' => 'mail.contacts',        'keywords' => 'subscribers lists'],
            ['group' => 'Go to', 'label' => 'Providers',    'icon' => 'ki-rocket',       'route' => 'mail.providers',       'keywords' => 'smtp brevo resend ses quota'],
            ['group' => 'Go to', 'label' => 'Invoices',     'icon' => 'ki-dollar',       'route' => 'accounting.invoices',  'keywords' => 'billing money paid'],
            ['group' => 'Go to', 'label' => 'Expenses',     'icon' => 'ki-wallet',       'route' => 'accounting.expenses',  'keywords' => 'costs spending receipts'],
            ['group' => 'Go to', 'label' => 'Clients',      'icon' => 'ki-profile-circle', 'route' => 'accounting.clients',   'keywords' => 'customers companies'],
            ['group' => 'Go to', 'label' => 'Reports',      'icon' => 'ki-chart-line-up','route' => 'accounting.reports',   'keywords' => 'revenue profit charts'],
            ['group' => 'Go to', 'label' => 'Files',        'icon' => 'ki-folder',       'route' => 'data.files',           'keywords' => 'documents uploads storage'],
            ['group' => 'Go to', 'label' => 'Passwords',    'icon' => 'ki-lock',         'route' => 'data.passwords',       'keywords' => 'vault secrets credentials'],
            ['group' => 'Go to', 'label' => 'Links & bots', 'icon' => 'ki-arrow-up-right',         'route' => 'data.links',           'keywords' => 'telegram bookmarks urls'],
            ['group' => 'Go to', 'label' => 'GitHub repos', 'icon' => 'ki-github',       'route' => 'data.repos',           'keywords' => 'git code projects'],
            ['group' => 'Go to', 'label' => 'Backups',      'icon' => 'ki-cloud-download','route' => 'data.backups',        'keywords' => 'restore archive dump'],
            ['group' => 'Go to', 'label' => 'Notifications','icon' => 'ki-notification-status', 'route' => 'social.notifications', 'keywords' => 'social feed alerts'],
            ['group' => 'Go to', 'label' => 'Publish',      'icon' => 'ki-share',        'route' => 'social.publish',       'keywords' => 'post compose social'],

            ['group' => 'Settings', 'label' => 'Profile',       'icon' => 'ki-user',        'route' => 'settings.profile',       'keywords' => 'account name email'],
            ['group' => 'Settings', 'label' => 'Security',      'icon' => 'ki-shield-tick', 'route' => 'settings.security',      'keywords' => 'password 2fa sessions tokens'],
            ['group' => 'Settings', 'label' => 'Appearance',    'icon' => 'ki-color-swatch','route' => 'settings.appearance',    'keywords' => 'theme dark light density'],
            ['group' => 'Settings', 'label' => 'Notifications', 'icon' => 'ki-notification','route' => 'settings.notifications', 'keywords' => 'alerts email digest'],
        ];
    }

    public function with(): array
    {
        $q = trim(mb_strtolower($this->query));

        $matches = collect($this->commands())
            ->filter(fn (array $c) => \Illuminate\Support\Facades\Route::has($c['route']))
            ->filter(function (array $c) use ($q) {
                if ($q === '') {
                    return true;
                }

                return str_contains(mb_strtolower($c['label']), $q)
                    || str_contains(mb_strtolower($c['keywords']), $q);
            })
            ->map(fn (array $c) => [...$c, 'url' => route($c['route'])])
            ->groupBy('group');

        return ['groups' => $matches, 'total' => $matches->flatten(1)->count()];
    }
};

?>

<div>
    <div class="fixed inset-0 z-50 {{ $open ? '' : 'hidden' }}" id="kargah-palette">

        <div class="absolute inset-0 bg-black/40 backdrop-blur-[2px]" wire:click="close"></div>

        <div class="relative mx-auto mt-[10vh] w-[92%] max-w-[560px]">
            <div class="kt-card overflow-hidden shadow-2xl">

                <div class="flex items-center gap-3 px-4 py-3 border-b border-border">
                    <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                    <input type="text"
                           id="kargah-palette-input"
                           class="grow bg-transparent border-0 outline-none text-sm placeholder:text-muted-foreground"
                           placeholder="Search pages and actions…"
                           wire:model.live.debounce.150ms="query"
                           autocomplete="off">
                    <kbd class="text-[10px] px-1.5 py-0.5 rounded border border-border text-muted-foreground">ESC</kbd>
                </div>

                <div class="max-h-[380px] overflow-y-auto kt-scrollable-y p-2">
                    @forelse ($groups as $groupName => $items)
                        <div class="px-2 pt-2 pb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                            {{ $groupName }}
                        </div>
                        @foreach ($items as $item)
                            <a href="{{ $item['url'] }}"
                               class="kargah-palette-item flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-accent/60 focus:bg-accent/60 outline-none">
                                <i class="ki-filled {{ $item['icon'] }} text-base text-muted-foreground shrink-0"></i>
                                <span class="text-sm text-mono">{{ $item['label'] }}</span>
                                <i class="ki-filled ki-black-right text-xs text-muted-foreground ms-auto"></i>
                            </a>
                        @endforeach
                    @empty
                        <div class="flex flex-col items-center py-12 text-center">
                            <i class="ki-filled ki-magnifier text-3xl text-muted-foreground mb-3"></i>
                            <p class="text-sm text-secondary-foreground">Nothing matches “{{ $query }}”.</p>
                        </div>
                    @endforelse
                </div>

                <div class="flex items-center justify-between px-4 py-2.5 border-t border-border text-[11px] text-muted-foreground">
                    <span>{{ $total }} {{ \Illuminate\Support\Str::plural('result', $total) }}</span>
                    <span class="flex items-center gap-3">
                        <span><kbd class="px-1 py-0.5 rounded border border-border">↑↓</kbd> navigate</span>
                        <span><kbd class="px-1 py-0.5 rounded border border-border">↵</kbd> open</span>
                    </span>
                </div>

            </div>
        </div>
    </div>

    @push('scripts')
    <script>
    (function () {
        if (window.__kargahPaletteBound) return;
        window.__kargahPaletteBound = true;

        function items() {
            return Array.from(document.querySelectorAll('#kargah-palette .kargah-palette-item'));
        }

        function isOpen() {
            const el = document.getElementById('kargah-palette');
            return el && !el.classList.contains('hidden');
        }

        document.addEventListener('keydown', function (e) {
            const key = e.key.toLowerCase();

            if ((e.metaKey || e.ctrlKey) && key === 'k') {
                e.preventDefault();
                if (window.Livewire) Livewire.dispatch('palette-open');
                setTimeout(function () {
                    const input = document.getElementById('kargah-palette-input');
                    if (input) input.focus();
                }, 60);
                return;
            }

            if (!isOpen()) return;

            if (key === 'escape') {
                e.preventDefault();
                if (window.Livewire) Livewire.dispatch('palette-close');
                return;
            }

            const list = items();
            if (!list.length) return;

            const current = document.activeElement;
            const index = list.indexOf(current);

            if (key === 'arrowdown') {
                e.preventDefault();
                (list[index + 1] || list[0]).focus();
            } else if (key === 'arrowup') {
                e.preventDefault();
                (list[index - 1] || list[list.length - 1]).focus();
            } else if (key === 'enter' && index > -1) {
                e.preventDefault();
                current.click();
            }
        });
    })();
    </script>
    @endpush
</div>
