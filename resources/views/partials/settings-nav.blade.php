@php
    $tabs = [
        ['route' => 'settings.profile',       'label' => 'Profile',       'icon' => 'ki-user',           'hint' => 'Name, avatar, locale'],
        ['route' => 'settings.security',      'label' => 'Security',      'icon' => 'ki-shield-tick',    'hint' => 'Password, sessions, tokens'],
        ['route' => 'settings.appearance',    'label' => 'Appearance',    'icon' => 'ki-color-swatch',   'hint' => 'Theme and density'],
        ['route' => 'settings.notifications', 'label' => 'Notifications', 'icon' => 'ki-notification-status', 'hint' => 'What reaches you, and how'],
        ['route' => 'platform.application-passwords', 'label' => 'Application passwords', 'icon' => 'ki-key', 'hint' => 'Credentials for scripts and the API'],
        ['route' => 'platform.assistant', 'label' => 'Assistant', 'icon' => 'ki-message-programming', 'hint' => 'AI provider, model and API key'],
    ];

    // Drop anything whose module has been disabled, rather than blowing up on
    // route(). The same guard the sidebar uses, and for the same reason: the
    // first four tabs are application routes, the fifth belongs to a module.
    $tabs = array_values(array_filter($tabs, fn (array $tab): bool => Route::has($tab['route'])));
@endphp

<div class="kt-card">
    <div class="kt-card-content p-2 flex flex-col gap-0.5">
        @foreach ($tabs as $tab)
            <a href="{{ route($tab['route']) }}"
               class="flex items-start gap-3 px-3 py-2.5 rounded-lg transition-colors
                      {{ request()->routeIs($tab['route']) ? 'bg-accent/60' : 'hover:bg-accent/40' }}">
                <i class="ki-filled {{ $tab['icon'] }} text-lg shrink-0 mt-0.5
                          {{ request()->routeIs($tab['route']) ? 'text-primary' : 'text-muted-foreground' }}"></i>
                <span class="min-w-0">
                    <span class="block text-sm font-medium {{ request()->routeIs($tab['route']) ? 'text-primary' : 'text-mono' }}">
                        {{ $tab['label'] }}
                    </span>
                    <span class="block text-xs text-muted-foreground">{{ $tab['hint'] }}</span>
                </span>
            </a>
        @endforeach
    </div>
</div>
