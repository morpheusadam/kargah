<?php

namespace Modules\Project\Support;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * `@name` in a comment or a description: who it means, and how it is drawn.
 *
 * **The chip never goes through the markdown parser as HTML.** `Markdown` is
 * configured with `html_input => strip`, so writing `<span>` into the source
 * before conversion would simply delete it. Nor is the chip glued onto the
 * rendered HTML with a regex — a second pass over finished HTML cannot tell an
 * `@` in a paragraph from an `@` inside an `href`, and `mailto:` links are full
 * of them.
 *
 * So the order is: swap each resolved mention for a placeholder that markdown
 * has no opinion about, convert the *whole* text through `Markdown` exactly as
 * before, then swap the placeholders for chip markup this class built itself
 * out of `e()`-escaped values. Every byte the user typed is still sanitised by
 * the one sanitiser; the only unescaped HTML is the handful of tags written
 * here, from a name that was escaped on the way in.
 *
 * **An unmatched `@word` is left alone.** `@lunch` is not an error, it is the
 * word lunch after an at sign, and it renders as plain text.
 *
 * **Handles are matched loosely, on purpose.** There is no username column, so
 * a person is addressed by their name with the punctuation taken out:
 * "Nima Fazlipour" answers to `@nima`, `@nimafazlipour`, `@Nima.Fazlipour` and
 * to the local part of their email. Being generous here costs nothing — the
 * people list is a handful of rows on a self-hosted install — and being strict
 * would mean a mention that silently notifies nobody.
 */
final class Mentions
{
    /**
     * What counts as a handle after the `@`. Dots, hyphens and underscores are
     * inside it so `@nima.fazlipour` is one mention rather than a mention of
     * `nima` followed by a full stop.
     */
    private const PATTERN = '/(?<![\w@])@([A-Za-z0-9][A-Za-z0-9._-]{0,63})/u';

    /** Stands in for a mention while markdown runs. Not markdown-significant. */
    private const PLACEHOLDER = "\u{FFFC}";

    /**
     * Every person actually named in `$text`, deduplicated, in the order they
     * were first mentioned. An `@word` matching nobody is not in here.
     *
     * @return Collection<int, User>
     */
    public static function resolve(?string $text, ?Collection $people = null): Collection
    {
        $handles = self::handles($text);

        if ($handles === []) {
            return collect();
        }

        $people ??= self::people();

        return collect($handles)
            ->map(fn (string $handle): ?User => self::match($handle, $people))
            ->filter()
            ->unique('id')
            ->values();
    }

    /**
     * The ids of everyone named in `$text`, less `$excludeUserId`.
     *
     * Mentioning yourself is not news to yourself — the same rule
     * `Watching::notifyMemberAdded()` applies to adding yourself to a card.
     *
     * @return list<int>
     */
    public static function recipients(?string $text, ?int $excludeUserId = null, ?Collection $people = null): array
    {
        return self::resolve($text, $people)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->reject(fn (int $id): bool => $id === $excludeUserId)
            ->values()
            ->all();
    }

    /**
     * Markdown to HTML, with every resolved mention drawn as a chip.
     *
     * Safe to echo unescaped, for the same reason `Markdown::toHtml()` is: the
     * user's text is converted by the same converter with the same settings,
     * and the chips are assembled here from escaped values.
     */
    public static function toHtml(?string $source, ?Collection $people = null): string
    {
        $source = (string) $source;

        if (trim($source) === '') {
            return '';
        }

        // A literal placeholder character typed by a person would confuse the
        // swap below. Nothing is lost by removing it: U+FFFC is an invisible
        // stand-in for an embedded object and has no business in a comment.
        $source = str_replace(self::PLACEHOLDER, '', $source);

        $people ??= self::people();

        /** @var list<string> $chips */
        $chips = [];

        $marked = preg_replace_callback(self::PATTERN, function (array $m) use ($people, &$chips): string {
            $user = self::match($m[1], $people);

            if ($user === null) {
                return $m[0];
            }

            $chips[] = self::chip($user);

            return self::PLACEHOLDER.(count($chips) - 1).self::PLACEHOLDER;
        }, $source);

        $html = Markdown::toHtml($marked ?? $source);

        if ($chips === []) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/\x{FFFC}(\d+)\x{FFFC}/u',
            fn (array $m): string => $chips[(int) $m[1]] ?? '',
            $html,
        );
    }

    /**
     * The people an autocomplete should offer for a partial handle.
     *
     * Server-driven and unpaginated: the whole table is a handful of rows on a
     * self-hosted install, and an endpoint plus a debounce would be more moving
     * parts than the feature is worth.
     *
     * @return Collection<int, User>
     */
    public static function suggest(string $partial, int $limit = 6): Collection
    {
        $needle = self::normalise($partial);

        return self::people()
            ->filter(fn (User $user): bool => $needle === '' || str_contains(self::normalise((string) $user->name), $needle)
                || str_contains(self::normalise(self::localPart((string) $user->email)), $needle))
            ->take($limit)
            ->values();
    }

    /** How a person is written into a comment: their name, punctuation removed. */
    public static function handleFor(User $user): string
    {
        $handle = self::normalise((string) $user->name);

        return $handle !== '' ? $handle : self::normalise(self::localPart((string) $user->email));
    }

    /**
     * Every `@handle` in the text, in order, whether or not anybody answers to
     * it. Public because a caller that only wants to know "does this mention
     * anyone" should not have to run the resolver twice.
     *
     * @return list<string>
     */
    public static function handles(?string $text): array
    {
        $text = (string) $text;

        if ($text === '' || ! str_contains($text, '@')) {
            return [];
        }

        preg_match_all(self::PATTERN, $text, $matches);

        return array_values($matches[1] ?? []);
    }

    /* Internals ------------------------------------------------------------- */

    /** @param Collection<int, User> $people */
    private static function match(string $handle, Collection $people): ?User
    {
        $needle = self::normalise($handle);

        if ($needle === '') {
            return null;
        }

        // Exact first — "Sam" and "Sammy" both answer to a prefix, and the
        // person whose whole name was typed should win.
        return $people->first(fn (User $u): bool => self::normalise((string) $u->name) === $needle
                || self::normalise(self::localPart((string) $u->email)) === $needle)
            ?? $people->first(fn (User $u): bool => str_starts_with(self::normalise((string) $u->name), $needle));
    }

    private static function chip(User $user): string
    {
        return '<span class="inline-flex items-center rounded px-1 py-0.5 text-xs font-medium bg-primary/10 text-primary" '
            .'title="'.e((string) $user->name).'">@'.e((string) $user->name).'</span>';
    }

    /** Case, spaces and punctuation all removed — `Nima Fazlipour` → `nimafazlipour`. */
    private static function normalise(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($value)));
    }

    private static function localPart(string $email): string
    {
        $at = strpos($email, '@');

        return $at === false ? $email : substr($email, 0, $at);
    }

    /**
     * Everyone who can be mentioned.
     *
     * Not memoised in a static: a card with twenty comments would read the same
     * handful of rows twenty times, which is why every public method here takes
     * an optional `$people` — the drawer already has the list loaded and passes
     * it in. A static memo would be faster and wrong the moment a person is
     * added inside the same process, which is exactly what a test does.
     *
     * @return Collection<int, User>
     */
    private static function people(): Collection
    {
        return User::query()->orderBy('name')->get(['id', 'name', 'email']);
    }
}
