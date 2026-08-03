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
}
