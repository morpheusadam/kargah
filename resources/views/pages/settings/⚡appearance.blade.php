<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Platform\Support\ConnectionHealth;
use Modules\Project\Support\Palette;

/**
 * Two things you can change about how Kargah looks, and both of them are real.
 *
 * 🔴 **This page used to offer five controls and persist none of them.** Theme,
 * accent colour, density, "start with the sidebar collapsed" and "reduce
 * motion" were five public properties on a component with no `save()`, no
 * column behind any of them and no JavaScript listening: clicking one moved a
 * highlight and the next page load put it back. `docs/frontend-conventions.md`
 * forbids inventing fake persistence, and `project-guaid/DECISIONS.md` settled
 * the same argument for the sessions panel — a page that invents state is worse
 * than a page without the panel, because the invented one is believed.
 *
 * What survived is what is genuinely wired to something:
 *
 * - **Theme** is real, and it was already real: `layouts/app.blade.php` owns a
 *   `window.kargahTheme` with `current()`, `set()` and `toggle()`, backed by the
 *   `kargah.theme` key in `localStorage` and applied before first paint. This
 *   page calls that object rather than storing a preference of its own, so the
 *   header's toggle and this picker cannot disagree. It is per browser and not
 *   per account, which is a fact worth printing rather than hiding: the same
 *   login on a second computer keeps that computer's choice.
 * - **Colour-blind label patterns** is real and is per account —
 *   `users.colour_blind_mode`, read by `⚡boards.blade.php` and
 *   `⚡board-settings.blade.php` through `Palette::pattern()`. It was reachable
 *   only from inside one board's settings, which is an odd home for a global
 *   preference about every label on every board.
 *
 * The three that were removed are listed on the page as not adjustable, each
 * with an em dash for a value, because "there is no such setting" is a fact
 * somebody deserves to be told once instead of hunting for.
 */
new
#[Title('Appearance — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** The settings-nav search box. See `partials/settings-nav.blade.php`. */
    public string $settingsFilter = '';

    public bool $colourBlindMode = false;

    public function mount(): void
    {
        $this->colourBlindMode = (bool) auth()->user()?->colour_blind_mode;
    }

    public function with(): array
    {
        return [
            'themes' => [
                ['key' => 'light', 'label' => 'Light', 'icon' => 'ki-sun'],
                ['key' => 'dark', 'label' => 'Dark', 'icon' => 'ki-moon'],
            ],
            // Three swatches is enough to see a pattern working and few enough
            // to fit beside the switch. Red and green first on purpose: they
            // are the pair the whole feature exists for.
            //
            // The class strings are resolved here, not in the template, for two
            // reasons. `Palette` belongs to `Modules\Project`, and a settings
            // page that fatals because somebody switched a module off is a bad
            // trade for a preview — so `class_exists` decides whether there is
            // a preview at all. And resolving here keeps the template free of
            // any construction Tailwind's scanner cannot read; every class
            // string still exists whole inside `Palette`.
            'sampleLabels' => $this->sampleLabels(),
            // A value only the browser can know. The server cannot read
            // `localStorage`, so it renders an em dash and the script below
            // replaces it on mount — never `0`, never a guess at "dark"
            // because the layout happens to default there.
            'unknown' => ConnectionHealth::UNKNOWN,
            /*
             | Not adjustable, and said out loud.
             |
             | Each of these was a control on this page that wrote nowhere. They
             | stay listed, with an em dash where a value would be, so somebody
             | looking for "compact rows" finds the answer instead of assuming
             | they have missed a menu. Adding any of them back means a column
             | on `users` and something that reads it — the settings page is the
             | last part to build, not the first.
             */
            'notAdjustable' => [
                ['label' => 'Accent colour', 'why' => 'The accent is fixed by the theme stylesheet, which is prebuilt and shipped rather than generated per account.'],
                ['label' => 'Row density', 'why' => 'Table and card spacing come from the same prebuilt stylesheet, so there is nothing per-account to change.'],
                ['label' => 'Sidebar starts collapsed', 'why' => 'The sidebar remembers nothing between page loads; it opens the same way every time.'],
                ['label' => 'Reduce motion', 'why' => "Kargah follows your operating system's own reduce-motion setting and has no switch of its own."],
            ],
        ];
    }

    /**
     * The preview chips, already resolved to whole class strings.
     *
     * Empty when `Modules\Project` is switched off: without it there are no
     * boards, no labels and no `Palette`, so a preview of label chips would be
     * a picture of something this install does not have.
     *
     * @return list<array{name: string, chip: string, pattern: string}>
     */
    private function sampleLabels(): array
    {
        if (! class_exists(Palette::class)) {
            return [];
        }

        return array_map(fn (array $sample): array => [
            'name' => $sample['name'],
            'chip' => Palette::chip($sample['colour']),
            'pattern' => Palette::pattern($sample['colour']),
        ], [
            ['name' => 'Bug', 'colour' => 'red'],
            ['name' => 'Shipped', 'colour' => 'green'],
            ['name' => 'Waiting on client', 'colour' => 'yellow'],
        ]);
    }

    /**
     * Turn label patterns on or off for this account.
     *
     * `forceFill` rather than `update`, matching `⚡board-settings.blade.php`'s
     * `toggleColourBlindMode()` — the two write the same column and a person
     * flipping it in one place must see it flipped in the other, so they are
     * deliberately the same two lines rather than two implementations.
     *
     * This writes immediately instead of waiting for a Save button: the effect
     * is visible in the swatches beside it on the same round trip, so a save
     * step would only add a way to lose the change.
     */
    public function toggleColourBlindMode(): void
    {
        $user = auth()->user();

        if ($user === null) {
            $this->toastError('You are not signed in', 'Sign in again and retry.');

            return;
        }

        $user->forceFill(['colour_blind_mode' => ! $user->colour_blind_mode])->save();

        $this->colourBlindMode = (bool) $user->colour_blind_mode;

        $this->toastSuccess(
            $this->colourBlindMode ? 'Label patterns on' : 'Label patterns off',
            $this->colourBlindMode
                ? 'Every label chip on every board now carries a pattern as well as a colour.'
                : 'Label chips are back to colour alone.',
        );
    }
};

