<?php

namespace Modules\Accounting\Support;

use Brick\Money\Currency;
use Brick\Money\CurrencyConverter;
use Brick\Money\Exception\UnknownCurrencyException;
use Brick\Money\ISOCurrencyProvider;
use Modules\Accounting\Services\ExchangeRates;

/**
 * The three currencies Kargah deals in, and the one that does not exist.
 *
 * `brick/money` ships ISO 4217, which covers USD and TRY. It does not cover
 * crypto, so USDT is defined here — once, in one place, so no other file has
 * to know that a tether has six decimal places.
 *
 * Six is not a guess: USDT uses six decimals on both TRC-20 and ERC-20, so the
 * two networks agree and there is no cross-chain precision mismatch to handle.
 * Fiat is stored at the same scale with trailing zeros rather than varying the
 * scale per currency, which keeps every amount comparable in raw SQL.
 */
final class Currencies
{
    public const USD = 'USD';

    public const TRY = 'TRY';

    public const USDT = 'USDT';

    /** The scale every money column is stored at. */
    public const STORAGE_SCALE = 6;

    /**
     * Currencies `brick/money` has never heard of.
     *
     * The numeric code is 0 because ISO 4217 has not assigned one and inventing
     * a number that a standards body might later use for something else is how
     * you get a bug in five years.
     */
    private static function custom(): array
    {
        return [
            self::USDT => new Currency(self::USDT, 0, 'Tether', 6),
        ];
    }

    public static function get(string $code): Currency
    {
        $code = strtoupper(trim($code));

        $custom = self::custom();

        if (isset($custom[$code])) {
            return $custom[$code];
        }

        try {
            return ISOCurrencyProvider::getInstance()->getCurrency($code);
        } catch (UnknownCurrencyException) {
            // Re-thrown with somewhere to go: "Unknown currency code: XBT" does
            // not tell whoever hit it where the list of known ones lives.
            throw new UnknownCurrencyException(
                'Kargah does not know the currency "'.$code.'". Add it to '.self::class.' or to the currencies table.',
            );
        }
    }

    public static function isKnown(string $code): bool
    {
        try {
            self::get($code);

            return true;
        } catch (UnknownCurrencyException) {
            return false;
        }
    }

    public static function isCrypto(string $code): bool
    {
        return isset(self::custom()[strtoupper(trim($code))]);
    }

    /** How many decimal places a currency is *displayed* with. */
    public static function minorUnit(string $code): int
    {
        return self::get($code)->getDefaultFractionDigits();
    }

    public static function symbol(string $code): string
    {
        return match (strtoupper(trim($code))) {
            self::USD => '$',
            self::TRY => '₺',
            self::USDT => '₮',
            default => self::get($code)->getCurrencyCode(),
        };
    }

    /** The codes Kargah ships knowing about, in display order. */
    public static function supported(): array
    {
        return [self::USD, self::TRY, self::USDT];
    }

    /**
     * Deliberately absent: a converter built from live rates.
     *
     * Conversion always needs a *stated* rate from `exchange_rates`, because
     * every converted figure has to be able to say which rate produced it and
     * when. A convenience converter that reaches for "the current rate" is how
     * a number nobody can defend to an accountant gets onto an invoice.
     *
     * @see ExchangeRates
     */
    public static function converter(): never
    {
        throw new \LogicException(
            'Build a '.CurrencyConverter::class.' from a stated rate in exchange_rates. '.
            'A figure whose provenance is invisible is a figure nobody can defend.',
        );
    }
}
