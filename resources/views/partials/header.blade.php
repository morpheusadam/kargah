<header class="kt-header fixed top-0 z-10 start-0 end-0 flex items-stretch shrink-0 bg-background"
        data-kt-sticky="true"
        data-kt-sticky-class="border-b border-border"
        data-kt-sticky-name="header"
        id="header">

    <div class="kt-container-fixed flex justify-between items-stretch lg:gap-4" id="headerContainer">

        {{-- Mobile: logo + drawer toggle --}}
        <div class="flex gap-1 items-center lg:hidden">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2">
                <span class="inline-flex items-center justify-center size-8 rounded-lg bg-primary text-primary-foreground font-bold text-sm">K</span>
                <span class="text-base font-semibold text-mono">Kargah</span>
            </a>
            <button class="kt-btn kt-btn-icon kt-btn-ghost -ms-2" data-kt-drawer-toggle="#sidebar">
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

            <button class="kt-btn kt-btn-icon kt-btn-ghost size-9" data-kt-toggle="html" data-kt-toggle-class="dark" title="Toggle theme">
                <i class="ki-filled ki-moon text-lg dark:hidden"></i>
                <i class="ki-filled ki-sun text-lg hidden dark:inline"></i>
            </button>

            <button class="kt-btn kt-btn-icon kt-btn-ghost size-9 relative" title="Notifications">
                <i class="ki-filled ki-notification-status text-lg"></i>
                <span class="absolute top-1.5 end-1.5 size-2 rounded-full bg-destructive"></span>
            </button>

            {{-- User menu --}}
            <div class="kt-dropdown" data-kt-dropdown="true" data-kt-dropdown-offset="10px, 10px" data-kt-dropdown-placement="bottom-end" data-kt-dropdown-trigger="click">
                <button class="flex items-center gap-2 cursor-pointer" data-kt-dropdown-toggle="true">
                    <span class="inline-flex items-center justify-center size-9 rounded-full bg-primary/10 text-primary text-sm font-semibold">
                        {{ strtoupper(substr(auth()->user()?->name ?? 'K', 0, 1)) }}
                    </span>
                </button>

                <div class="kt-dropdown-content w-[220px]" data-kt-dropdown-content="true">
                    <div class="px-4 py-3 border-b border-border">
                        <div class="text-sm font-semibold text-mono truncate">{{ auth()->user()?->name ?? 'Guest' }}</div>
                        <div class="text-xs text-secondary-foreground truncate">{{ auth()->user()?->email }}</div>
                    </div>
                    <div class="p-2">
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
