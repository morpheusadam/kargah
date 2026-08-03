<?php

namespace Modules\Accounting\Database\Factories;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Support\Currencies;

class LedgerEntryFactory extends Factory
{
    protected $model = LedgerEntry::class;

    public function definition(): array
    {
        $amount = $this->amount(20_000, 800_000);

        return [
            'entry_type' => LedgerEntry::TYPE_INVOICE_PAYMENT,
            // A plain morph with no foreign key behind it, so an entry survives
            // the row it refers to being deleted.
            'reference_type' => null,
            'reference_id' => null,
            'currency' => Currencies::USD,
            'amount' => $amount,
            'reporting_currency' => Currencies::USD,
            'reporting_amount' => $amount,
            'description' => $this->faker->randomElement([
                'Payment received',
                'Hosting renewal',
                'Retainer settled',
                'Opening adjustment',
            ]),
            'reverses_id' => null,
            'occurred_at' => now()->startOfDay()->subDays($this->faker->numberBetween(0, 60)),
            'created_by' => null,
        ];
    }

    public function forReference(Model $reference): static
    {
        return $this->state(fn () => [
            'reference_type' => $reference->getMorphClass(),
            'reference_id' => $reference->getKey(),
        ]);
    }

    /** Money out: an expense is a negative entry, so a balance is a plain sum. */
    public function expense(): static
    {
        return $this->state(function (array $attributes) {
            $out = (string) BigDecimal::of((string) $attributes['amount'])->negated();

            return [
                'entry_type' => LedgerEntry::TYPE_EXPENSE,
                'amount' => $out,
                'reporting_amount' => $out,
                'description' => 'Expense',
            ];
        });
    }

    public function adjustment(): static
    {
        return $this->state(fn () => ['entry_type' => LedgerEntry::TYPE_ADJUSTMENT]);
    }

    public function inCurrency(string $currency): static
    {
        return $this->state(fn () => ['currency' => $currency]);
    }

    /** A decimal string built from an integer number of minor units. */
    private function amount(int $minMinorUnits, int $maxMinorUnits): string
    {
        return (string) BigDecimal::ofUnscaledValue(
            $this->faker->numberBetween($minMinorUnits, $maxMinorUnits),
            2,
        )->toScale(Currencies::STORAGE_SCALE);
    }
}
