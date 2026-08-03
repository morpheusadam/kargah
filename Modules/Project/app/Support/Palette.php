<?php

namespace Modules\Project\Support;

/**
 * Colour keys to whole Tailwind class strings.
 *
 * A board's colour and a label's colour are stored as a key — 'success' — and
 * resolved here. Never `"bg-{$colour}"`: Tailwind's scanner reads source files
 * as text and cannot see a class name that only exists once PHP has run, so a
 * concatenated class is simply absent from the stylesheet.
 *
 * Every string below appears here in full, which is what makes it findable.
 */
final class Palette
{
    /** @var array<string, array{name: string, chip: string, dot: string, tone: string}> */
    private const COLOURS = [
        'primary' => [
            'name' => 'Blue',
            'chip' => 'bg-primary/15 text-primary',
            'dot' => 'bg-primary',
            'tone' => 'bg-primary/15 text-primary',
        ],
        'success' => [
            'name' => 'Green',
            'chip' => 'bg-success/15 text-success',
            'dot' => 'bg-success',
            'tone' => 'bg-success/15 text-success',
        ],
        'info' => [
            'name' => 'Sky',
            'chip' => 'bg-info/15 text-info',
            'dot' => 'bg-info',
            'tone' => 'bg-info/15 text-info',
        ],
        'warning' => [
            'name' => 'Amber',
            'chip' => 'bg-warning/15 text-warning',
            'dot' => 'bg-warning',
            'tone' => 'bg-warning/15 text-warning',
        ],
        'destructive' => [
            'name' => 'Red',
            'chip' => 'bg-destructive/15 text-destructive',
            'dot' => 'bg-destructive',
            'tone' => 'bg-destructive/15 text-destructive',
        ],
        'neutral' => [
            'name' => 'Grey',
            'chip' => 'bg-accent/60 text-secondary-foreground',
            'dot' => 'bg-muted-foreground',
            'tone' => 'bg-accent/60 text-secondary-foreground',
        ],

        // Added for the due-date badge scale: Trello's five states are grey,
        // yellow, red, pink and green, and the first four already had a
        // colour here. Pink stayed the one genuinely new value until the
        // label palette below arrived and started using it too — which is
        // Trello's own behaviour, not a collision: the overdue badge and the
        // "Pink" label really do share one colour there as well.
        'pink' => [
            'name' => 'Pink',
            'chip' => 'bg-pink-500/15 text-pink-600',
            'dot' => 'bg-pink-500',
            'tone' => 'bg-pink-500/15 text-pink-600',
        ],

        // Trello's own ten label colours, added 5 August 2026 alongside the
        // semantic keys above rather than replacing them. A label's colour is
        // a swatch somebody picked; 'destructive' is a system meaning ("this
        // is a failure state") that a board's colour and a due-date badge
        // still read — conflating the two is exactly what made a label
        // called "Bug" secretly mean "danger" everywhere else `destructive`
        // appears. Boards, lists and due-date badges are untouched and keep
        // using the keys above.
        //
        // These ten keys are also what `⚡card-detail.blade.php`'s cover
        // picker and the board-canvas hand-off below are told to reuse, so a
        // label, a card cover and a board's solid background all draw from
        // the same ten swatches — see the final report for the exact list.
        'green' => [
            'name' => 'Green',
            'chip' => 'bg-green-500/15 text-green-600',
            'dot' => 'bg-green-500',
            'tone' => 'bg-green-500/15 text-green-600',
        ],
        'yellow' => [
            'name' => 'Yellow',
            'chip' => 'bg-yellow-500/15 text-yellow-700',
            'dot' => 'bg-yellow-500',
            'tone' => 'bg-yellow-500/15 text-yellow-700',
        ],
        'orange' => [
            'name' => 'Orange',
            'chip' => 'bg-orange-500/15 text-orange-600',
            'dot' => 'bg-orange-500',
            'tone' => 'bg-orange-500/15 text-orange-600',
        ],
        'red' => [
            'name' => 'Red',
            'chip' => 'bg-red-500/15 text-red-600',
            'dot' => 'bg-red-500',
            'tone' => 'bg-red-500/15 text-red-600',
        ],
        'purple' => [
            'name' => 'Purple',
            'chip' => 'bg-purple-500/15 text-purple-600',
            'dot' => 'bg-purple-500',
            'tone' => 'bg-purple-500/15 text-purple-600',
        ],
        'blue' => [
            'name' => 'Blue',
            'chip' => 'bg-blue-500/15 text-blue-600',
            'dot' => 'bg-blue-500',
            'tone' => 'bg-blue-500/15 text-blue-600',
        ],
        'sky' => [
            'name' => 'Sky',
            'chip' => 'bg-sky-500/15 text-sky-600',
            'dot' => 'bg-sky-500',
            'tone' => 'bg-sky-500/15 text-sky-600',
        ],
        'lime' => [
            'name' => 'Lime',
            'chip' => 'bg-lime-500/15 text-lime-700',
            'dot' => 'bg-lime-500',
            'tone' => 'bg-lime-500/15 text-lime-700',
        ],
        'black' => [
            // Trello's "Black" label is a dark slate, not literal black — pure
            // black text on a pale chip reads as an error state here, and a
            // solid black dot disappears against the dark theme's own near-black
            // surfaces. Slate reads as the intended "no colour, but still a
            // colour" swatch in both themes.
            'name' => 'Black',
            'chip' => 'bg-slate-500/15 text-slate-700',
            'dot' => 'bg-slate-700',
            'tone' => 'bg-slate-500/15 text-slate-700',
        ],
    ];

