<header class="kt-header fixed top-0 z-10 start-0 end-0 flex items-stretch shrink-0 bg-background"
        data-kt-sticky="true"
        data-kt-sticky-class="border-b border-border"
        data-kt-sticky-name="header"
        id="header">

    <div class="kt-container-fixed flex justify-between items-stretch lg:gap-4" id="headerContainer">

        {{-- Mobile: logo + drawer toggle --}}
        <div class="flex gap-1 items-center lg:hidden">
            <a href="{{ route('dashboard') }}">
                <x-brand-mark :size="8" name-class="text-base font-semibold text-mono" />
            </a>
            {{--
                The only way to reach the sidebar below `lg`, and it had no
                accessible name at all — so on every page of the application, at
                every mobile width, a screen reader announced the one control
                that opens the navigation as "button". `title` as well as
                `aria-label` because a pointer user gets nothing from the first.
            --}}
            <button class="kt-btn kt-btn-icon kt-btn-ghost -ms-2"
                    data-kt-drawer-toggle="#sidebar"
                    title="Open the menu"
                    aria-label="Open the menu">
                <i class="ki-filled ki-menu text-lg"></i>
            </button>
        </div>

        {{-- Page heading --}}
        <div class="hidden lg:flex items-center gap-3">
            <h1 class="text-base font-medium text-mono">{{ $title ?? 'Dashboard' }}</h1>
            @isset($subtitle)
                <span class="text-sm text-secondary-foreground">{{ $subtitle }}</span>
            @endisset
        </div>

        {{-- Right side --}}
        <div class="flex items-center gap-2 lg:gap-3.5">

            <button type="button"
                    onclick="window.Livewire && Livewire.dispatch('palette-open')"
                    class="hidden md:flex items-center gap-2 h-9 px-3 rounded-lg border border-border text-sm text-muted-foreground hover:border-primary/40 transition-colors"
                    title="Search (Ctrl+K)">
                <i class="ki-filled ki-magnifier text-base"></i>
                <span>Search…</span>
                <kbd class="ms-6 text-[10px] px-1.5 py-0.5 rounded border border-border">Ctrl K</kbd>
            </button>

            <button type="button"
                    onclick="window.Livewire && Livewire.dispatch('palette-open')"
                    class="kt-btn kt-btn-icon kt-btn-ghost size-9 md:hidden" title="Search">
                <i class="ki-filled ki-magnifier text-lg"></i>
            </button>

            <button type="button"
                    class="kt-btn kt-btn-icon kt-btn-ghost size-9"
                    onclick="window.kargahTheme && window.kargahTheme.toggle()"
                    title="Toggle theme"
                    aria-label="Toggle theme">
                <i class="ki-filled ki-moon text-lg dark:hidden"></i>
                <i class="ki-filled ki-sun text-lg hidden dark:inline"></i>
            </button>

            {{-- The bell.

                 Core owns the feed, because a notification's subject may be a
                 card, an invoice or an email and only Core may be depended on
                 by all three. The count is read through Core's contract rather
                 than its model, and the whole thing disappears rather than
                 throwing if the module is ever disabled.

                 It is plain Blade, so the number is whatever it was when the
                 page was served — the layout does not re-render on a Livewire
                 round trip. That is the right trade for one query per page
                 load; the feed itself keeps a live count. --}}
            @if (Route::has('core.notifications') && auth()->check())
                @php
                    $unreadNotifications = app(\Modules\Core\Contracts\Notifier::class)->unreadCount(auth()->id());
                @endphp
                <a href="{{ route('core.notifications') }}" wire:navigate
                   class="kt-btn kt-btn-icon kt-btn-ghost size-9 relative"
                   title="{{ $unreadNotifications === 0 ? 'Notifications' : $unreadNotifications.' unread' }}"
                   aria-label="Notifications">
                    <i class="ki-filled ki-notification-status text-lg"></i>
                    @if ($unreadNotifications > 0)
                        <span class="absolute -top-0.5 -end-0.5 min-w-4 h-4 px-1 rounded-full bg-destructive text-white text-[10px] font-semibold inline-flex items-center justify-center"
                              data-kargah-bell-count>{{ $unreadNotifications > 99 ? '99+' : $unreadNotifications }}</span>
                    @endif
                </a>
            @endif

            {{-- User menu --}}
            <div data-kt-dropdown="true" data-kt-dropdown-offset="10px, 10px" data-kt-dropdown-placement="bottom-end" data-kt-dropdown-trigger="click">
                <button class="flex items-center gap-2 cursor-pointer" data-kt-dropdown-toggle="true" aria-label="Account menu">
                    <span class="inline-flex items-center justify-center size-9 rounded-full bg-primary/10 text-primary text-sm font-semibold">
                        {{ strtoupper(substr(auth()->user()?->name ?? 'K', 0, 1)) }}
                    </span>
                </button>

                <div class="kt-dropdown-menu w-[220px] p-0" data-kt-dropdown-menu="true">
                    <div class="px-4 py-3 border-b border-border">
                        <div class="text-sm font-semibold text-mono truncate">{{ auth()->user()?->name ?? 'Guest' }}</div>
                        <div class="text-xs text-secondary-foreground truncate">{{ auth()->user()?->email }}</div>
                    </div>
                    <div class="p-2 flex flex-col gap-0.5">
                        <a href="{{ route('settings.profile') }}" class="kt-btn kt-btn-ghost w-full justify-start gap-2">
                            <i class="ki-filled ki-user text-base"></i> Profile
                        </a>
                        <a href="{{ route('settings.appearance') }}" class="kt-btn kt-btn-ghost w-full justify-start gap-2">
                            <i class="ki-filled ki-color-swatch text-base"></i> Appearance
                        </a>
                        <a href="{{ route('settings.security') }}" class="kt-btn kt-btn-ghost w-full justify-start gap-2">
                            <i class="ki-filled ki-shield-tick text-base"></i> Security
                        </a>

                        <div class="border-t border-border my-1"></div>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="kt-btn kt-btn-ghost w-full justify-start gap-2 text-destructive">
                                <i class="ki-filled ki-exit-right text-base"></i>
                                Sign out
                            </button>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>
</header>
