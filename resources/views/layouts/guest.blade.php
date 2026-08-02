<!DOCTYPE html>
<html class="h-full" data-kt-theme="true" data-kt-theme-mode="light" dir="ltr" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    <title>{{ $title ?? 'Kargah' }}</title>

    <link rel="icon" href="/assets/media/app/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/vendors/keenicons/styles.bundle.css" rel="stylesheet">
    <link href="/assets/css/styles.css" rel="stylesheet">

    @livewireStyles
</head>
<body class="antialiased flex h-full text-base text-foreground bg-muted">

<script>
    const defaultThemeMode = 'light';
    let themeMode;
    if (document.documentElement) {
        themeMode = localStorage.getItem('kt-theme') || defaultThemeMode;
        if (themeMode === 'system') {
            themeMode = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        document.documentElement.classList.add(themeMode);
    }
</script>

<div class="flex items-center justify-center grow w-full p-6">
    {{ $slot }}
</div>

<script src="/assets/js/core.bundle.js"></script>
<script src="/assets/vendors/ktui/ktui.min.js"></script>

@livewireScripts
</body>
</html>
