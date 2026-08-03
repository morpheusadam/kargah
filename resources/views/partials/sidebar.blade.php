@php
    /**
     * Sidebar navigation.
     *
     * `route` is the link target. `match` (optional) is the set of patterns that
     * keeps an item highlighted on that section's detail pages — without it,
     * opening an invoice would un-highlight Invoices.
     *
     * The accordion is driven by this file's own JavaScript rather than KTUI:
     * the theme's controller does not pick up a menu rendered inside a Livewire
     * layout, which left every group permanently collapsed and made the whole
     * sidebar look dead.
     */
    $nav = [
        [
            'key'   => 'projects',
            'label' => 'Projects',
            'icon'  => 'ki-abstract-26',
            'match' => 'projects.*',
            'items' => [
                ['label' => 'Boards',  'route' => 'projects.boards',  'match' => ['projects.boards', 'projects.board-settings']],
                ['label' => 'Archive', 'route' => 'projects.archive'],
            ],
        ],
        [
            'key'   => 'accounting',
            'label' => 'Accounting',
            'icon'  => 'ki-dollar',
            'match' => 'accounting.*',
            'items' => [
                ['label' => 'Invoices',  'route' => 'accounting.invoices', 'match' => ['accounting.invoices', 'accounting.invoice-*']],
                ['label' => 'Recurring', 'route' => 'accounting.recurring'],
                ['label' => 'Expenses',  'route' => 'accounting.expenses', 'match' => ['accounting.expenses', 'accounting.expense-*']],
                ['label' => 'Clients',   'route' => 'accounting.clients',  'match' => ['accounting.clients', 'accounting.client-*']],
                ['label' => 'Reports',   'route' => 'accounting.reports'],
            ],
        ],
        [
            'key'   => 'mail',
            'label' => 'Mail',
            'icon'  => 'ki-sms',
            'match' => 'mail.*',
            'items' => [
                ['label' => 'Inbox',       'route' => 'mail.inbox'],
                ['label' => 'Campaigns',   'route' => 'mail.campaigns', 'match' => ['mail.campaigns', 'mail.campaign-*']],
                ['label' => 'Contacts',    'route' => 'mail.contacts',  'match' => ['mail.contacts', 'mail.contact-*']],
                ['label' => 'Providers',   'route' => 'mail.providers', 'match' => ['mail.providers', 'mail.provider-*']],
                ['label' => 'Suppression', 'route' => 'mail.suppression'],
            ],
        ],
        [
            'key'   => 'data',
            'label' => 'Data',
            'icon'  => 'ki-folder',
            'match' => 'data.*',
            'items' => [
                ['label' => 'Files',        'route' => 'data.files'],
                ['label' => 'Passwords',    'route' => 'data.passwords', 'match' => ['data.passwords', 'data.credential-*']],
                ['label' => 'Links & Bots', 'route' => 'data.links',     'match' => ['data.links', 'data.link-*']],
                ['label' => 'GitHub Repos', 'route' => 'data.repos',     'match' => ['data.repos', 'data.repo-*']],
                ['label' => 'Backups',      'route' => 'data.backups',   'match' => ['data.backups', 'data.backup-*']],
            ],
        ],
        [
            'key'   => 'social',
            'label' => 'Social',
            'icon'  => 'ki-share',
            'match' => 'social.*',
            'items' => [
                ['label' => 'Notifications', 'route' => 'social.notifications'],
                ['label' => 'Publish',       'route' => 'social.publish'],
                ['label' => 'Calendar',      'route' => 'social.calendar'],
                ['label' => 'Queue',         'route' => 'social.posts',    'match' => ['social.posts', 'social.post-*']],
                ['label' => 'Accounts',      'route' => 'social.accounts', 'match' => ['social.accounts', 'social.account-*']],
            ],
        ],
    ];

    // Drop anything whose module has been disabled rather than blowing up on route().
    $nav = collect($nav)
        ->map(fn (array $group) => [
            ...$group,
            'items' => collect($group['items'])->filter(fn ($i) => Route::has($i['route']))->values()->all(),
        ])
        ->filter(fn (array $group) => count($group['items']) > 0)
        ->values()
        ->all();
@endphp

