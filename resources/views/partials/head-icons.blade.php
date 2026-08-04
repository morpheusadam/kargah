{{--
    Every icon the browser, the OS and link previews ask for.

    All of it is generated from public/img/kargah-logo.webp by
    tools/make-icons.py — change the mark, re-run that, change nothing here.

    The mtime query stops a browser serving yesterday's icon after a rebrand,
    which is otherwise one of the stickier caches there is.
--}}
@php
    // Through `asset()` so the URL carries the application's own root. Written
    // as '/'.$path until 5 August 2026, which made every icon a request to the
    // domain root: on an install under a subdirectory the browser asked the
    // site upstairs for five files it has never heard of and got five 404s.
    $v = static fn (string $path) => asset($path).'?v='.(@filemtime(public_path($path)) ?: 1);
@endphp

<link rel="icon" href="{{ $v('favicon.ico') }}" sizes="any">
<link rel="icon" type="image/png" sizes="16x16" href="{{ $v('img/icons/icon-16.png') }}">
<link rel="icon" type="image/png" sizes="32x32" href="{{ $v('img/icons/icon-32.png') }}">
<link rel="icon" type="image/png" sizes="192x192" href="{{ $v('img/icons/icon-192.png') }}">
<link rel="apple-touch-icon" sizes="180x180" href="{{ $v('img/icons/apple-touch-icon.png') }}">

<link rel="manifest" href="{{ $v('site.webmanifest') }}">
<meta name="theme-color" content="#0d111c">
<meta name="apple-mobile-web-app-title" content="Kargah">
<meta name="application-name" content="Kargah">

<meta property="og:type" content="website">
<meta property="og:site_name" content="Kargah">
<meta property="og:title" content="{{ $title ?? 'Kargah' }}">
<meta property="og:description" content="Self-hosted freelance workspace: inbox, boards, invoices and a vault.">
<meta property="og:image" content="{{ $v('img/og-image.png') }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="{{ $v('img/og-image.png') }}">