?>

<div class="flex flex-col gap-5">

    <div>
        <h1 class="text-xl font-semibold text-mono">Settings</h1>
        <p class="text-sm text-secondary-foreground mt-1">How Kargah behaves for you.</p>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        <div class="col-span-12 lg:col-span-3">
            @include('partials.settings-nav')
        </div>

        <div class="col-span-12 lg:col-span-9 flex flex-col gap-5">

            <div>
                <h2 class="text-lg font-semibold text-mono">Appearance</h2>
                <p class="text-sm text-secondary-foreground mt-1">
                    Whether Kargah draws itself dark or light, and whether label colours carry a pattern too.
                </p>
            </div>

            <div class="kt-card" id="theme">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Theme</h3>
                    <span class="text-xs text-muted-foreground">
                        Currently <strong class="text-mono" data-kargah-theme-current>{{ $unknown }}</strong>
                    </span>
                </div>
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <p class="text-sm text-secondary-foreground">
                        Switches the page background and text immediately — no save, no reload.
                        Stored in this browser rather than on your account, so signing in on another
                        computer leaves that computer's choice alone.
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-[520px]">
                        @foreach ($themes as $t)
                            <button type="button"
                                    data-kargah-theme="{{ $t['key'] }}"
                                    class="flex flex-col items-center gap-3 p-4 rounded-lg border border-border hover:border-primary/40 transition-colors">
                                <div class="w-full h-20 rounded-md border border-border overflow-hidden flex">
                                    <div class="w-1/3 {{ $t['key'] === 'dark' ? 'bg-neutral-800' : 'bg-muted' }}"></div>
                                    <div class="w-2/3 {{ $t['key'] === 'dark' ? 'bg-neutral-900' : 'bg-background' }}"></div>
                                </div>
                                <span class="flex items-center gap-2 text-sm font-medium text-mono">
                                    <i class="ki-filled {{ $t['icon'] }}"></i> {{ $t['label'] }}
                                </span>
                            </button>
                        @endforeach
                    </div>

                </div>
            </div>

            <div class="kt-card" id="colour-blind">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Reading label colours</h3>
                    @if ($colourBlindMode)
                        <span class="kt-badge kt-badge-sm kt-badge-success">On</span>
                    @endif
                </div>
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <label class="flex items-start justify-between gap-4">
                        <span>
                            <span class="block text-sm font-medium text-mono">Colour-blind label patterns</span>
                            <span class="block text-xs text-muted-foreground mt-1">
                                Puts a stripe or dot pattern on every label chip on cards and boards, as well as
                                its colour, so red and green stop depending on hue alone. Applies to every board
                                at once, not just the one you are looking at.
                            </span>
                        </span>
                        <span class="kt-switch shrink-0">
                            <input type="checkbox" wire:click="toggleColourBlindMode" @checked($colourBlindMode)
                                   wire:loading.attr="disabled" wire:target="toggleColourBlindMode">
                        </span>
                    </label>

                    <div class="rounded-lg bg-muted px-4 py-3 flex flex-wrap items-center gap-2">
                        @forelse ($sampleLabels as $sample)
                            @if ($loop->first)
                                <span class="text-xs text-muted-foreground me-1">Labels look like this:</span>
                            @endif
                            <span class="text-xs font-medium px-2 py-1 rounded {{ $sample['chip'] }} {{ $colourBlindMode ? $sample['pattern'] : '' }}">
                                {{ $sample['name'] }}
                            </span>
                        @empty
                            <span class="text-xs text-muted-foreground">
                                The Projects module is switched off on this install, so there are no label chips to preview.
                            </span>
                        @endforelse
                        <span wire:loading wire:target="toggleColourBlindMode" class="text-xs text-muted-foreground inline-flex items-center gap-1.5">
                            <i class="ki-filled ki-loading animate-spin text-xs"></i> Applying…
                        </span>
                    </div>

                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Not adjustable</h3>
                </div>
                <div class="kt-card-table">
                    <div class="kt-scrollable-x-auto">
                        <table class="kt-table align-middle text-sm">
                            <thead>
                                <tr>
                                    <th class="min-w-[200px]">Setting</th>
                                    <th class="w-[90px]">Value</th>
                                    <th class="min-w-[320px]">Why</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($notAdjustable as $row)
                                    <tr wire:key="not-adjustable-{{ $loop->index }}">
                                        <td class="text-mono">{{ $row['label'] }}</td>
                                        <td class="text-secondary-foreground">{{ $unknown }}</td>
                                        <td class="text-secondary-foreground">{{ $row['why'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>

    @script
    <script>
    (function () {
        // The theme lives in `localStorage` and is applied by the layout before
        // first paint, so the server cannot know which one is active and the
        // markup ships an em dash. This fills it in, and re-fills it after every
        // morph — Livewire's morph rewrites the button markup from the server's
        // copy, which does not carry the highlight, so a class added once would
        // vanish on the next round trip. `morphed` fires once per component;
        // `morph.updated` fires per node and is almost never what you want.
        function paint() {
            if (! window.kargahTheme || ! $wire.$el || ! $wire.$el.isConnected) return;

            var current = window.kargahTheme.current();

            $wire.$el.querySelectorAll('[data-kargah-theme]').forEach(function (button) {
                var active = button.getAttribute('data-kargah-theme') === current;

                button.classList.toggle('border-primary', active);
                button.classList.toggle('bg-primary/5', active);
                button.classList.toggle('border-border', ! active);
            });

            $wire.$el.querySelectorAll('[data-kargah-theme-current]').forEach(function (slot) {
                slot.textContent = current === 'dark' ? 'Dark' : 'Light';
            });
        }

        // One delegated listener on the root rather than a binding per button:
        // the morph replaces the buttons, and a listener bound to the old node
        // would go with it. Guarded by asking whether this element already has
        // the handler, never by a data-* flag — the morph strips those.
        if (! $wire.$el.__kargahThemePicker) {
            $wire.$el.__kargahThemePicker = true;

            $wire.$el.addEventListener('click', function (event) {
                var button = event.target.closest('[data-kargah-theme]');
                if (! button || ! window.kargahTheme) return;

                window.kargahTheme.set(button.getAttribute('data-kargah-theme'));
                paint();
            });
        }

        Livewire.hook('morphed', paint);
        paint();
    })();
    </script>
    @endscript
</div>
