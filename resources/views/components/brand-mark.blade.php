@props([
    'size' => 9,          // Tailwind size-* step
    'showName' => true,
    'nameClass' => 'text-lg font-semibold text-mono tracking-tight',
    'glow' => false,      // the pulsing halo used on the login screen
])

@php
    /**
     * The one place the brand mark is defined.
     *
     * Drop a file at public/img/kargah-logo.png (or .svg / .webp) and it appears
     * in the sidebar, the header, the login screen and the error pages at once.
     * Until then this falls back to the lettermark so nothing renders broken.
     */
    $candidates = ['img/kargah-logo.svg', 'img/kargah-logo.png', 'img/kargah-logo.webp'];

    $logo = null;
    foreach ($candidates as $candidate) {
        if (is_file(public_path($candidate))) {
            $logo = '/'.$candidate;
            break;
        }
    }
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5']) }}>
    @if ($logo)
        <img src="{{ $logo }}"
             alt="Kargah"
             class="size-{{ $size }} object-contain shrink-0 {{ $glow ? 'kargah-mark-glow' : '' }}"
             draggable="false">
    @else
        <span class="inline-flex items-center justify-center size-{{ $size }} rounded-xl bg-primary text-primary-foreground font-bold shrink-0 {{ $glow ? 'kargah-mark' : '' }}">K</span>
    @endif

    @if ($showName)
        <span class="{{ $nameClass }}">Kargah</span>
    @endif
</span>
