@php
    /**
     * Sidebar navigation.
     *
     * Each module contributes one top-level group. Adding a module means adding
     * one entry here — nothing else in the layout needs to change.
     */
    $nav = [
        [
            'label' => 'Projects',
            'icon'  => 'ki-abstract-26',
            'match' => 'projects*',
            'items' => [
                ['label' => 'Boards',   'route' => 'projects.boards'],
                ['label' => 'Archive',  'route' => 'projects.archive'],
            ],
        ],
        [
            'label' => 'Accounting',
            'icon'  => 'ki-dollar',
            'match' => 'accounting*',
            'items' => [
                ['label' => 'Invoices', 'route' => 'accounting.invoices'],
                ['label' => 'Expenses', 'route' => 'accounting.expenses'],
                ['label' => 'Clients',  'route' => 'accounting.clients'],
                ['label' => 'Reports',  'route' => 'accounting.reports'],
            ],
        ],
        [
            'label' => 'Mail',
            'icon'  => 'ki-sms',
            'match' => 'mail*',
            'items' => [
                ['label' => 'Inbox',     'route' => 'mail.inbox'],
                ['label' => 'Campaigns', 'route' => 'mail.campaigns'],
                ['label' => 'Contacts',  'route' => 'mail.contacts'],
                ['label' => 'Providers', 'route' => 'mail.providers'],
            ],
        ],
        [
            'label' => 'Data',
            'icon'  => 'ki-folder',
            'match' => 'data*',
            'items' => [
                ['label' => 'Files',        'route' => 'data.files'],
                ['label' => 'Passwords',    'route' => 'data.passwords'],
                ['label' => 'Links & Bots', 'route' => 'data.links'],
                ['label' => 'GitHub Repos', 'route' => 'data.repos'],
                ['label' => 'Backups',      'route' => 'data.backups'],
            ],
        ],
        [
            'label' => 'Social',
            'icon'  => 'ki-share',
            'match' => 'social*',
            'items' => [
                ['label' => 'Notifications', 'route' => 'social.notifications'],
                ['label' => 'Publish',       'route' => 'social.publish'],
                ['label' => 'Accounts',      'route' => 'social.accounts'],
            ],
        ],
    ];
@endphp

<div class="kt-sidebar bg-background border-e border-e-border fixed top-0 bottom-0 z-20 hidden lg:flex flex-col items-stretch shrink-0 [--kt-drawer-enable:true] lg:[--kt-drawer-enable:false]"
     data-kt-drawer="true"
     data-kt-drawer-class="kt-drawer kt-drawer-start top-0 bottom-0"
     id="sidebar">

    <div class="kt-sidebar-header hidden lg:flex items-center relative justify-between px-3 lg:px-6 shrink-0" id="sidebar_header">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
            <span class="inline-flex items-center justify-center size-8 rounded-lg bg-primary text-primary-foreground font-bold text-sm shrink-0">K</span>
            <span class="kt-sidebar-collapse:hidden text-lg font-semibold text-mono tracking-tight">Kargah</span>
        </a>
        <button class="kt-btn kt-btn-outline kt-btn-icon size-[30px] absolute start-full top-2/4 -translate-x-2/4 -translate-y-2/4 rtl:translate-x-2/4"
                data-kt-toggle="body"
                data-kt-toggle-class="kt-sidebar-collapse"
                id="sidebar_toggle">
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

            <div class="kt-menu flex flex-col grow gap-1" data-kt-menu="true" data-kt-menu-accordion-expand-all="false" id="sidebar_menu">

                {{-- Dashboard: single link, no accordion --}}
                <div class="kt-menu-item {{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <a class="kt-menu-link flex items-center grow border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px] rounded-lg hover:bg-accent/60 kt-menu-item-active:bg-accent/60"
                       href="{{ route('dashboard') }}">
                        <span class="kt-menu-icon items-start text-muted-foreground w-[20px]">
                            <i class="ki-filled ki-element-11 text-lg"></i>
                        </span>
                        <span class="kt-menu-title text-sm font-medium text-foreground kt-menu-item-active:text-primary kt-menu-link-hover:!text-primary">
                            Dashboard
                        </span>
                    </a>
                </div>

                <div class="border-b border-border my-2"></div>

                @foreach ($nav as $group)
                    @php $groupActive = request()->routeIs($group['match']); @endphp

                    <div class="kt-menu-item {{ $groupActive ? 'show' : '' }}"
                         data-kt-menu-item-toggle="accordion"
                         data-kt-menu-item-trigger="click">

                        <div class="kt-menu-link flex items-center grow cursor-pointer border border-transparent gap-[10px] ps-[10px] pe-[10px] py-[6px]" tabindex="0">
                            <span class="kt-menu-icon items-start text-muted-foreground w-[20px]">
                                <i class="ki-filled {{ $group['icon'] }} text-lg"></i>
                            </span>
                            <span class="kt-menu-title text-sm font-medium text-foreground kt-menu-item-active:text-primary kt-menu-link-hover:!text-primary">
                                {{ $group['label'] }}
                            </span>
                            <span class="kt-menu-arrow text-muted-foreground w-[20px] shrink-0 justify-end ms-1 me-[-10px]">
                                <span class="inline-flex kt-menu-item-show:hidden"><i class="ki-filled ki-plus text-[11px]"></i></span>
                                <span class="hidden kt-menu-item-show:inline-flex"><i class="ki-filled ki-minus text-[11px]"></i></span>
                            </span>
                        </div>

                        <div class="kt-menu-accordion gap-1 ps-[10px] relative before:absolute before:start-[20px] before:top-0 before:bottom-0 before:border-s before:border-border">
                            @foreach ($group['items'] as $item)
                                <div class="kt-menu-item {{ request()->routeIs($item['route']) ? 'active' : '' }}">
                                    <a class="kt-menu-link border border-transparent items-center grow kt-menu-item-active:bg-accent/60 kt-menu-item-active:rounded-lg hover:bg-accent/60 hover:rounded-lg gap-[14px] ps-[10px] pe-[10px] py-[8px]"
                                       href="{{ route($item['route']) }}"
                                       tabindex="0">
                                        <span class="kt-menu-bullet flex w-[6px] -start-[3px] relative before:absolute before:top-0 before:size-[6px] before:rounded-full before:-translate-y-1/2 kt-menu-item-active:before:bg-primary kt-menu-item-hover:before:bg-primary"></span>
                                        <span class="kt-menu-title text-2sm font-normal text-foreground kt-menu-item-active:text-primary kt-menu-item-active:font-semibold kt-menu-link-hover:!text-primary">
                                            {{ $item['label'] }}
                                        </span>
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach

            </div>
        </div>
    </div>
</div>
