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
    {{-- Login staging. A real file because Livewire carries neither a pushed
         stack nor @assets from a component through to its layout. --}}
    <link href="/css/login.css" rel="stylesheet">

    @livewireStyles
</head>
<body class="antialiased h-full text-base text-foreground bg-background">

{{--
    The signed-out screens are dark, full stop — the design is built around the
    glow and there is no light variant of it. A stored preference from inside the
    app must not leak out here, so this ignores localStorage rather than reading
    it. Applied before first paint, so there is no flash.
--}}
<script>
    document.documentElement.classList.remove('light');
    document.documentElement.classList.add('dark');
</script>

{{-- The guest shell is deliberately bare: each guest page owns its own staging. --}}
{{ $slot }}

@include('partials.toasts')

<script src="/assets/js/core.bundle.js"></script>
<script src="/assets/vendors/ktui/ktui.min.js"></script>

@livewireScripts
@stack('scripts')
</body>
</html>
