<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * A closed `.kt-dropdown` must actually be closed.
 *
 * The theme hides one with a rule in the **components** layer:
 *
 *     .kt-dropdown:not(.open) { display: none }
 *
 * Tailwind's display utilities live in the **utilities** layer, and a cascade
 * layer beats specificity outright — `.flex` wins over `.kt-dropdown:not(.open)`
 * no matter how the selectors compare. So a panel written as
 *
 *     <div class="kt-dropdown … flex flex-col gap-3 {{ $open ? 'open' : '' }}">
 *
 * is `display: flex` whether or not it is open, forever, and there is nothing in
 * the page, the console or the test suite to say so. That shipped: every one of
 * the five popovers on the card back — start date, due date, cover, move and
 * mirror — was permanently visible, stacked on top of each other and on top of
 * the description, from the moment a card was opened. It looked like the
 * controls had been rendered twice, which is the wrong diagnosis and the reason
 * it survived a UI audit that read markup rather than measuring a browser.
 *
 * The fix at each site is to put the display utility **inside** the conditional,
 * so the class is present only when `open` is:
 *
 *     <div class="kt-dropdown … {{ $open ? 'open flex flex-col gap-3' : '' }}">
 *
 * written out whole, never concatenated — Tailwind's scanner reads source text.
 *
 * This test is a grep with a reason attached. It cannot run in a browser, so it
 * does the next best thing: it refuses the shape that causes the bug. If a panel
 * genuinely needs a display utility that is not conditional, the honest move is
 * to stop using `.kt-dropdown` for it and hide it with `@if` instead — see
 * docs/frontend-conventions.md, "pick one mechanism per panel, never both".
 */
class DropdownVisibilityTest extends TestCase
{
    /**
     * The utilities that override the theme's hide rule.
     *
     * Only the ones that set `display`. `items-center` and `gap-3` are harmless
     * on a hidden element, and listing them would make this test noisy about
     * markup that is perfectly fine.
     *
     * @var list<string>
     */
    private const DISPLAY_UTILITIES = [
        'flex', 'inline-flex', 'grid', 'inline-grid', 'block', 'inline-block', 'table', 'contents',
    ];

    public function test_no_dropdown_panel_carries_an_unconditional_display_utility(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $contents = file_get_contents($file);

            if ($contents === false || ! str_contains($contents, 'kt-dropdown')) {
                continue;
            }

            foreach (explode("\n", $contents) as $number => $line) {
                if (! preg_match('/class="([^"]*\bkt-dropdown\b[^"]*)"/', $line, $match)) {
                    continue;
                }

                // Everything a Blade expression would emit is decided at render
                // time and is exactly where a conditional `open flex` belongs.
                // Strip it, and judge only what is unconditionally in the class
                // attribute.
                $static = preg_replace('/\{\{.*?\}\}/s', ' ', $match[1]) ?? '';

                foreach (self::DISPLAY_UTILITIES as $utility) {
                    if (preg_match('/(^|\s)'.preg_quote($utility, '/').'(\s|$)/', $static)) {
                        $offenders[] = $this->relative($file).':'.($number + 1).'  →  '.$utility;
                    }
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'A .kt-dropdown panel carries a display utility outside its conditional, so it is visible whether or not it is open.',
            'Move the utility inside: {{ $open ? \'open flex flex-col gap-3\' : \'\' }}',
            '',
            ...$offenders,
        ]));
    }

    /**
     * Every Blade template in the application and in every module.
     *
     * @return list<string>
     */
    private function bladeFiles(): array
    {
        $files = [];

        foreach ([base_path('resources/views'), base_path('Modules')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            /** @var \SplFileInfo $file */
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
    }
}
