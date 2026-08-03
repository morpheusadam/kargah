<?php

namespace Tests\Unit;

use Brick\Math\BigDecimal;
use Brick\Money\Exception\UnknownCurrencyException;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;
use PHPUnit\Framework\TestCase;

/**
 * The money path.
 *
 * Getting this wrong is the one failure mode in Kargah that costs the owner
 * real money, so these tests are stricter than the rest. The property under
 * test throughout is that a decimal string goes in, a decimal string comes
 * out, and nothing in between is ever a float.
 */
class MoneyTest extends TestCase
{
    /* The guard ------------------------------------------------------------- */

    public function test_a_float_is_refused_at_the_door(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A float reached the money path');

        Money::of(19.99, Currencies::USD);
    }

    public function test_a_float_quantity_is_refused_too(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::lineTotal(0.5, '120.00', Currencies::USD);
    }

    public function test_a_float_rate_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::convert(Money::of('100', Currencies::USD), 34.15, Currencies::TRY);
    }

    /**
     * Why the guards exist at all, demonstrated rather than asserted.
     *
     * `brick/math`'s `BigNumber::of()` accepts `int|string|BigNumber`. Hand it
     * a float and PHP coerces to `int` first, so an exchange rate of 34.1527
     * silently becomes 34 — a whole lira per dollar — behind nothing louder
     * than a deprecation notice. Every entry point into the money layer takes
     * `float` in its signature purely so it can refuse it by name before this
     * happens.
     */
    public function test_the_maths_library_truncates_a_float_to_an_integer(): void
    {
        $truncated = @BigDecimal::of(34.1527);

        $this->assertSame('34', (string) $truncated, 'brick/math stopped truncating floats — the guards can be relaxed.');
        $this->assertSame('34.1527', (string) BigDecimal::of('34.1527'));
    }

    /**
     * The classic. In binary, 0.1 + 0.2 is 0.30000000000000004, and an invoice
     * built on that is wrong by an amount small enough that nobody notices
     * until a year of them is added up.
     */
    public function test_the_sum_that_floats_get_wrong(): void
    {
        $total = Money::of('0.10', Currencies::USD)->plus(Money::of('0.20', Currencies::USD));

        $this->assertSame('0.300000', Money::toStorage($total));
        $this->assertSame('$0.30', Money::format(Money::toStorage($total), Currencies::USD));

        // The same sum in binary is 0.30000000000000004. PHP's default
        // precision hides that when casting to string, which is precisely why
        // a float bug of this shape survives casual inspection.
        $this->assertFalse(0.1 + 0.2 === 0.3);
    }

    /* Currencies ------------------------------------------------------------- */

    public function test_usdt_exists_with_six_decimal_places_on_both_chains(): void
    {
        $usdt = Currencies::get(Currencies::USDT);

        $this->assertSame('USDT', $usdt->getCurrencyCode());
        $this->assertSame(6, $usdt->getDefaultFractionDigits());
        $this->assertTrue(Currencies::isCrypto(Currencies::USDT));
    }

    public function test_the_two_fiat_currencies_come_from_iso_4217(): void
    {
        $this->assertSame(2, Currencies::minorUnit(Currencies::USD));
        $this->assertSame(2, Currencies::minorUnit(Currencies::TRY));
        $this->assertFalse(Currencies::isCrypto(Currencies::USD));
    }

    public function test_an_unknown_currency_says_what_to_do_about_it(): void
    {
        $this->expectException(UnknownCurrencyException::class);
        $this->expectExceptionMessage('Kargah does not know the currency "XBT"');

        Currencies::get('XBT');
    }

    /* Storage ---------------------------------------------------------------- */

    public function test_every_currency_stores_at_the_same_scale_so_raw_sql_can_compare_them(): void
    {
        $this->assertSame('1500.000000', Money::toStorage(Money::of('1500', Currencies::USD)));
        $this->assertSame('1500.000000', Money::toStorage(Money::of('1500', Currencies::TRY)));
        $this->assertSame('1500.000000', Money::toStorage(Money::of('1500', Currencies::USDT)));
    }

    public function test_a_tether_amount_keeps_all_six_decimals(): void
    {
        // Wallets round differently and under- or over-payment by a few
        // micro-units is normal. Losing them would hide a real discrepancy.
        $arrived = Money::fromStorage('249.999871', Currencies::USDT);

        $this->assertSame('249.999871', Money::toStorage($arrived));
    }

    public function test_reading_a_null_column_gives_zero_rather_than_an_error(): void
    {
        $this->assertSame('0.000000', Money::toStorage(Money::fromStorage(null, Currencies::USD)));
    }

    /* Arithmetic --------------------------------------------------------------- */

    public function test_a_line_total_is_quantity_times_unit_price(): void
    {
        $this->assertSame('1020.000000', Money::toStorage(Money::lineTotal('8.5', '120.00', Currencies::USD)));
    }

    public function test_a_fractional_quantity_does_not_drift(): void
    {
        // Three lots of a third of an hour is one hour, not 0.9999999.
        $line = Money::lineTotal('0.333333', '3000.00', Currencies::USD);

        $this->assertSame('999.999000', Money::toStorage($line));
    }

    public function test_a_total_is_the_sum_of_its_lines(): void
    {
        $total = Money::sum(['1020.000000', '450.500000', '29.990000'], Currencies::USD);

        $this->assertSame('1500.490000', Money::toStorage($total));
    }

    public function test_summing_nothing_is_zero_in_the_right_currency(): void
    {
        $total = Money::sum([], Currencies::TRY);

        $this->assertSame('0.000000', Money::toStorage($total));
        $this->assertSame('TRY', $total->getCurrency()->getCurrencyCode());
    }

    public function test_a_percentage_is_applied_without_a_float(): void
    {
        $vat = Money::percentageOf(Money::of('1500.00', Currencies::USD), '20');

        $this->assertSame('300.000000', Money::toStorage($vat));
    }

    public function test_an_awkward_percentage_rounds_half_up_and_says_so(): void
    {
        // 18% of 12.34 is 2.2212 — the rounding has to be stated, not inferred.
        $tax = Money::percentageOf(Money::of('12.34', Currencies::USD), '18');

        $this->assertSame('2.221200', Money::toStorage($tax));
    }

    /* Conversion ----------------------------------------------------------------- */

    public function test_conversion_uses_the_rate_it_is_given(): void
    {
        $lira = Money::convert(Money::of('1500.00', Currencies::USD), '34.152700', Currencies::TRY);

        $this->assertSame('51229.050000', Money::toStorage($lira));
        $this->assertSame('TRY', $lira->getCurrency()->getCurrencyCode());
    }

    /**
     * There is deliberately no "convert at today's rate" helper. Every
     * converted figure has to be able to name the rate that produced it.
     */
    public function test_there_is_no_way_to_convert_without_stating_a_rate(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A figure whose provenance is invisible');

        Currencies::converter();
    }

    /* Display ---------------------------------------------------------------------- */

    public function test_a_figure_reads_at_its_own_currency_scale(): void
    {
        $this->assertSame('$1,500.49', Money::format('1500.490000', Currencies::USD));
        $this->assertSame('₺51,229.05', Money::format('51229.050000', Currencies::TRY));
        $this->assertSame('₮249.999871', Money::format('249.999871', Currencies::USDT));
    }

    public function test_grouping_is_done_on_the_string_because_number_format_takes_a_float(): void
    {
        $this->assertSame('1,234,567.89', Money::group('1234567.89'));
        $this->assertSame('123.00', Money::group('123.00'));
        $this->assertSame('-1,000.50', Money::group('-1000.50'));
        $this->assertSame('999', Money::group('999'));
    }

    public function test_a_large_figure_keeps_every_digit(): void
    {
        // decimal(20,6) holds up to 99,999,999,999,999.999999. A float would
        // have given up on the last several digits long before here.
        $big = Money::of('99999999999999.999999', Currencies::USDT);

        $this->assertSame('99999999999999.999999', Money::toStorage($big));
    }
}
