<?php

namespace Modules\Accounting\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Accounting\Models\Currency;
use Modules\Accounting\Support\Currencies;

/**
 * The currency table has a closed universe, so this has states rather than
 * random values: inventing a code the money layer has never heard of would
 * produce a row that breaks at the first conversion.
 */
class CurrencyFactory extends Factory
{
    protected $model = Currency::class;

    public function definition(): array
    {
        return [
            'code' => Currencies::USD,
            'name' => 'US Dollar',
            'symbol' => '$',
            'minor_unit' => 2,
            'is_crypto' => false,
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function dollar(): static
    {
        return $this->state(fn () => [
            'code' => Currencies::USD,
            'name' => 'US Dollar',
            'symbol' => '$',
            'minor_unit' => 2,
            'is_crypto' => false,
            'position' => 0,
        ]);
    }

    public function lira(): static
    {
        return $this->state(fn () => [
            'code' => Currencies::TRY,
            'name' => 'Turkish Lira',
            'symbol' => '₺',
            'minor_unit' => 2,
            'is_crypto' => false,
            'position' => 1,
        ]);
    }

    /** Six decimals on both TRC-20 and ERC-20, so the two networks agree. */
    public function tether(): static
    {
        return $this->state(fn () => [
            'code' => Currencies::USDT,
            'name' => 'Tether',
            'symbol' => '₮',
            'minor_unit' => 6,
            'is_crypto' => true,
            'position' => 2,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
