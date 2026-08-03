<?php

namespace Modules\Accounting\Database\Factories;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Accounting\Models\ExchangeRate;
use Modules\Accounting\Services\ExchangeRates;
use Modules\Accounting\Support\Currencies;

class ExchangeRateFactory extends Factory
{
    protected $model = ExchangeRate::class;

    public function definition(): array
    {
        return [
            'base_currency' => Currencies::USD,
            'quote_currency' => Currencies::TRY,
            // 38.000000–42.000000, built from an integer number of hundred
            // thousandths. `randomFloat()` is banned here — a rate that arrives
            // as a float puts its error into every invoice converted with it.
            'rate' => $this->rate(3_800_000, 4_200_000, 5),
            'rate_type' => ExchangeRates::MARKET,
            'source' => 'frankfurter',
            // A distinct day per row: the table's business key is
            // (base, quote, type, as_of), so a fixed date would collide the
            // second time this factory is called.
            'as_of' => now()->subDays($this->faker->unique()->numberBetween(0, 90))->toDateString(),
            'fetched_at' => now(),
        ];
    }

    /** The Turkish central bank's buying rate — the one tax procedure names. */
    public function tcmbBuy(): static
    {
        return $this->state(fn () => [
            'rate_type' => ExchangeRates::TCMB_BUY,
            'source' => 'tcmb_evds',
        ]);
    }

    /** USDT against the dollar, hovering where a stablecoin should. */
    public function tether(): static
    {
        return $this->state(fn () => [
            'base_currency' => Currencies::USDT,
            'quote_currency' => Currencies::USD,
            'rate' => $this->rate(999_400, 1_000_600, 6),
            'source' => 'coingecko',
        ]);
    }

    public function on(string $date): static
    {
        return $this->state(fn () => ['as_of' => $date]);
    }

    public function pair(string $base, string $quote): static
    {
        return $this->state(fn () => [
            'base_currency' => $base,
            'quote_currency' => $quote,
        ]);
    }

    /** An integer number of units at `$scale`, rendered as a decimal string. */
    private function rate(int $min, int $max, int $scale): string
    {
        return (string) BigDecimal::ofUnscaledValue(
            $this->faker->numberBetween($min, $max),
            $scale,
        )->toScale(Currencies::STORAGE_SCALE);
    }
}
