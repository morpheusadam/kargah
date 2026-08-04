<!DOCTYPE html>
<html class="h-full" data-kt-theme="true" data-kt-theme-mode="dark" dir="ltr" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Kargah' }}</title>
    @include('partials.head-icons')
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/vendors/keenicons/styles.bundle.css" rel="stylesheet">
    <link href="/assets/css/styles.css" rel="stylesheet">
    {{-- Utilities the prebuilt theme bundle does not contain. See docs/theme.md. --}}
    <link href="/assets/css/kargah.css" rel="stylesheet">

    @livewireStyles
</head>
<body class="antialiased flex h-full text-base text-foreground bg-background demo1 kt-sidebar-fixed kt-header-fixed">

{{--
    Theme, owned here rather than by the vendor bundle.

    The previous version read Metronic's own `kt-theme` key, so a value left
    behind by the template's demo scripts could pin the app to light with no way
    to tell where it came from. This uses its own key, treats anything it does
    not recognise as dark, and runs before first paint so there is no flash.
--}}
<script>
    (function () {
        var stored = localStorage.getItem('kargah.theme');
        var mode = (stored === 'light' || stored === 'dark') ? stored : 'dark';

        var apply = function (next) {
            document.documentElement.classList.remove('light', 'dark');
            document.documentElement.classList.add(next);

            try {
                localStorage.setItem('kargah.theme', next);
                // KTUI's theme module reads its own key and, in _bindMode, strips
                // both classes off <html> before applying whatever it finds there.
                // Keeping the two in step is what stops it undoing this on load.
                localStorage.setItem('kt-theme', next);
            } catch (e) { /* private mode */ }
        };

        apply(mode);

        window.kargahTheme = {
            current: function () {
                return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
            },
            set: apply,
            toggle: function () {
                apply(this.current() === 'dark' ? 'light' : 'dark');
            }
        };
    })();
</script>

{{--
    `min-w-0` on both of these, and it has to be both.

    A flex item's default `min-width` is `auto`, which means "never shrink below
    your content's minimum". The board canvas is a horizontally scrolling strip
    of lists; its minimum is far wider than the screen, and without these two
    the whole page inherited that minimum and scrolled sideways — sidebar, header,
    footer and all — while the canvas's own scrollbar sat there doing nothing.
    Measured at a 1302px viewport: the document was 1560px wide.

    Both, because the chain has two flex items in it and each independently
    refuses to shrink: this row is an item of `body`, and `.kt-wrapper` is an
    item of this row. Setting it on either one alone changes nothing at all,
    which is exactly the sort of half-fix that gets reverted as "it did not
    work". See docs/frontend-conventions.md — "the page body never scrolls
    sideways" is the rule this was breaking on every wide page.
--}}
<div class="flex grow min-w-0">

    @include('partials.sidebar')

    <div class="kt-wrapper flex grow flex-col min-w-0">

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