<div class="kt-sidebar bg-background border-e border-e-border fixed top-0 bottom-0 z-20 hidden lg:flex flex-col items-stretch shrink-0 [--kt-drawer-enable:true] lg:[--kt-drawer-enable:false]"
     data-kt-drawer="true"
     data-kt-drawer-class="kt-drawer kt-drawer-start top-0 bottom-0"
     id="sidebar">

    <div class="kt-sidebar-header hidden lg:flex items-center relative justify-between px-3 lg:px-6 shrink-0" id="sidebar_header">
        <a href="{{ route('dashboard') }}">
            <x-brand-mark :size="8" name-class="kt-sidebar-collapse:hidden text-lg font-semibold text-mono tracking-tight" />
        </a>
        <button class="kt-btn kt-btn-outline kt-btn-icon size-[30px] absolute start-full top-2/4 -translate-x-2/4 -translate-y-2/4"
                data-kt-toggle="body"
                data-kt-toggle-class="kt-sidebar-collapse"
                id="sidebar_toggle"
                aria-label="Collapse sidebar">
            <i class="ki-filled ki-black-left-line kt-toggle-active:rotate-180 transition-all duration-300"></i>
        </button>
    </div>

    <div class="kt-sidebar-content flex grow shrink-0 py-5 pe-2" id="sidebar_content">
        <div class="kt-scrollable-y-hover grow shrink-0 flex ps-2 lg:ps-5 pe-1 lg:pe-3"
             data-kt-scrollable="true"
             data-kt-scrollable-dependencies="#sidebar_header"
             data-kt-scrollable-height="auto"
             data-kt-scrollable-offset="0px"
             data-kt-scrollable-wrappers="#sidebar_content"
             id="sidebar_scrollable">

            <nav class="kt-menu kt-menu-default flex flex-col grow gap-1" id="sidebar_menu" aria-label="Main">

                {{-- Dashboard: a plain link, no accordion --}}
                @php $dashActive = request()->routeIs('dashboard'); @endphp
                <div class="kt-menu-item {{ $dashActive ? 'active' : '' }}">
                    <a class="kt-menu-link flex items-center grow border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] rounded-lg hover:bg-accent/60 {{ $dashActive ? 'bg-accent/60' : '' }}"
                       href="{{ route('dashboard') }}"
                       @if ($dashActive) aria-current="page" @endif>
                        <span class="kt-menu-icon items-start w-[20px] {{ $dashActive ? 'text-primary' : 'text-muted-foreground' }}">
                            <i class="ki-filled ki-element-11 text-lg"></i>
                        </span>
                        <span class="kt-menu-title text-sm {{ $dashActive ? 'text-primary font-semibold' : 'text-foreground font-medium' }}">
                            Dashboard
                        </span>
                    </a>
                </div>

                {{-- The in-app feed. A plain link like Dashboard, and above the
                     module groups on purpose: it is not one module's page. Note
                     Social's own "Notifications" item lower down is a different
                     thing — what the networks said, not what Kargah has to
                     say — and the two routes are `social.notifications` and
                     `core.notifications`. --}}
                @if (Route::has('core.notifications'))
                    @php $notifActive = request()->routeIs('core.notifications'); @endphp
                    <div class="kt-menu-item {{ $notifActive ? 'active' : '' }}">
                        <a class="kt-menu-link flex items-center grow border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] rounded-lg hover:bg-accent/60 {{ $notifActive ? 'bg-accent/60' : '' }}"
                           href="{{ route('core.notifications') }}"
                           @if ($notifActive) aria-current="page" @endif>
                            <span class="kt-menu-icon items-start w-[20px] {{ $notifActive ? 'text-primary' : 'text-muted-foreground' }}">
                                <i class="ki-filled ki-notification-status text-lg"></i>
                            </span>
                            <span class="kt-menu-title text-sm {{ $notifActive ? 'text-primary font-semibold' : 'text-foreground font-medium' }}">
                                Notifications
                            </span>
                        </a>
                    </div>
                @endif

                <div class="border-b border-border my-2"></div>

                @foreach ($nav as $group)
                    @php
                        $groupActive = request()->routeIs($group['match']);
                        // A group starts open when one of its pages is on screen; the rest
                        // of the time the client script restores whatever the user chose.
                        $groupOpen = $groupActive;
                    @endphp

                    <div class="kt-menu-item {{ $groupOpen ? 'show' : '' }}" data-kargah-group="{{ $group['key'] }}">

                        <button type="button"
                                class="kt-menu-link flex items-center grow w-full cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] rounded-lg hover:bg-accent/40 text-start"
                                data-kargah-accordion
                                aria-expanded="{{ $groupOpen ? 'true' : 'false' }}"
                                aria-controls="submenu-{{ $group['key'] }}">
                            <span class="kt-menu-icon items-start w-[20px] {{ $groupActive ? 'text-primary' : 'text-muted-foreground' }}">
                                <i class="ki-filled {{ $group['icon'] }} text-lg"></i>
                            </span>
                            <span class="kt-menu-title text-sm font-medium {{ $groupActive ? 'text-primary' : 'text-foreground' }}">
                                {{ $group['label'] }}
                            </span>
                            <span class="kt-menu-arrow text-muted-foreground w-[20px] shrink-0 flex justify-end ms-1 me-[-10px]">
                                <i class="ki-filled ki-down text-[11px] transition-transform duration-200 {{ $groupOpen ? 'rotate-180' : '' }}"
                                   data-kargah-chevron></i>
                            </span>
                        </button>

                        <div class="kt-menu-accordion gap-1 ps-[10px] relative before:absolute before:start-[20px] before:top-0 before:bottom-0 before:border-s before:border-border"
                             id="submenu-{{ $group['key'] }}">
                            @foreach ($group['items'] as $item)
                                @php $itemActive = request()->routeIs(...($item['match'] ?? [$item['route']])); @endphp
                                <div class="kt-menu-item {{ $itemActive ? 'active' : '' }}">
                                    <a class="kt-menu-link border border-transparent flex items-center grow rounded-lg hover:bg-accent/60 gap-[14px] ps-[10px] pe-[10px] py-[8px] {{ $itemActive ? 'bg-accent/60' : '' }}"
                                       href="{{ route($item['route']) }}"
                                       @if ($itemActive) aria-current="page" @endif>
                                        <span class="flex w-[6px] -start-[3px] relative before:absolute before:top-0 before:size-[6px] before:rounded-full before:-translate-y-1/2 {{ $itemActive ? 'before:bg-primary' : 'before:bg-border' }}"></span>
                                        <span class="text-2sm {{ $itemActive ? 'text-primary font-semibold' : 'text-foreground font-normal' }}">
                                            {{ $item['label'] }}
                                        </span>
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <div class="border-b border-border my-2"></div>

                @php $setActive = request()->routeIs('settings.*'); @endphp
                <div class="kt-menu-item {{ $setActive ? 'active' : '' }}">
                    <a class="kt-menu-link flex items-center grow border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] rounded-lg hover:bg-accent/60 {{ $setActive ? 'bg-accent/60' : '' }}"
                       href="{{ route('settings.profile') }}"
                       @if ($setActive) aria-current="page" @endif>
                        <span class="kt-menu-icon items-start w-[20px] {{ $setActive ? 'text-primary' : 'text-muted-foreground' }}">
                            <i class="ki-filled ki-setting-2 text-lg"></i>
                        </span>
                        <span class="kt-menu-title text-sm {{ $setActive ? 'text-primary font-semibold' : 'text-foreground font-medium' }}">
                            Settings
                        </span>
                    </a>
                </div>

            </nav>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    if (window.__kargahSidebarBound) return;
    window.__kargahSidebarBound = true;

    var STORE = 'kargah.sidebar.open';

    function readStored() {
        try { return JSON.parse(localStorage.getItem(STORE)) || {}; } catch (e) { return {}; }
    }

    function writeStored(state) {
        try { localStorage.setItem(STORE, JSON.stringify(state)); } catch (e) { /* private mode */ }
    }

    function setOpen(group, open) {
        group.classList.toggle('show', open);

        var button = group.querySelector('[data-kargah-accordion]');
        if (button) button.setAttribute('aria-expanded', open ? 'true' : 'false');

        var chevron = group.querySelector('[data-kargah-chevron]');
        if (chevron) chevron.classList.toggle('rotate-180', open);
    }

    // Restore the user's choice, but never collapse the group they are inside,
    // and never leave two groups open at once.
    function restore() {
        var stored = readStored();
        var groups = document.querySelectorAll('[data-kargah-group]');
        var active = null;

        groups.forEach(function (group) {
            if (group.querySelector('[aria-current="page"]')) active = group;
        });

        groups.forEach(function (group) {
            var key = group.dataset.kargahGroup;

            if (active) {
                setOpen(group, group === active);
            } else {
                setOpen(group, stored[key] === true);
            }
        });
    }

    document.addEventListener('click', function (e) {
        var button = e.target.closest('[data-kargah-accordion]');
        if (!button) return;

        e.preventDefault();

        var group = button.closest('[data-kargah-group]');
        if (!group) return;

        var open = !group.classList.contains('show');
        var stored = readStored();

        // Accordion, not a set of independent toggles: opening one closes the rest.
        if (open) {
            document.querySelectorAll('[data-kargah-group]').forEach(function (other) {
                if (other !== group && other.classList.contains('show')) {
                    setOpen(other, false);
                    stored[other.dataset.kargahGroup] = false;
                }
            });
        }

        setOpen(group, open);
        stored[group.dataset.kargahGroup] = open;
        writeStored(stored);
    });

    document.addEventListener('DOMContentLoaded', restore);
    document.addEventListener('livewire:navigated', restore);
    restore();
})();
</script>
@endpush
