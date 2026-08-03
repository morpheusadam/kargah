<?php

namespace Modules\Accounting\Database\Factories;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Accounting\Models\RecurringInvoice;
use Modules\Accounting\Support\Currencies;

/**
 * Not a single float literal in here.
 *
 * `320.00` written in PHP source is a float the moment the parser sees it, and
 * the money layer refuses floats at the door — so the template's amounts are
 * built from an integer number of minor units and handed on as decimal strings.
 */
class RecurringInvoiceFactory extends Factory
{
    protected $model = RecurringInvoice::class;

    public function definition(): array
    {
        return [
            'company_id' => null,
            'customer_id' => null,
            'title' => $this->faker->randomElement([
                'Retainer — product design',
                'Hosting and maintenance',
                'Quarterly support block',
                'Weekly content sprint',
            ]),
            'currency' => Currencies::USD,
            'tax_percent' => '0.000000',
            'cadence' => 'monthly',
            'day_of_month' => null,
            'next_run_on' => today()->addDays($this->faker->numberBetween(1, 20))->toDateString(),
            'last_run_on' => null,
            'lines' => [
                [
                    'description' => 'Monthly retainer',
                    'quantity' => '1',
                    'unit_price' => $this->amount(20_000, 400_000),
                ],
            ],
            'notes' => null,
            'terms' => 'Payment due within 30 days of the invoice date.',
            'is_active' => true,
            'created_by' => null,
        ];
    }

    /** Due today, which is what the generator picks up. */
    public function due(): static
    {
        return $this->state(fn (): array => ['next_run_on' => today()->toDateString()]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }

    public function cadence(string $cadence): static
    {
        return $this->state(fn (): array => ['cadence' => $cadence]);
    }

    public function inCurrency(string $currency): static
    {
        return $this->state(fn (): array => ['currency' => $currency]);
    }

    /**
     * A decimal string built from an integer number of minor units.
     *
     * `ofUnscaledValue(32000, 2)` is 320.00 and never touches a float.
     */
    private function amount(int $minMinorUnits, int $maxMinorUnits): string
    {
        return (string) BigDecimal::ofUnscaledValue(
            $this->faker->numberBetween($minMinorUnits, $maxMinorUnits),
            2,
        )->toScale(Currencies::STORAGE_SCALE);
    }
}
