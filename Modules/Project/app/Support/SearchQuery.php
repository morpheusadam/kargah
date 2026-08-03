<?php

namespace Modules\Project\Support;

/**
 * Trello's search language, parsed into a `ParsedSearch`.
 *
 * A pure function of its input: no models, no container, no clock, no database.
 * It turns a string into a structure and stops there — resolving `due:week`
 * against a timezone, and turning any of this into SQL, belongs to whatever
 * consumes the result.
 *
 * ## The language
 *
 *     member:nima  board:"Client Work"  list:"To Do"  label:red
 *     created:7  created:week  edited:month
 *     due:day  due:7  due:overdue  due:complete  due:incomplete
 *     has:attachments|cover|members|description|stickers
 *     name:widget  description:scope  checklist:deploy  comment:blocked
 *     is:open|archived|starred
 *     sort:due  sort:-edited
 *     -label:red  -widget                       negation
 *     widget "Northwind retainer"               free text
 *
 * ## Rules it is worth knowing before reading the code
 *
 * **Nothing is ever silently dropped.** A search box that ignores what someone
 * typed is worse than one that returns nothing. An unknown key (`colour:red`), a
 * known key with an unusable value (`created:tomorrow`, `is:cancelled`) and a
 * negated `sort:` all fall through to free text, spelled `key:value`, so the
 * user can see their typo in the results rather than wonder why the filter did
 * nothing. The two exceptions are documented below and are both mid-typing
 * states rather than mistakes.
 *
 * **Quotes are metacharacters wherever they appear, and there is no escape.**
 * `board:"Client Work"` is one value with a space in it. A `"` anywhere else
 * toggles quoting too, which means a literal double quote cannot be searched
 * for — the trade is that a value can never contain a `"`, so rendering a parsed
 * query back to a string is lossless and the round trip closes.
 *
 * **An unterminated quote runs to the end of the input** instead of throwing.
 * The box is typed into live, and half a quoted phrase is what every quoted
 * phrase looks like a moment before it is finished.
 *
 * **A leading `-` on a token negates it**; a `-` anywhere else is a hyphen, so
 * `re-scope` is one word and `sort:-edited` is descending.
 *
 * **Repeats of one key are OR'd, different keys are AND'd**, which is Trello's
 * behaviour and the reason the language is worth having. See `ParsedSearch`.
 * Repeated values within a key are deduplicated case-insensitively, first
 * spelling kept — `label:Red label:red` is one condition, not two.
 *
 * **Case**: keys and enum values are matched case-insensitively and stored
 * lowercase; free text and open-ended values such as `label:` and `board:` keep
 * exactly the case that was typed. Matching those is the compiler's business and
 * it can lowercase what it likes — the parser must not destroy the input, because
 * it is also what gets echoed back to the user.
 *
 * **The two things that are dropped**, both being a half-typed token rather than
 * an error: an operator with no value at all (`label:`, `colour:`), and an
 * unquoted token with no letter or digit in it (`-`, `:`, `--`). Empty and
 * whitespace-only input parse to an empty result. Nothing here throws, ever.
 */
final class SearchQuery
{
    /** Keys whose value is arbitrary text and is therefore kept verbatim. */
    public const TEXT_KEYS = [
        'member', 'board', 'list', 'label',
        'name', 'description', 'checklist', 'comment',
    ];

    /** `created:` and `edited:` — a window back from now, or N days. */
    public const PERIODS = ['day', 'week', 'month'];

    /**
     * `due:` — a window ahead, N days ahead, or a state.
     *
     * `complete` and `incomplete` are the card's completion mark, not its date.
     */
    public const DUE_VALUES = ['day', 'week', 'month', 'overdue', 'complete', 'incomplete'];

    public const HAS_VALUES = ['attachments', 'cover', 'members', 'description', 'stickers'];

    public const IS_VALUES = ['open', 'archived', 'starred'];

    /** `sort:` fields, each with an optional leading `-` for descending. */
    public const SORT_FIELDS = ['due', 'created', 'edited', 'name', 'position'];

    public static function parse(string $input): ParsedSearch
    {
        $terms = [];
        $excludedTerms = [];
        $filters = [];
        $excludedFilters = [];
        $sortField = null;
        $sortDescending = false;

        foreach (self::tokenise($input) as $token) {
            $negated = $token['negated'];
            $key = $token['key'];
            $value = $token['value'];
            $quoted = $token['quoted'];

            if ($key !== null) {
                if ($key === 'sort') {
                    $sort = $negated ? null : self::sortDirective($value);

                    if ($sort !== null) {
                        [$sortField, $sortDescending] = $sort;

                        continue;
                    }
                } else {
                    $normalised = self::normalise($key, $value);

                    if ($normalised !== null) {
                        if ($negated) {
                            self::push($excludedFilters, $key, $normalised);
                        } else {
                            self::push($filters, $key, $normalised);
                        }

                        continue;
                    }
                }

                // Not a usable operator, so it is what the user typed: free text.
                $value = $key.':'.$value;
            }

            if ($negated) {
                self::addTerm($excludedTerms, $value, $quoted);
            } else {
                self::addTerm($terms, $value, $quoted);
            }
        }

        // Keys in one fixed order, matching what `toString()` renders, so two
        // queries that mean the same thing are the same object however they
        // were typed — and so a parse survives its own rendering unchanged.
        ksort($filters, SORT_STRING);
        ksort($excludedFilters, SORT_STRING);

        return new ParsedSearch(
            terms: $terms,
            excludedTerms: $excludedTerms,
            filters: $filters,
            excludedFilters: $excludedFilters,
            sortField: $sortField,
            sortDescending: $sortDescending,
        );
    }