    /**
     * Trello's own label order — the order its colour picker shows them in,
     * and the order `⚡board-settings.blade.php`'s label swatches use. `keys()`
     * below is unordered/associative and includes the semantic keys too, which
     * is the wrong list to build a ten-swatch picker from.
     *
     * @var list<string>
     */
    private const LABEL_COLOUR_ORDER = [
        'green', 'yellow', 'orange', 'red', 'purple', 'blue', 'sky', 'lime', 'pink', 'black',
    ];

    /**
     * Colour-blind mode: a repeating-gradient overlay per label colour, laid
     * over the chip's own `background-color` (a distinct CSS property, so the
     * two classes stack rather than fight). Trello's own answer to "one in
     * twelve men cannot reliably tell red from green" is a pattern rather than
     * a second hue, so the shape — not the colour — is what a colour-blind
     * reader keys off. Meant for `chipClass()` sized surfaces (a label chip on
     * a card), not the small dot swatches in a picker, which are too small for
     * a stripe to read as anything but noise.
     *
     * @var array<string, string>
     */
    private const LABEL_PATTERNS = [
        'green' => 'bg-[image:repeating-linear-gradient(45deg,rgba(0,0,0,0.35)_0px,rgba(0,0,0,0.35)_2px,transparent_2px,transparent_6px)]',
        'yellow' => 'bg-[image:repeating-linear-gradient(-45deg,rgba(0,0,0,0.35)_0px,rgba(0,0,0,0.35)_2px,transparent_2px,transparent_6px)]',
        'orange' => 'bg-[image:repeating-radial-gradient(circle,rgba(0,0,0,0.35)_0px,rgba(0,0,0,0.35)_1.5px,transparent_1.5px,transparent_6px)]',
        'red' => 'bg-[image:repeating-linear-gradient(45deg,rgba(0,0,0,0.3)_0px,rgba(0,0,0,0.3)_1px,transparent_1px,transparent_6px),repeating-linear-gradient(-45deg,rgba(0,0,0,0.3)_0px,rgba(0,0,0,0.3)_1px,transparent_1px,transparent_6px)]',
        'purple' => 'bg-[image:repeating-linear-gradient(90deg,rgba(0,0,0,0.35)_0px,rgba(0,0,0,0.35)_2px,transparent_2px,transparent_7px)]',
        'blue' => 'bg-[image:repeating-linear-gradient(0deg,rgba(0,0,0,0.35)_0px,rgba(0,0,0,0.35)_2px,transparent_2px,transparent_7px)]',
        'sky' => 'bg-[image:repeating-radial-gradient(circle,rgba(0,0,0,0.35)_0px,rgba(0,0,0,0.35)_1px,transparent_1px,transparent_4px)]',
        'lime' => 'bg-[image:repeating-linear-gradient(45deg,rgba(0,0,0,0.3)_0px,rgba(0,0,0,0.3)_3px,transparent_3px,transparent_10px)]',
        'pink' => 'bg-[image:repeating-linear-gradient(60deg,rgba(0,0,0,0.35)_0px,rgba(0,0,0,0.35)_2px,transparent_2px,transparent_5px)]',
        'black' => 'bg-[image:repeating-linear-gradient(45deg,rgba(255,255,255,0.5)_0px,rgba(255,255,255,0.5)_1px,transparent_1px,transparent_4px)]',
    ];

    /**
     * Sensible default text tone when a label colour is used full-bleed, as a
     * board's solid background rather than a small chip — a translucent chip
     * reads fine against either tone, a full board canvas does not.
     *
     * @var array<string, 'light'|'dark'>
     */
    private const BACKGROUND_TEXT_DEFAULTS = [
        'green' => 'light',
        'yellow' => 'dark',
        'orange' => 'dark',
        'red' => 'light',
        'purple' => 'light',
        'blue' => 'light',
        'sky' => 'dark',
        'lime' => 'dark',
        'pink' => 'light',
        'black' => 'light',
    ];

