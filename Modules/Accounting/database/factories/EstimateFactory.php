<?php

namespace Modules\Accounting\Database\Factories;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Accounting\Models\Estimate;
use Modules\Accounting\Support\Currencies;

/**
 * Not a single float literal in here.
 *
 * `1500.00` written in PHP source is a float the moment the parser sees it, and
 * the money layer refuses floats at the door — so every amount is built from an
 * integer number of minor units and turned into a decimal string. Faker's
 * `randomFloat()` is banned for the same reason.
 *
 * No state here freezes an exchange rate, because an estimate never has one:
 * nothing has been transacted until the invoice it converts into is issued.
 */
class EstimateFactory extends Factory
{
    protected $model = Estimate::class;

    public function definition(): array
    {
        return [
            'number' => 'EST-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9_999), 4, '0', STR_PAD_LEFT),
            'company_id' => null,
            'customer_id' => null,
            'status' => 'draft',
            'currency' => Currencies::USD,

            // Zero until lines exist. `Estimate::recalculateTotal()` is what
            // writes a total, from the lines, through `Money`.
            'total' => '0.000000',

            'valid_until' => today()->addDays(30)->toDateString(),
            'notes' => null,
            'terms' => 'This quote is valid for 30 days. Payment due within 30 days of the invoice date.',

            'converted_invoice_id' => null,
            'converted_invoice_number' => null,
            'converted_at' => null,
            'created_by' => null,
        ];
    }

    /** Out with the client, waiting for an answer. */
    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => 'sent',
            'valid_until' => today()->addDays($this->faker->numberBetween(5, 30))->toDateString(),
        ]);
    }

    /**
     * Sent, and its validity date has passed.
     *
     * The status column still says `sent` on purpose — expiry is derived, not
     * stored, so a fixture that wrote `expired` into the column would be testing
     * a state the application never produces.
     */
    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => 'sent',
            'valid_until' => today()->subDays($this->faker->numberBetween(1, 60))->toDateString(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => ['status' => 'accepted']);
    }

    public function declined(): static
    {
        return $this->state(fn (): array => ['status' => 'declined']);
    }

    public function inCurrency(string $currency): static
    {
        return $this->state(fn (): array => ['currency' => $currency]);
    }

    public function inLira(): static
    {
        return $this->inCurrency(Currencies::TRY);
    }

    /**
     * A stated total, as a decimal string built from minor units.
     *
     * Only useful for a fixture with no lines; anything with lines should go
     * through `Estimate::recalculateTotal()` so the two agree.
     */
    public function totalling(int $minorUnits): static
    {
        return $this->state(fn (): array => [
            'total' => (string) BigDecimal::ofUnscaledValue($minorUnits, 2)->toScale(Currencies::STORAGE_SCALE),
        ]);
    }
}