    /**
     * Split the input into tokens, resolving quoting and the leading `-`.
     *
     * Scanned a byte at a time, which is safe for UTF-8: every metacharacter
     * here is ASCII and no continuation byte can collide with one.
     *
     * @return list<array{negated: bool, key: ?string, value: string, quoted: bool}>
     */
    private static function tokenise(string $input): array
    {
        $tokens = [];
        $length = strlen($input);
        $i = 0;

        while ($i < $length) {
            while ($i < $length && ctype_space($input[$i])) {
                $i++;
            }

            if ($i >= $length) {
                break;
            }

            $negated = false;

            while ($i < $length && $input[$i] === '-') {
                $negated = true;
                $i++;
            }

            $key = null;
            $value = '';
            $quoted = false;
            $inQuote = false;
            $sawQuote = false;

            while ($i < $length) {
                $char = $input[$i];

                if ($char === '"') {
                    $inQuote = ! $inQuote;
                    $sawQuote = true;
                    $quoted = true;
                    $i++;

                    continue;
                }

                if (! $inQuote && ctype_space($char)) {
                    break;
                }

                // The first colon splits key from value — but only if what came
                // before it looks like a key and was not quoted. `"a:b"` is text,
                // and the second colon of `member:a:b` belongs to the value.
                if (! $inQuote && $char === ':' && $key === null && ! $sawQuote && self::isKeyShaped($value)) {
                    $key = strtolower($value);
                    $value = '';
                    $quoted = false;
                    $i++;

                    continue;
                }

                $value .= $char;
                $i++;
            }

            // `label:` with nothing after it is a search being typed, not a
            // search. Same for `colour:`. Dropped rather than shown back.
            if ($key !== null && $value === '') {
                continue;
            }

            $tokens[] = [
                'negated' => $negated,
                'key' => $key,
                'value' => $value,
                'quoted' => $quoted,
            ];
        }

        return $tokens;
    }

    private static function isKeyShaped(string $candidate): bool
    {
        return preg_match('/^[a-zA-Z][a-zA-Z_]*$/', $candidate) === 1;
    }

    /**
     * The stored form of an operator's value, or null when this key cannot use
     * this value and the whole token should become free text instead.
     */
    private static function normalise(string $key, string $value): ?string
    {
        if (in_array($key, self::TEXT_KEYS, true)) {
            return $value;
        }

        $lower = strtolower($value);

        if ($key === 'created' || $key === 'edited' || $key === 'due') {
            $allowed = $key === 'due' ? self::DUE_VALUES : self::PERIODS;

            if (in_array($lower, $allowed, true)) {
                return $lower;
            }

            // A bare integer is a count of days — back for created/edited,
            // ahead for due. Zero is not a search, so it falls through.
            return preg_match('/^[1-9]\d*$/', $value) === 1 ? $value : null;
        }

        if ($key === 'has') {
            return in_array($lower, self::HAS_VALUES, true) ? $lower : null;
        }

        if ($key === 'is') {
            return in_array($lower, self::IS_VALUES, true) ? $lower : null;
        }

        return null;
    }

    /**
     * @return array{0: string, 1: bool}|null field and descending
     */
    private static function sortDirective(string $value): ?array
    {
        $descending = str_starts_with($value, '-');
        $field = strtolower($descending ? substr($value, 1) : $value);

        return in_array($field, self::SORT_FIELDS, true) ? [$field, $descending] : null;
    }

    /**
     * @param  array<string, list<string>>  $bucket
     */
    private static function push(array &$bucket, string $key, string $value): void
    {
        $bucket[$key] ??= [];

        foreach ($bucket[$key] as $existing) {
            if (mb_strtolower($existing) === mb_strtolower($value)) {
                return;
            }
        }

        $bucket[$key][] = $value;
    }

    /**
     * @param  list<string>  $terms
     */
    private static function addTerm(array &$terms, string $value, bool $quoted): void
    {
        if ($value === '') {
            return;
        }

        // An unquoted token with nothing but punctuation in it — `-`, `:`, `--`
        // — is a search half typed, not a search for a hyphen.
        if (! $quoted && ! preg_match('/[a-zA-Z0-9]/', $value) && ! preg_match('/[\p{L}\p{N}]/u', $value)) {
            return;
        }

        foreach ($terms as $existing) {
            if (mb_strtolower($existing) === mb_strtolower($value)) {
                return;
            }
        }

        $terms[] = $value;
    }
}
