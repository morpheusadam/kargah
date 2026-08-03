<!DOCTYPE html>
{{-- `dark` is on the element itself, not added by a script. There is no moment
     in the page's life when this screen is light. --}}
<html class="h-full dark" data-kt-theme="true" data-kt-theme-mode="dark" dir="ltr" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="dark light">

    <title>{{ $title ?? 'Kargah' }}</title>
    @include('partials.head-icons')
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/vendors/keenicons/styles.bundle.css" rel="stylesheet">
    <link href="/assets/css/styles.css" rel="stylesheet">
    <link href="/assets/css/kargah.css" rel="stylesheet">
    {{-- Login staging. A real file because Livewire carries neither a pushed
         stack nor @assets from a component through to its layout. The version
         query is the file's own mtime, so an edit is never served from cache. --}}
    <link href="/css/login.css?v={{ @filemtime(public_path('css/login.css')) ?: 1 }}" rel="stylesheet">

    @livewireStyles
</head>
<body class="antialiased h-full text-base text-foreground bg-background">

{{--
    The signed-out screens are dark, full stop — the design is built around the
    glow and there is no light variant of it. `dark` is on <html> server-side and
    nothing on this page loads that could take it off again: see the note by the
    scripts at the bottom.
--}}

{{-- The guest shell is deliberately bare: each guest page owns its own staging. --}}
{{ $slot }}

@include('partials.toasts')

{{--
    The theme bundle is deliberately not loaded here.

    KTUI's theme module reads its own `kt-theme` key and, in _bindMode, strips
    both `dark` and `light` off <html> before applying whatever it found. A stale
    'light' in that key was what made this page load dark and then snap to light.

    The only vendor behaviour this screen used was the password reveal, which is
    a dozen lines. Dropping the two bundles removes the fight, and takes about
    1.1 MB off a page that needs none of it.
--}}
<script>
    (function () {
        document.addEventListener('click', function (e) {
            var trigger = e.target.closest('[data-kt-toggle-password-trigger]');
            if (!trigger) return;

            var field = trigger.closest('[data-kt-toggle-password]');
            var input = field && field.querySelector('input');
            if (!input) return;

            var revealed = input.type === 'text';
            input.type = revealed ? 'password' : 'text';
            field.classList.toggle('toggle-password-active', !revealed);
            trigger.setAttribute('aria-label', revealed ? 'Show password' : 'Hide password');
        });
    })();
</script>

@livewireScripts
@stack('scripts')
</body>
</html>
