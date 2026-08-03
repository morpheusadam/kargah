<?php

namespace Modules\Accounting\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use Brick\Money\Context\CustomContext;
use Brick\Money\Money as BrickMoney;

/**
 * Every arithmetic operation Kargah performs on money.
 *
 * There is exactly one rule and this class exists to make it impossible to
 * break by accident: **no float ever touches a monetary value.** Not on the way
 * in, not on the way out, not in a total, not in a percentage. A float cannot
 * hold 0.1, and an invoice that is a hundredth of a cent wrong is an invoice
 * the owner has to explain to an accountant.
 *
 * Every method takes and returns decimal strings, and every operation that can
 * lose a digit states its rounding mode out loud. `brick/money` refuses to
 * round implicitly, which is the whole reason it is here.
 *
 * Note `RoundingMode` is an enum in the installed brick/math — the spec's
 * `RoundingMode::HALF_UP` does not exist; the case is `HalfUp`.
 */
final class Money
{
    /** How totals round. Half up, the convention every invoice uses. */
    public const ROUNDING = RoundingMode::HalfUp;

    /**
     * Build a money value.
     *
     * `$amount` must be a decimal string or an integer. `float` is in the
     * signature only so the guard can *reject* it by name: without it PHP
     * quietly coerces 19.99 to the integer 19 and the error surfaces as a
     * missing 99 cents somewhere downstream. The point of failure should be
     * the line that introduced the float, not the total three screens later.
     */
    public static function of(string|int|float|BigNumber $amount, string $currency): BrickMoney
    {
        return BrickMoney::of(
            self::assertNotFloat($amount),
            Currencies::get($currency),
            self::context(),
            self::ROUNDING,
        );
    }

    /**
     * Every value carries the storage scale, not the currency's display scale.
     *
     * Working at two decimals internally would round each line before the
     * total, and the difference between rounding per line and rounding at the
     * end is exactly the kind of penny an accountant asks about.
     */
    private static function context(): CustomContext
    {
        return new CustomContext(Currencies::STORAGE_SCALE);
    }

    /** Zero in a currency. */
    public static function zero(string $currency): BrickMoney
    {
        return BrickMoney::zero(Currencies::get($currency), self::context());
    }

    /**
     * A money value read straight out of a `decimal(20,6)` column.
     *
     * The column holds more decimals than most currencies display; the stored
     * scale is kept, because rounding belongs at the point a figure is
     * *computed* or *shown*, not every time a row is read.
     */
    public static function fromStorage(string|int|float|null $amount, string $currency): BrickMoney
    {
        return $amount === null ? self::zero($currency) : self::of($amount, $currency);
    }

    /** The decimal string to write back into a `decimal(20,6)` column. */
    public static function toStorage(BrickMoney $money): string
    {
        return (string) $money->getAmount()->toScale(Currencies::STORAGE_SCALE, RoundingMode::HalfUp);
    }

    /**
     * A line total: quantity × unit price.
     *
     * Quantity is a decimal string too — half an hour is 0.5, and 0.5 as a
     * float is one of the few that happens to be exact, which is exactly the
     * kind of luck that hides the bug until 0.1 turns up.
     */
    public static function lineTotal(string|float $quantity, string|float $unitPrice, string $currency): BrickMoney
    {
        return self::of(
            (string) BigDecimal::of(self::assertNotFloat($quantity))
                ->multipliedBy(BigDecimal::of(self::assertNotFloat($unitPrice)))
                ->toScale(Currencies::STORAGE_SCALE, RoundingMode::HalfUp),
            $currency,
        );
    }

    /**
     * Sum a set of amounts in one currency.
     *
     * @param  iterable<string|BrickMoney>  $amounts
     */
    public static function sum(iterable $amounts, string $currency): BrickMoney
    {
        $total = self::zero($currency);

        foreach ($amounts as $amount) {
            $total = $total->plus(
                $amount instanceof BrickMoney ? $amount : self::fromStorage($amount, $currency),
                self::ROUNDING,
            );
        }

        return $total;
    }

    /**
     * Apply a percentage — tax, discount, withholding.
     *
     * Stated as a decimal string like '20' for twenty per cent.
     */
    public static function percentageOf(BrickMoney $money, string|float $percent): BrickMoney
    {
        return $money->multipliedBy(
            BigDecimal::of(self::assertNotFloat($percent))->dividedBy('100', 10, RoundingMode::HalfUp),
            self::ROUNDING,
        );
    }

    /**
     * Convert at a stated rate.
     *
     * There is no overload that looks the rate up. A converted figure has to
     * be able to say which rate produced it and on what date, so the caller
     * holds the rate and therefore holds the provenance.
     */
    public static function convert(BrickMoney $money, string|float $rate, string $toCurrency): BrickMoney
    {
        return BrickMoney::of(
            (string) $money->getAmount()
                ->multipliedBy(BigDecimal::of(self::assertNotFloat($rate)))
                ->toScale(Currencies::STORAGE_SCALE, RoundingMode::HalfUp),
            Currencies::get($toCurrency),
            self::context(),
            self::ROUNDING,
        );
    }

    /** How a figure reads on screen, at the currency's own scale. */
    public static function format(string|int|float|null $amount, string $currency): string
    {
        $money = self::fromStorage($amount, $currency);

        $display = (string) $money->getAmount()->toScale(Currencies::minorUnit($currency), RoundingMode::HalfUp);

        return Currencies::symbol($currency).self::group($display);
    }

    /**
     * Thousands separators, done on the string.
     *
     * `number_format()` takes a float, so it is not allowed anywhere near a
     * monetary value — it would undo the entire point of this class on the last
     * step before the number reaches a human.
     */
    public static function group(string $decimal): string
    {
        $negative = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '-');

        [$whole, $fraction] = array_pad(explode('.', $decimal, 2), 2, null);

        $grouped = strrev(implode(',', str_split(strrev($whole), 3)));

        return ($negative ? '-' : '').$grouped.($fraction === null ? '' : '.'.$fraction);
    }

    /**
     * The guard.
     *
     * A float arriving here means someone wrote `0.1 + 0.2` somewhere upstream,
     * and the value is already wrong — so this refuses it rather than quietly
     * casting and carrying the error forward.
     */
    private static function assertNotFloat(mixed $value): string|int|BigNumber
    {
        if (is_float($value)) {
            throw new \InvalidArgumentException(
                'A float reached the money path with value '.var_export($value, true).'. '.
                'Money is decimal strings all the way through — see '.self::class.'.',
            );
        }

        return $value;
    }
}
