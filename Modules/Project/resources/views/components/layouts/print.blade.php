{{--
    The bare page the board print view renders into.

    The app's own layout — `layouts::app`, what every other page in this module
    gets — is a sidebar, a header, a theme script that pins the document dark,
    and the whole Metronic bundle. Every one of those is chrome on a sheet of
    paper, and the dark theme in particular is a page of ink. Rather than fight
    it with `@media print` overrides in the component, the print page opts out
    of the shell entirely and this is what it opts into: a white page, one
    typeface, and the component's own styles.

    Deliberately no `styles.css` and no `kargah.css`. Nothing here uses a
    Tailwind class, so there is nothing to load and nothing that breaks when the
    stylesheet is next rebuilt. `@livewireStyles` stays because the page is
    still a Livewire component.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Print — Kargah' }}</title>

    <style>
        html {
            background: #fff;
            color: #000;
        }

        body {
            margin: 0;
            background: #fff;
            color: #000;
            font-family: 'Inter', -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        @page {
            margin: 14mm;
        }
    </style>

    @livewireStyles
</head>

<body>
    {{ $slot }}
</body>

</html>
