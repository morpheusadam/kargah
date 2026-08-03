<?php

namespace Modules\Accounting\Database\Factories;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Support\Currencies;

class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        $amount = (string) BigDecimal::ofUnscaledValue(
            $this->faker->numberBetween(20_000, 800_000),
            2,
        )->toScale(Currencies::STORAGE_SCALE);

        return [
            'invoice_id' => Invoice::factory(),
            'currency' => Currencies::USD,
            'amount' => $amount,
            // Paid in the invoice's own currency, so there is nothing to
            // convert and nothing realised. Both figures follow from that.
            'settlement_rate' => '1.000000',
            'applied_amount' => $amount,
            'fx_gain_loss' => '0.000000',
            'method' => 'bank',
            'paid_at' => now()->startOfDay()->subDays($this->faker->numberBetween(0, 30)),
            'note' => null,
            'created_by' => null,
        ];
    }

    /**
     * Settles an invoice in full, whatever the invoice's total happens to be.
     *
     * The total is read as a string and handed straight back, so the payment
     * matches to the last stored digit rather than to two decimal places.
     */
    public function settling(Invoice $invoice): static
    {
        return $this->state(fn () => [
            'invoice_id' => $invoice->id,
            'currency' => $invoice->currency,
            'amount' => (string) $invoice->total,
            'settlement_rate' => '1.000000',
            'applied_amount' => (string) $invoice->total,
            'fx_gain_loss' => '0.000000',
        ]);
    }

    public function crypto(): static
    {
        return $this->state(fn () => [
            'method' => 'crypto',
            'currency' => Currencies::USDT,
        ]);
    }

    public function inCurrency(string $currency): static
    {
        return $this->state(fn () => ['currency' => $currency]);
    }
}
