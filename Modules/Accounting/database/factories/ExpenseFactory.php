<?php

namespace Modules\Accounting\Database\Factories;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Support\Currencies;

class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    /** @var array<string, list<string>> */
    private const VENDORS = [
        'Hosting' => ['Hostinger', 'DigitalOcean', 'Hetzner'],
        'Software' => ['KeenThemes', 'Figma', 'JetBrains'],
        'Email' => ['Amazon SES', 'Postmark'],
        'Domains' => ['Namecheap', 'Porkbun'],
        'Hardware' => ['Apple Store', 'Logitech'],
        'Other' => ['Accountant', 'Bank charges'],
    ];

    public function definition(): array
    {
        $category = $this->faker->randomElement(array_keys(self::VENDORS));
        $amount = (string) BigDecimal::ofUnscaledValue(
            $this->faker->numberBetween(500, 50_000),
            2,
        )->toScale(Currencies::STORAGE_SCALE);

        return [
            'company_id' => null,
            'vendor' => $this->faker->randomElement(self::VENDORS[$category]),
            'category' => $category,
            'description' => null,
            'currency' => Currencies::USD,
            'amount' => $amount,
            // Spent in the reporting currency, so the rate is exactly one and
            // there is nothing to look up. A different currency would need a
            // stated rate, which belongs to whoever records the expense.
            'reporting_currency' => Currencies::USD,
            'reporting_rate' => '1.000000',
            'reporting_amount' => $amount,
            'is_billable' => false,
            'rebilled_on_invoice_id' => null,
            'spent_on' => now()->startOfDay()->subDays($this->faker->numberBetween(0, 90))->toDateString(),
            'receipt_reference' => null,
            'created_by' => null,
        ];
    }

    /** Agreed to be recoverable from the client, and not yet on an invoice. */
    public function billable(): static
    {
        return $this->state(fn () => [
            'is_billable' => true,
            'rebilled_on_invoice_id' => null,
        ]);
    }

    public function rebilledOn(Invoice $invoice): static
    {
        return $this->state(fn () => [
            'is_billable' => true,
            'rebilled_on_invoice_id' => $invoice->id,
        ]);
    }

    public function inCategory(string $category): static
    {
        return $this->state(fn () => [
            'category' => $category,
            'vendor' => $this->faker->randomElement(self::VENDORS[$category] ?? ['Sundry']),
        ]);
    }

    public function inCurrency(string $currency): static
    {
        return $this->state(fn () => ['currency' => $currency]);
    }
}
