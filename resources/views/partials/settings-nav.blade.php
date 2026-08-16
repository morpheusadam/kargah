@php
    /*
     | The settings index, grouped by what somebody is trying to do.
     |
     | It used to be a flat list of six tabs in the order the pages happened to
     | be built — Profile, Security, Appearance, Notifications, Application
     | passwords, Assistant — which is the order of the tables behind them and
     | not the order of any question a person arrives with. Somebody who wants
     | to stop an old laptop reaching Kargah has to already know whether that
     | lives under "Security" (a session) or under "Application passwords" (a
     | credential); somebody who wants Kargah to stop emailing them at night has
     | to guess between "Profile" and "Notifications". The three headings below
     | are the three questions, and every tab sits under the question it
     | answers.
     |
     | Each tab also carries the settings on it, each with the one-line sentence
     | naming what visibly changes when it is altered. That list is what the
     | search box matches on, which is the point: six tabs is small enough to
     | scan and roughly thirty settings is not, so searching "password" has to
     | find the password field on Security *and* the credential store on
     | Application passwords, neither of which has the word in its tab label.
     |
     | The `Route::has()` filter is unchanged and load-bearing. Four of these
     | are application routes and two belong to `Modules\Platform`; a disabled
     | module deregisters its routes, so the tab has to disappear rather than
     | blow up inside `route()`. The same guard the sidebar uses, for the same
     | reason.
     |
     | `$settingsFilter` is a public property on each of the six components that
     | include this partial — Blade includes inherit the including view's data,
     | so binding to it here binds to the component that rendered it. The
     | `?? ''` fallback keeps the partial renderable from anything that has not
     | declared the property rather than fataling on an undefined variable.
     */
    $filter = trim((string) ($settingsFilter ?? ''));

    $groups = [
        [
            'heading' => 'Who you are',
            'tabs' => [
                [
                    'route' => 'settings.profile',
                    'label' => 'Profile',
                    'icon' => 'ki-user',
                    'hint' => 'Your name, your address, and how dates read',
                    'settings' => [
                        ['label' => 'Name', 'anchor' => 'identity', 'changes' => 'Changes the name on your avatar chip, on invoices and in the activity log.'],
                        ['label' => 'Email address', 'anchor' => 'identity', 'changes' => 'Changes the address you sign in with and the one notification email is sent to.'],
                        ['label' => 'Short bio', 'anchor' => 'identity', 'changes' => 'Changes the paragraph printed under your name on invoices and proposals.'],
                        ['label' => 'Time zone', 'anchor' => 'regional', 'changes' => 'Changes the clock every due date, invoice date and "last active" time is read against.'],
                        ['label' => 'Language', 'anchor' => 'regional', 'changes' => "Changes the language of Kargah's own labels and buttons."],
                        ['label' => 'Date format', 'anchor' => 'regional', 'changes' => 'Changes how every date on every page is written out.'],
                    ],
                ],
                [
                    'route' => 'settings.appearance',
                    'label' => 'Appearance',
                    'icon' => 'ki-color-swatch',
                    'hint' => 'Light or dark, and label patterns',
                    'settings' => [
                        ['label' => 'Theme', 'anchor' => 'theme', 'changes' => 'Switches the page background and text between dark and light immediately, in this browser only.'],
                        ['label' => 'Colour-blind label patterns', 'anchor' => 'colour-blind', 'changes' => 'Puts a stripe or dot pattern on every label chip on cards and boards, as well as its colour.'],
                    ],
                ],
            ],
        ],
        [
            'heading' => 'Keeping people out',
            'tabs' => [
                [
                    'route' => 'settings.security',
                    'label' => 'Security',
                    'icon' => 'ki-shield-tick',
                    'hint' => 'Password, second factor, signed-in devices',
                    'settings' => [
                        ['label' => 'Password', 'anchor' => 'password', 'changes' => 'Changes what you type to sign in, and signs every other device out on the spot.'],
                        ['label' => 'Two-factor authentication', 'anchor' => 'two-factor', 'changes' => 'Turning it on makes every sign-in ask for a six-digit code after the password.'],
                        ['label' => 'Recovery codes', 'anchor' => 'two-factor', 'changes' => 'Generating new ones stops every code you already wrote down from working.'],
                        ['label' => 'Signed-in devices', 'anchor' => 'sessions', 'changes' => 'Signing a device out ends its session, so it lands on the sign-in page next time.'],
                    ],
                ],
            ],
        ],
        [
            'heading' => 'What reaches you, and what reaches in',
            'tabs' => [
                [
                    'route' => 'settings.notifications',
                    'label' => 'Notifications',
                    'icon' => 'ki-notification-status',
                    'hint' => 'Which events reach you, and by which route',
                    'settings' => [
                        ['label' => 'Event switches', 'anchor' => 'events', 'changes' => 'Each switch decides whether that event lands in the bell feed, in your inbox, or nowhere.'],
                        ['label' => 'Email digest', 'anchor' => 'delivery', 'changes' => 'Changes whether email arrives one message at a time, once a day, once a week, or never.'],
                        ['label' => 'Quiet hours', 'anchor' => 'delivery', 'changes' => 'Holds email back between the two times you set; the bell feed still updates as things happen.'],
                    ],
                ],
                [
                    'route' => 'platform.application-passwords',
                    'label' => 'Application passwords',
                    'icon' => 'ki-key',
                    'hint' => 'Credentials for scripts, the CLI and the API',
                    'settings' => [
                        ['label' => 'Issue a password', 'anchor' => 'new', 'changes' => 'Creates a credential a script can sign in with, shown once and never again.'],
                        ['label' => 'Scopes', 'anchor' => 'new', 'changes' => 'Decide which parts of Kargah that one credential may read or change.'],
                        ['label' => 'Expiry date', 'anchor' => 'new', 'changes' => 'Sets the day the credential stops working on its own, with no action from you.'],
                        ['label' => 'Revoke', 'anchor' => 'issued', 'changes' => 'Stops that credential immediately, so any live script still using it starts getting a 401.'],
                    ],
                ],
                [
                    'route' => 'platform.assistant',
                    'label' => 'Assistant',
                    'icon' => 'ki-message-programming',
                    'hint' => 'Which AI service answers, and with which key',
                    'settings' => [
                        ['label' => 'Provider and model', 'anchor' => 'providers', 'changes' => 'Changes which AI service answers the assistant, and which of its models.'],
                        ['label' => 'API key', 'anchor' => 'providers', 'changes' => 'Replaces the stored key; a wrong one makes the assistant fail on the very next question.'],
                        ['label' => 'Default provider', 'anchor' => 'providers', 'changes' => 'Decides which provider the assistant asks when nothing names one explicitly.'],
                        ['label' => 'Active', 'anchor' => 'providers', 'changes' => "Switching a provider off takes it out of the assistant's reach without deleting its key."],
                    ],
                ],
            ],
        ],
    ];

    /*
     | Filtering happens in two passes rather than one, because a tab whose
     | *label* matches ("Security") should keep all of its settings, while a tab
     | matched only through one of its settings ("password" finding Application
     | passwords) should show just the settings that matched. Collapsing both
     | into a single filter would either hide the rest of Security's contents or
     | list all four credential settings for a search that only wanted one.
     */
    $matches = static function (string $haystack) use ($filter): bool {
        return $filter === '' || str_contains(mb_strtolower($haystack), mb_strtolower($filter));
    };

    $visible = [];
    // Counted rather than written as "6": a disabled module drops its tab, and
    // a footer claiming "1 of 6" on an install that only has five is the kind
    // of small lie that makes somebody hunt for a page that is not there.
    $availableTabs = 0;

    foreach ($groups as $group) {
        $tabs = [];

        foreach ($group['tabs'] as $tab) {
            if (! Route::has($tab['route'])) {
                continue;
            }

            $availableTabs++;

            $tabMatched = $matches($tab['label'].' '.$tab['hint']);

            $settings = array_values(array_filter(
                $tab['settings'],
                fn (array $setting): bool => $matches($setting['label'].' '.$setting['changes']),
            ));

            if ($filter !== '' && ! $tabMatched && $settings === []) {
                continue;
            }

            $tab['settings'] = $filter === '' || $tabMatched ? $tab['settings'] : $settings;
            // Only a search shows the settings underneath a tab. Unfiltered,
            // thirty lines of explanation in a 3-column sidebar would bury the
            // six links that are the reason the sidebar exists.
            $tab['showSettings'] = $filter !== '';

            $tabs[] = $tab;
        }

        if ($tabs !== []) {
            $visible[] = ['heading' => $group['heading'], 'tabs' => $tabs];
        }
    }

    $matchedTabs = array_sum(array_map(fn (array $group): int => count($group['tabs']), $visible));
