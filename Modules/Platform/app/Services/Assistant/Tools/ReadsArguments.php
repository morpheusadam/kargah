<?php

namespace Modules\Platform\Services\Assistant\Tools;

/**
 * Reading an argument a model sent, without believing it.
 *
 * A JSON Schema is a request, not a guarantee. Every provider will at some
 * point send `"5"` where the schema said `integer`, send `"true"` for a
 * boolean, omit a key it declared required, or send `null` for a string — and
 * a tool that does `(int) $arguments['limit']` on a missing key emits a PHP
 * warning that becomes an `ErrorException` under this application's error
 * handler. So arguments are read through here, where every one of those is a
 * default rather than a failure.
 *
 * `int()` clamps rather than rejects. A model asking for 5,000 cards is not
 * making an error worth an error message — it is asking for "all of them", and
 * the bounded answer is both what it wanted and what keeps one tool call from
 * becoming the whole context window.
 */
trait ReadsArguments
{
    /** @param  array<string, mixed>  $arguments */
    protected function stringArgument(array $arguments, string $key, string $default = ''): string
    {
        $value = $arguments[$key] ?? null;

        if (is_string($value)) {
            return trim($value);
        }

        return is_int($value) || is_float($value) ? (string) $value : $default;
    }

    /**
     * An integer, clamped into `[$min, $max]`. Null when absent and no default
     * is given, which is how a required id tells its tool to answer "which
     * one?" rather than to look up id 0.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function intArgument(array $arguments, string $key, ?int $default = null, int $min = 0, int $max = PHP_INT_MAX): ?int
    {
        $value = $arguments[$key] ?? null;

        if (is_bool($value) || $value === null || (is_string($value) && ! is_numeric(trim($value)))) {
            return $default;
        }

        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return $default;
        }

        return max($min, min($max, (int) $value));
    }

    /** @param  array<string, mixed>  $arguments */
    protected function boolArgument(array $arguments, string $key, bool $default = false): bool
    {
        $value = $arguments[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['true', '1', 'yes', 'on'], true);
        }

        return is_int($value) ? $value !== 0 : $default;
    }

    /**
     * A boolean that is allowed to be absent — the difference between "only
     * billable expenses" and "every expense", which `ExpenseReader::paginate()`
     * spells as `?bool $billable`.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function nullableBoolArgument(array $arguments, string $key): ?bool
    {
        return array_key_exists($key, $arguments) && $arguments[$key] !== null
            ? $this->boolArgument($arguments, $key)
            : null;
    }

    /** The empty-properties schema every provider accepts, for a tool that takes no arguments. */
    protected function noParameters(): array
    {
        return ['type' => 'object', 'properties' => (object) [], 'required' => []];
    }
}
