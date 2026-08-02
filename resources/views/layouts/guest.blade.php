<!DOCTYPE html>
<html class="h-full" data-kt-theme="true" data-kt-theme-mode="dark" dir="ltr" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="dark light">

    <title>{{ $title ?? 'Kargah' }}</title>

    <link rel="icon" href="/assets/media/app/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/vendors/keenicons/styles.bundle.css" rel="stylesheet">
    <link href="/assets/css/styles.css" rel="stylesheet">
    <link href="/assets/css/kargah.css" rel="stylesheet">

    @livewireStyles
</head>
<body class="antialiased h-full text-base text-foreground bg-background">

{{-- Applied before first paint so the page never flashes the wrong theme. --}}
<script>
    (function () {
        var mode = localStorage.getItem('kt-theme') || 'dark';
        if (mode === 'system') {
            mode = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        document.documentElement.classList.add(mode);
    })();
</script>

<div class="grid lg:grid-cols-2 h-full">

    {{-- Brand side. Hidden on small screens; the form is what matters there. --}}
    <div class="hidden lg:flex flex-col justify-between p-12 relative overflow-hidden bg-muted/40 border-e border-border">

        <div class="absolute -top-32 -start-32 size-[420px] rounded-full bg-primary/10 blur-3xl"></div>
        <div class="absolute -bottom-40 -end-24 size-[380px] rounded-full bg-primary/5 blur-3xl"></div>

        <div class="relative flex items-center gap-2.5">
            <span class="inline-flex items-center justify-center size-9 rounded-lg bg-primary text-primary-foreground font-bold">K</span>
            <span class="text-lg font-semibold text-mono tracking-tight">Kargah</span>
        </div>

        <div class="relative max-w-[440px]">
            <h1 class="text-3xl font-semibold text-mono leading-tight">
                Your whole practice,<br>behind one login.
            </h1>
            <p class="text-sm text-secondary-foreground mt-4 leading-relaxed">
                Mail, boards, invoices and a private vault in a single app you host yourself.
                No five subscriptions, no data spread across five companies.
            </p>

            <div class="flex flex-col gap-3.5 mt-9">
                @foreach ([
                    ['ki-sms', 'Inbox and bulk campaigns', 'IMAP in, routed sending out'],
                    ['ki-abstract-26', 'Boards that are really boards', 'Lists and cards you name yourself'],
                    ['ki-dollar', 'Invoices in USD, TRY and USDT', 'Rates fixed at the moment you issue'],
                    ['ki-lock', 'An encrypted vault', 'Credentials, files and backups'],
                ] as [$icon, $title, $sub])
                    <div class="flex items-start gap-3">
                        <span class="inline-flex items-center justify-center size-8 rounded-lg bg-primary/10 text-primary shrink-0 mt-0.5">
                            <i class="ki-filled {{ $icon }} text-base"></i>
                        </span>
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-mono">{{ $title }}</span>
                            <span class="block text-xs text-muted-foreground">{{ $sub }}</span>
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="relative flex items-center gap-4 text-xs text-muted-foreground">
            <span>Self-hosted</span>
            <span class="size-1 rounded-full bg-border"></span>
            <span>MIT licensed</span>
            <span class="size-1 rounded-full bg-border"></span>
            <a href="https://github.com/morpheusadam/kargah" target="_blank" rel="noopener" class="hover:text-primary transition-colors">GitHub</a>
        </div>
    </div>

    {{-- Form side --}}
    <div class="flex flex-col items-center justify-center p-6 lg:p-12 relative">

        <div class="absolute top-6 end-6 flex items-center gap-1">
            <button type="button"
                    class="kt-btn kt-btn-icon kt-btn-ghost size-9"
                    data-kt-toggle="html"
                    data-kt-toggle-class="dark"
                    title="Switch theme"
                    aria-label="Switch theme">
                <i class="ki-filled ki-moon text-base hidden dark:inline"></i>
                <i class="ki-filled ki-sun text-base dark:hidden"></i>
            </button>
        </div>

        <div class="lg:hidden flex items-center gap-2.5 mb-10">
            <span class="inline-flex items-center justify-center size-9 rounded-lg bg-primary text-primary-foreground font-bold">K</span>
            <span class="text-lg font-semibold text-mono tracking-tight">Kargah</span>
        </div>

        {{ $slot }}
    </div>

</div>

<script src="/assets/js/core.bundle.js"></script>
<script src="/assets/vendors/ktui/ktui.min.js"></script>

@livewireScripts
@stack('scripts')
</body>
</html>
