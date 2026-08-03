<?php

namespace Modules\Accounting\Database\Factories;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\InvoiceLine;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;

class InvoiceLineFactory extends Factory
{
    protected $model = InvoiceLine::class;

    /** Quantities as decimal strings. Half a day is '0.5', never 0.5. */
    private const QUANTITIES = ['0.500000', '1.000000', '2.000000', '3.000000', '4.000000', '8.000000'];

    public function definition(): array
    {
        $quantity = $this->faker->randomElement(self::QUANTITIES);
        $unitPrice = (string) BigDecimal::ofUnscaledValue(
            $this->faker->numberBetween(5_000, 90_000),
            2,
        )->toScale(Currencies::STORAGE_SCALE);

        return [
            'invoice_id' => Invoice::factory(),
            'description' => $this->faker->randomElement([
                'Development retainer — four days',
                'Mail module: inbox and reading pane',
                'Booking widget, embeddable build',
                'Migration off shared hosting',
                'Invoice PDF fixes',
                'Analytics dashboard scoping',
                'Contact import tooling',
                'Hand-over documentation',
            ]),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            // Not a third independent number: the line total is the product,
            // computed through the money layer at the storage scale.
            'amount' => Money::toStorage(Money::lineTotal($quantity, $unitPrice, Currencies::USD)),
            'position' => $this->position(),
        ];
    }

    public function forInvoice(Invoice $invoice): static
    {
        return $this->state(fn () => ['invoice_id' => $invoice->id]);
    }

    /**
     * The fractional ordering key, at the column's own scale.
     *
     * decimal(20,10), built with `brick/math` for the same reason the boards
     * do it: two lines landing on the same float is two lines with no order.
     */
    private function position(): string
    {
        return (string) BigDecimal::of('1024')
            ->multipliedBy($this->faker->unique()->numberBetween(1, 512))
            ->toScale(10, RoundingMode::Down);
    }
}
