<?php

namespace Modules\Accounting\Database\Factories;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Accounting\Models\RecurringExpense;
use Modules\Accounting\Support\Currencies;

/**
 * Not a single float literal in here.
 *
 * `12.00` written in PHP source is a float the moment the parser sees it, and
 * the money layer refuses floats at the door — so the amount is built from an
 * integer number of minor units and handed on as a decimal string.
 */
class RecurringExpenseFactory extends Factory
{
    protected $model = RecurringExpense::class;

    /** @var array<string, list<string>> */
    private const VENDORS = [
        'Hosting' => ['Hostinger', 'DigitalOcean', 'Hetzner'],
        'Software' => ['KeenThemes', 'Figma', 'JetBrains'],
        'Email' => ['Amazon SES', 'Postmark'],
        'Domains' => ['Namecheap', 'Porkbun'],
        'Other' => ['Accountant', 'Bank charges'],
    ];

    public function definition(): array
    {
        $category = $this->faker->randomElement(array_keys(self::VENDORS));

        return [
            'company_id' => null,
            'vendor' => $this->faker->randomElement(self::VENDORS[$category]),
            'category' => $category,
            'description' => null,
            'currency' => Currencies::USD,
            'amount' => $this->amount(500, 20_000),
            'is_billable' => false,
            'cadence' => 'monthly',
            'day_of_month' => null,
            'next_run_on' => today()->addDays($this->faker->numberBetween(1, 20))->toDateString(),
            'last_run_on' => null,
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

    /** A cost the client agreed to cover, rebilled every period. */
    public function billable(): static
    {
        return $this->state(fn (): array => ['is_billable' => true]);
    }

    /**
     * A decimal string built from an integer number of minor units.
     *
     * `ofUnscaledValue(1200, 2)` is 12.00 and never touches a float.
     */
    private function amount(int $minMinorUnits, int $maxMinorUnits): string
    {
        return (string) BigDecimal::ofUnscaledValue(
            $this->faker->numberBetween($minMinorUnits, $maxMinorUnits),
            2,
        )->toScale(Currencies::STORAGE_SCALE);
    }
}
