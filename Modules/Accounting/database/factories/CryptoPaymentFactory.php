<?php

namespace Modules\Accounting\Database\Factories;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Accounting\Models\CryptoPayment;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Support\Currencies;

class CryptoPaymentFactory extends Factory
{
    protected $model = CryptoPayment::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory()->crypto(),
            'chain' => CryptoPayment::CHAIN_TRON,
            'token_standard' => 'TRC-20',
            'tx_hash' => bin2hex(random_bytes(32)),
            'from_address' => 'T'.$this->faker->regexify('[A-Za-z0-9]{33}'),
            'to_address' => 'T'.$this->faker->regexify('[A-Za-z0-9]{33}'),
            'amount' => (string) BigDecimal::ofUnscaledValue(
                $this->faker->numberBetween(20_000, 800_000),
                2,
            )->toScale(Currencies::STORAGE_SCALE),
            // Tron energy, paid in TRX and stored at the same scale as
            // everything else so it can be summed alongside it.
            'network_fee' => (string) BigDecimal::ofUnscaledValue(
                $this->faker->numberBetween(100_000, 5_000_000),
                6,
            )->toScale(Currencies::STORAGE_SCALE),
            'block_number' => $this->faker->numberBetween(60_000_000, 70_000_000),
            'confirmations' => 0,
            'status' => 'pending',
            'verified_at' => null,
        ];
    }

    /** Deep enough that `isFinal()` is true. */
    public function confirmed(): static
    {
        return $this->state(fn () => [
            'confirmations' => $this->faker->numberBetween(CryptoPayment::FINAL_CONFIRMATIONS, 512),
            'status' => 'confirmed',
            'verified_at' => now(),
        ]);
    }

    /** Seen on chain but not yet final — the window a reorg could still take. */
    public function pending(): static
    {
        return $this->state(fn () => [
            'confirmations' => $this->faker->numberBetween(0, CryptoPayment::FINAL_CONFIRMATIONS - 1),
            'status' => 'pending',
            'verified_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'confirmations' => $this->faker->numberBetween(CryptoPayment::FINAL_CONFIRMATIONS, 512),
            'status' => 'failed',
            'verified_at' => null,
        ]);
    }

    /** The same token, the other network — different addresses, different explorer. */
    public function ethereum(): static
    {
        return $this->state(fn () => [
            'chain' => CryptoPayment::CHAIN_ETHEREUM,
            'token_standard' => 'ERC-20',
            'tx_hash' => '0x'.bin2hex(random_bytes(32)),
            'from_address' => '0x'.bin2hex(random_bytes(20)),
            'to_address' => '0x'.bin2hex(random_bytes(20)),
        ]);
    }

    public function forPayment(Payment $payment): static
    {
        return $this->state(fn () => [
            'payment_id' => $payment->id,
            'amount' => (string) $payment->amount,
        ]);
    }
}