@endphp

<div class="kt-card">

    @isset($settingsFilter)
        <div class="kt-card-content p-3 border-b border-border">
            <div class="relative">
                <i class="ki-filled ki-magnifier absolute start-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground"></i>
                <input type="search"
                       class="kt-input ps-9 pe-9"
                       placeholder="Search settings"
                       aria-label="Search settings"
                       wire:model.live.debounce.250ms="settingsFilter">
                @if ($filter !== '')
                    <button type="button"
                            class="absolute end-2 top-1/2 -translate-y-1/2 kt-btn kt-btn-icon kt-btn-ghost size-6"
                            wire:click="$set('settingsFilter', '')"
                            title="Clear the search" aria-label="Clear the search">
                        <i class="ki-filled ki-cross text-xs"></i>
                    </button>
                @endif
            </div>
            <div wire:loading wire:target="settingsFilter" class="text-xs text-muted-foreground mt-2 flex items-center gap-1.5">
                <i class="ki-filled ki-loading animate-spin text-xs"></i> Filtering…
            </div>
        </div>
    @endisset

    <div class="kt-card-content p-2 flex flex-col gap-3">

        @forelse ($visible as $group)
            <div class="flex flex-col gap-0.5">
                <span class="px-3 pt-1 pb-1.5 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                    {{ $group['heading'] }}
                </span>

                @foreach ($group['tabs'] as $tab)
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

                    @if ($tab['showSettings'])
                        <div class="flex flex-col gap-0.5 ps-6 pe-2 pb-1">
                            @foreach ($tab['settings'] as $setting)
                                <a href="{{ route($tab['route']) }}#{{ $setting['anchor'] }}"
                                   class="block rounded-md px-3 py-1.5 hover:bg-accent/40">
                                    <span class="block text-xs font-medium text-mono">{{ $setting['label'] }}</span>
                                    <span class="block text-[11px] text-muted-foreground">{{ $setting['changes'] }}</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                @endforeach
            </div>
        @empty
            <div class="flex flex-col items-center text-center gap-2 px-4 py-10">
                <i class="ki-filled ki-magnifier text-2xl text-muted-foreground"></i>
                <p class="text-xs text-secondary-foreground">
                    No setting matches "{{ $filter }}".
                </p>
                <button type="button" class="kt-btn kt-btn-sm kt-btn-outline" wire:click="$set('settingsFilter', '')">
                    Show every setting
                </button>
            </div>
        @endforelse

    </div>

    @if ($filter !== '' && $matchedTabs > 0)
        <div class="kt-card-footer px-4 py-2">
            <span class="text-[11px] text-muted-foreground">
                {{ $matchedTabs }} of {{ $availableTabs }} settings pages match "{{ $filter }}".
            </span>
        </div>
    @endif

</div>
