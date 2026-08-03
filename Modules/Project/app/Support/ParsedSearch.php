<?php

namespace Modules\Project\Support;

/**
 * The result of parsing a Trello-style search string. Immutable, and inert.
 *
 * This object knows nothing about SQL, Eloquent, the container or the clock. It
 * is the whole contract between `SearchQuery::parse()` and whatever compiles a
 * query out of it, so everything the compiler needs is a property or a reader
 * here, and nothing here needs anything to be booted.
 *
 * ## Shape
 *
 * `label:red label:blue member:nima -is:archived widget -draft "Client Work" sort:-edited`
 * parses to:
 *
 * ```php
 * terms           = ['widget', 'Client Work'];   // AND of substring matches, in typed order
 * excludedTerms   = ['draft'];                   // AND NOT
 * filters         = ['label' => ['red', 'blue'], 'member' => ['nima']];
 * excludedFilters = ['is' => ['archived']];
 * sortField       = 'edited';
 * sortDescending  = true;
 * ```
 *
 * The nesting is `array<string, list<string>>` — operator key to values —
 * precisely so the rule that makes this language useful falls out of the shape
 * rather than out of the compiler: **the values under one key are OR'd, and the
 * keys are AND'd together.** `label:red label:blue` is red *or* blue;
 * `label:red member:nima` is red *and* nima. `excludedFilters` is the same shape
 * and is always AND NOT — every excluded value must fail to match, so
 * `-label:red -label:blue` excludes both.
 *
 * A key never appears with an empty list; absent means unconstrained. Both maps
 * arrive with their keys in alphabetical order — the order `toString()` renders
 * — so two queries that mean the same thing compare equal whatever order they
 * were typed in. Values keep the order they were typed in, which is the order an
 * OR is built in and the order the user will recognise.
 *
 * ## Sort is two properties, not a value object
 *
 * `sortField` plus `sortDescending`, because the only consumer writes one
 * `orderBy($field, $direction)` and a `?SearchSort` would have to be null-checked
 * before either half could be read — the same check as `$sortField !== null`,
 * one class further away. `sortDescending` is false whenever `sortField` is null.
 * `sort:` is a directive, so it never appears in `filters`, and `values('sort')`
 * is always empty.
 *
 * ## Dates are recorded, never resolved
 *
 * `created:week` is stored as the string `'week'` and `created:7` as `'7'`. This
 * object holds no `Carbon`, calls no `now()`, and takes no clock. What "week"
 * means depends on the request's timezone and on whether the boundary is a
 * rolling seven days or the start of the calendar week — both are the compiler's
 * decision, made at query time against a clock it can be handed in a test. A
 * parser that resolved them would bake the wrong timezone into a saved filter.
 *
 * @phpstan-type FilterMap array<string, list<string>>
 */
final class ParsedSearch
{
    /**
     * @param  list<string>  $terms  positive free text, in the order it was typed
     * @param  list<string>  $excludedTerms  free text under a leading `-`
     * @param  array<string, list<string>>  $filters  operator key => OR'd values
     * @param  array<string, list<string>>  $excludedFilters  operator key => AND-NOT values
     * @param  ?string  $sortField  one of due, created, edited, name, position
     */
    public function __construct(
        public readonly array $terms = [],
        public readonly array $excludedTerms = [],
        public readonly array $filters = [],
        public readonly array $excludedFilters = [],
        public readonly ?string $sortField = null,
        public readonly bool $sortDescending = false,
    ) {}

    /** True when the query constrains this operator in either direction. */
    public function has(string $key): bool
    {
        $key = strtolower($key);

        return ($this->filters[$key] ?? []) !== [] || ($this->excludedFilters[$key] ?? []) !== [];
    }

    /**
     * The values to OR together for one operator. Empty when unconstrained.
     *
     * @return list<string>
     */
    public function values(string $key): array
    {
        return $this->filters[strtolower($key)] ?? [];
    }

    /**
     * The values to exclude for one operator, AND-NOT. Empty when unconstrained.
     *
     * @return list<string>
     */
    public function excluded(string $key): array
    {
        return $this->excludedFilters[strtolower($key)] ?? [];
    }

    /**
     * Nothing to filter *and* nothing to sort.
     *
     * A sort-only query such as `sort:due` is deliberately not empty: a caller
     * that reads this as "show everything unchanged" would drop the ordering the
     * user asked for.
     */
    public function isEmpty(): bool
    {
        return $this->terms === []
            && $this->excludedTerms === []
            && $this->filters === []
            && $this->excludedFilters === []
            && $this->sortField === null;
    }

    /**
     * The query rendered back to a canonical search string.
     *
     * Not decoration: this is what the UI echoes back as "you are searching for
     * …" and what a saved filter stores, so it has to survive a round trip.
     * `parse(parse($s)->toString())->toString() === parse($s)->toString()` holds
     * for every input, which is what `SearchQueryTest` asserts.
     *
     * Free text keeps its typed order because that is what the user sees.
     * Operators are emitted with their keys in alphabetical order and `sort:`
     * last, so two queries that mean the same thing render identically however
     * they were typed — otherwise two saved filters could differ as strings and
     * not as searches.
     */
    public function toString(): string
    {
        $parts = [];

        foreach ($this->terms as $term) {
            $parts[] = self::render($term);
        }

        foreach ($this->excludedTerms as $term) {
            $parts[] = '-'.self::render($term);
        }

        $keys = array_keys($this->filters + $this->excludedFilters);
        sort($keys);

        foreach ($keys as $key) {
            foreach ($this->filters[$key] ?? [] as $value) {
                $parts[] = $key.':'.self::render($value);
            }

            foreach ($this->excludedFilters[$key] ?? [] as $value) {
                $parts[] = '-'.$key.':'.self::render($value);
            }
        }

        if ($this->sortField !== null) {
            $parts[] = 'sort:'.($this->sortDescending ? '-' : '').$this->sortField;
        }

        return implode(' ', $parts);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Quote a value when leaving it bare would parse back as something else.
     *
     * Whitespace would split it into two tokens, a `:` would make it look like
     * an operator, and a leading `-` would negate it. A `"` cannot come out of
     * the parser — quotes are metacharacters with no escape — so one injected by
     * a hand-built instance is dropped rather than allowed to break the round
     * trip.
     */
    private static function render(string $value): string
    {
        $value = str_replace('"', '', $value);

        if ($value === '' || str_starts_with($value, '-') || preg_match('/[\s:]/', $value) === 1) {
            return '"'.$value.'"';
        }

        return $value;
    }
}
