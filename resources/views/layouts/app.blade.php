<!DOCTYPE html>
<html class="h-full" data-kt-theme="true" data-kt-theme-mode="dark" dir="ltr" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Kargah' }}</title>

    <link rel="icon" href="/assets/media/app/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/vendors/keenicons/styles.bundle.css" rel="stylesheet">
    <link href="/assets/css/styles.css" rel="stylesheet">
    {{-- Utilities the prebuilt theme bundle does not contain. See docs/theme.md. --}}
    <link href="/assets/css/kargah.css" rel="stylesheet">

    @livewireStyles
</head>
<body class="antialiased flex h-full text-base text-foreground bg-background demo1 kt-sidebar-fixed kt-header-fixed">

<script>
    const defaultThemeMode = 'dark';
    let themeMode;
    if (document.documentElement) {
        if (localStorage.getItem('kt-theme')) {
            themeMode = localStorage.getItem('kt-theme');
        } else if (document.documentElement.hasAttribute('data-kt-theme-mode')) {
            themeMode = document.documentElement.getAttribute('data-kt-theme-mode');
        } else {
            themeMode = defaultThemeMode;
        }
        if (themeMode === 'system') {
            themeMode = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        document.documentElement.classList.add(themeMode);
    }
</script>

<div class="flex grow">

    @include('partials.sidebar')

    <div class="kt-wrapper flex grow flex-col">

        @include('partials.header')

        <main class="grow pt-5" id="content" role="content">
            <div class="kt-container-fixed">
                {{ $slot }}
            </div>
        </main>

        <footer class="kt-footer">
            <div class="kt-container-fixed">
                <div class="flex flex-col md:flex-row justify-center md:justify-between items-center gap-3 py-5">
                    <div class="flex order-2 md:order-1 gap-2 text-sm">
                        <span class="text-muted-foreground">{{ date('Y') }} &copy;</span>
                        <span class="text-secondary-foreground font-medium">Kargah</span>
                    </div>
                    <nav class="flex order-1 md:order-2 gap-4 text-sm">
                        <a class="text-secondary-foreground hover:text-primary" href="https://github.com/morpheusadam/kargah" target="_blank">GitHub</a>
                        <a class="text-secondary-foreground hover:text-primary" href="#">Docs</a>
                    </nav>
                </div>
            </div>
        </footer>

    </div>
</div>

<livewire:command-palette />

@include('partials.toasts')

{{--
    Only what every page needs. Heavy single-purpose libraries (ApexCharts 563 KB,
    FullCalendar 277 KB, TinyMCE, DataTables, Dropzone) are loaded by the page that
    uses them via @push('scripts'), not from here — see docs/frontend-conventions.md.
--}}
<script src="/assets/js/core.bundle.js"></script>
<script src="/assets/vendors/ktui/ktui.min.js"></script>
{{-- The theme bundles Sortable as a webpack module with no global, so ship it separately. --}}
<script src="/vendor/sortablejs/Sortable.min.js"></script>
<script src="/assets/js/layouts/demo1.js"></script>

@livewireScripts
@stack('scripts')
</body>
</html>
