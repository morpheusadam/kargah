<?php

namespace Modules\Project\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Fractional ordering.
 *
 * A list keeps its order in a `decimal(20,10)` column rather than an integer
 * sequence. Dropping a card between two others takes the midpoint of its new
 * neighbours, which is one write however long the list is. Renumbering would be
 * one write per card — 500 rows for one drag.
 *
 * Every value here is a decimal string handled by `brick/math`. Not because
 * positions are money, but because the failure mode is the same one floats
 * cause everywhere else: two cards that ought to differ landing on the same
 * number, after which their order is whatever the database feels like.
 *
 * The gap halves on every insertion between the same pair, so it cannot halve
 * for ever. `MIN_GAP` is the point at which the caller must rebalance the list
 * instead — see `project:rebalance`.
 */
final class Position
{
    /** Digits kept after the point. Matches the column. */
    public const SCALE = 10;

    /** The distance between two freshly appended neighbours. */
    public const STEP = '1024';

    /**
     * Below this, stop halving and rebalance.
     *
     * Well above the column's own resolution on purpose: SQLite stores a
     * decimal as a float, so the last few digits of the declared scale are not
     * somewhere to be operating.
     */
    public const MIN_GAP = '0.0001';

    /** The position after the last card in a list. */
    public static function after(?string $last): string
    {
        if ($last === null) {
            return self::format(self::STEP);
        }

        return self::format(BigDecimal::of($last)->plus(self::STEP));
    }

    /** The position before the first card in a list. */
    public static function before(?string $first): string
    {
        if ($first === null) {
            return self::format(self::STEP);
        }

        return self::format(BigDecimal::of($first)->dividedBy(2, self::SCALE, RoundingMode::Down));
    }

    /**
     * A position between two neighbours, either of which may be absent because
     * the card landed at one end of the list.
     */
    public static function between(?string $before, ?string $after): string
    {
        if ($before === null && $after === null) {
            return self::format(self::STEP);
        }

        if ($before === null) {
            return self::before($after);
        }

        if ($after === null) {
            return self::after($before);
        }

        $low = BigDecimal::of($before);
        $high = BigDecimal::of($after);

        return self::format(
            $low->plus($high)->dividedBy(2, self::SCALE, RoundingMode::Down),
        );
    }

    /**
     * True when the two neighbours are too close to place anything between
     * them. The caller rebalances and tries again.
     */
    public static function needsRebalance(?string $before, ?string $after): bool
    {
        if ($before === null || $after === null) {
            return false;
        }

        return BigDecimal::of($after)
            ->minus($before)
            ->abs()
            ->isLessThan(BigDecimal::of(self::MIN_GAP));
    }

    /**
     * Evenly spaced positions for a whole list, used by the rebalance command.
     *
     * @return list<string>
     */
    public static function spread(int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $step = BigDecimal::of(self::STEP);

        return array_map(
            fn (int $i): string => self::format($step->multipliedBy($i + 1)),
            range(0, $count - 1),
        );
    }

    /** Normalise to the column's own shape so comparisons are string-safe. */
    public static function format(BigDecimal|string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(self::SCALE, RoundingMode::Down);
    }
}