    /**
     * Board backgrounds, part two: Trello's most-picked backgrounds are
     * gradients, and they cost nothing but CSS. Six fixed options — Trello
     * itself does not let a free workspace invent one either — each a whole
     * `bg-gradient-to-*` class plus the text tone that reads on it.
     *
     * @var array<string, array{name: string, class: string, text_tone: 'light'|'dark'}>
     */
    private const GRADIENTS = [
        'sunset' => [
            'name' => 'Sunset',
            'class' => 'bg-gradient-to-br from-orange-400 via-red-500 to-purple-700',
            'text_tone' => 'light',
        ],
        'ocean' => [
            'name' => 'Ocean',
            'class' => 'bg-gradient-to-br from-sky-400 via-blue-600 to-indigo-800',
            'text_tone' => 'light',
        ],
        'meadow' => [
            'name' => 'Meadow',
            'class' => 'bg-gradient-to-br from-lime-400 via-green-500 to-emerald-700',
            'text_tone' => 'light',
        ],
        'berry' => [
            'name' => 'Berry',
            'class' => 'bg-gradient-to-br from-pink-400 via-fuchsia-500 to-purple-700',
            'text_tone' => 'light',
        ],
        'dusk' => [
            'name' => 'Dusk',
            'class' => 'bg-gradient-to-br from-slate-500 via-slate-700 to-slate-900',
            'text_tone' => 'light',
        ],
        'citrus' => [
            'name' => 'Citrus',
            'class' => 'bg-gradient-to-br from-yellow-300 via-amber-400 to-orange-500',
            'text_tone' => 'dark',
        ],
    ];

    /** @var array<'light'|'dark', string> */
    private const TEXT_TONES = [
        'light' => 'text-white',
        'dark' => 'text-mono',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::COLOURS);
    }

    /** @return array<string, array{name: string, chip: string, dot: string, tone: string}> */
    public static function all(): array
    {
        return self::COLOURS;
    }

    public static function chip(string $colour): string
    {
        return self::COLOURS[$colour]['chip'] ?? self::COLOURS['neutral']['chip'];
    }

    public static function dot(string $colour): string
    {
        return self::COLOURS[$colour]['dot'] ?? self::COLOURS['neutral']['dot'];
    }

    public static function tone(string $colour): string
    {
        return self::COLOURS[$colour]['tone'] ?? self::COLOURS['neutral']['tone'];
    }

    public static function name(string $colour): string
    {
        return self::COLOURS[$colour]['name'] ?? self::COLOURS['neutral']['name'];
    }

    public static function has(string $colour): bool
    {
        return isset(self::COLOURS[$colour]);
    }

    /* Label colours — Trello's ten, in Trello's order ----------------------- */

    /**
     * Trello's ten label colours, in the order its own picker shows them.
     * What `⚡board-settings.blade.php`'s label swatches iterate — not
     * `all()`, which also carries the semantic keys boards and lists use.
     *
     * @return list<string>
     */
    public static function labelColours(): array
    {
        return self::LABEL_COLOUR_ORDER;
    }

    public static function isLabelColour(string $colour): bool
    {
        return in_array($colour, self::LABEL_COLOUR_ORDER, true);
    }

    /**
     * The colour-blind pattern overlay for a label colour, or an empty string
     * for anything outside the ten — a semantic key has no pattern defined,
     * and an empty string composes safely into a class list either way.
     */
    public static function pattern(string $colour): string
    {
        return self::LABEL_PATTERNS[$colour] ?? '';
    }

    /** The text tone that reads best when this colour is a full board background. */
    public static function defaultTextToneForColour(string $colour): string
    {
        return self::BACKGROUND_TEXT_DEFAULTS[$colour] ?? 'light';
    }

    /* Board backgrounds — gradients ------------------------------------------ */

    /** @return array<string, array{name: string, class: string, text_tone: 'light'|'dark'}> */
    public static function gradients(): array
    {
        return self::GRADIENTS;
    }

    public static function hasGradient(string $key): bool
    {
        return isset(self::GRADIENTS[$key]);
    }

    public static function gradientClass(string $key): string
    {
        return self::GRADIENTS[$key]['class'] ?? self::GRADIENTS['ocean']['class'];
    }

    public static function gradientName(string $key): string
    {
        return self::GRADIENTS[$key]['name'] ?? self::GRADIENTS['ocean']['name'];
    }

    public static function gradientTextTone(string $key): string
    {
        return self::GRADIENTS[$key]['text_tone'] ?? 'light';
    }

    /* Text tone — the light/dark toggle a background stores ----------------- */

    /** 'light' or 'dark' to the whole class string cards need to stay readable. */
    public static function textTone(string $tone): string
    {
        return self::TEXT_TONES[$tone] ?? self::TEXT_TONES['light'];
    }
}
