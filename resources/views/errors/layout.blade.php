<!DOCTYPE html>
<html class="h-full" data-kt-theme="true" data-kt-theme-mode="light" dir="ltr" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="robots" content="noindex, nofollow">

    <title>@yield('code') — Kargah</title>

    <link rel="icon" href="/assets/media/app/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/vendors/keenicons/styles.bundle.css" rel="stylesheet">
    <link href="/assets/css/styles.css" rel="stylesheet">
</head>
<body class="antialiased flex h-full text-base text-foreground bg-muted">

<script>
    if (document.documentElement) {
        document.documentElement.classList.add(localStorage.getItem('kt-theme') || 'light');
    }
</script>

<div class="flex flex-col items-center justify-center grow w-full p-6 text-center">

    <a href="/" class="flex items-center gap-2 mb-10">
        <span class="inline-flex items-center justify-center size-9 rounded-lg bg-primary text-primary-foreground font-bold">K</span>
        <span class="text-lg font-semibold text-mono">Kargah</span>
    </a>

    <div class="inline-flex items-center justify-center size-16 rounded-2xl bg-@yield('tone', 'muted-foreground')/10 mb-6">
        <i class="ki-filled @yield('icon', 'ki-information-2') text-3xl text-@yield('tone', 'muted-foreground')"></i>
    </div>

    <div class="text-[64px] leading-none font-semibold text-mono tracking-tight">@yield('code')</div>

    <h1 class="text-xl font-semibold text-mono mt-4">@yield('heading')</h1>

    <p class="text-sm text-secondary-foreground mt-2 max-w-[420px]">@yield('message')</p>

    <div class="flex flex-wrap items-center justify-center gap-2 mt-8">
        <a href="{{ url()->previous() }}" class="kt-btn kt-btn-outline gap-2">
            <i class="ki-filled ki-black-left"></i> Go back
        </a>
        <a href="{{ auth()->check() ? url('/dashboard') : url('/login') }}" class="kt-btn kt-btn-primary gap-2">
            <i class="ki-filled ki-home-2"></i> {{ auth()->check() ? 'Dashboard' : 'Sign in' }}
        </a>
    </div>

    @hasSection('extra')
        <div class="mt-8">@yield('extra')</div>
    @endif

</div>

</body>
</html>
