<?php

namespace Modules\Accounting\Database\Factories;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;

/**
 * Not a single float literal in here, and none anywhere below it.
 *
 * `1500.00` written in PHP source is a float the moment the parser sees it, and
 * the money layer refuses floats at the door — so every amount is built from
 * an integer number of minor units and turned into a decimal string. Faker's
 * `randomFloat()` is banned for the same reason.
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        $currency = Currencies::USD;
        $subtotal = $this->amount(20_000, 800_000);

        return [
            'number' => 'INV-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9_999), 4, '0', STR_PAD_LEFT),
            'company_id' => null,
            'customer_id' => null,
            'status' => 'draft',
            'currency' => $currency,

            'subtotal' => $subtotal,
            'tax_percent' => '0.000000',
            'tax_amount' => '0.000000',
            'total' => $subtotal,

            // A draft has no frozen rate yet. Freezing one belongs to
            // InvoiceIssuer, at the moment of issue and nowhere else.
            'reporting_currency' => null,
            'reporting_rate' => null,
            'reporting_amount' => null,

            'issued_on' => null,
            'due_on' => null,
            'notes' => null,
            'terms' => 'Payment due within 30 days.',
            'created_by' => null,
        ];
    }

    /** Issued and unpaid, with a due date still ahead of it. */
    public function sent(): static
    {
        return $this->state(function (array $attributes) {
            $issued = now()->startOfDay()->subDays($this->faker->numberBetween(2, 20));

            return [
                'status' => 'sent',
                'issued_on' => $issued->toDateString(),
                'due_on' => $issued->copy()->addDays(30)->toDateString(),
                'sent_at' => $issued,
            ] + $this->reportingState($attributes);
        });
    }

    public function paid(): static
    {
        return $this->sent()->state(fn () => [
            'status' => 'paid',
            'paid_at' => now()->startOfDay()->subDays($this->faker->numberBetween(0, 2)),
        ]);
    }

    /** Issued, unpaid, and past its due date — what the list colours red. */
    public function overdue(): static
    {
        return $this->state(function (array $attributes) {
            $issued = now()->startOfDay()->subDays($this->faker->numberBetween(35, 60));

            return [
                'status' => 'overdue',
                'issued_on' => $issued->toDateString(),
                'due_on' => $issued->copy()->addDays(30)->toDateString(),
                'sent_at' => $issued,
            ] + $this->reportingState($attributes);
        });
    }

    public function voided(): static
    {
        return $this->sent()->state(fn () => [
            'status' => 'void',
            'voided_at' => now(),
        ]);
    }

    public function inCurrency(string $currency): static
    {
        return $this->state(fn () => ['currency' => $currency]);
    }

    public function inLira(): static
    {
        return $this->inCurrency(Currencies::TRY);
    }

    public function inTether(): static
    {
        return $this->inCurrency(Currencies::USDT);
    }

    /**
     * Tax at a stated percentage, with the total recomputed to match.
     *
     * `'20'`, not `20.0`: the percentage travels as a decimal string the whole
     * way, exactly as `Money::percentageOf()` requires.
     */
    public function taxed(string $percent = '20'): static
    {
        return $this->state(function (array $attributes) use ($percent) {
            $subtotal = Money::of((string) $attributes['subtotal'], $attributes['currency']);
            $tax = Money::percentageOf($subtotal, $percent);

            return [
                'tax_percent' => $percent,
                'tax_amount' => Money::toStorage($tax),
                'total' => Money::toStorage($subtotal->plus($tax, Money::ROUNDING)),
            ];
        });
    }

    /**
     * The reporting figure an issued invoice carries.
     *
     * Same currency means a rate of exactly one — no lookup, nothing to get
     * wrong. A different currency is left null here rather than invented,
     * because a made-up rate on a fixture is a made-up rate in a test.
     */
    private function reportingState(array $attributes): array
    {
        if (($attributes['currency'] ?? Currencies::USD) !== Currencies::USD) {
            return [];
        }

        return [
            'reporting_currency' => Currencies::USD,
            'reporting_rate' => '1.000000',
            'reporting_amount' => (string) $attributes['total'],
        ];
    }

    /**
     * A decimal string built from an integer number of minor units.
     *
     * `ofUnscaledValue(150000, 2)` is 1500.00 and never touches a float.
     */
    private function amount(int $minMinorUnits, int $maxMinorUnits): string
    {
        return (string) BigDecimal::ofUnscaledValue(
            $this->faker->numberBetween($minMinorUnits, $maxMinorUnits),
            2,
        )->toScale(Currencies::STORAGE_SCALE);
    }
}
