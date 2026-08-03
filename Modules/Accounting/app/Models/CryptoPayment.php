<?php

namespace Modules\Accounting\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Database\Factories\CryptoPaymentFactory;
use Modules\Accounting\Support\Money;

/**
 * The on-chain half of a crypto payment: enough to be verified by someone who
 * does not trust you.
 *
 * `chain` is not cosmetic. USDT exists on both Tron and Ethereum with different
 * addresses, and sending to the wrong network destroys the funds — so the row
 * has to say which one, and the explorer link has to follow it rather than
 * being guessed from the token.
 *
 * `amount` is what the chain says arrived, recorded separately from the invoice
 * figure. Wallets round differently and being a few micro-units out is normal;
 * assuming the two match is how a real shortfall goes unnoticed.
 */
class CryptoPayment extends Model
{
    use HasFactory;

    public const CHAIN_TRON = 'tron';

    public const CHAIN_ETHEREUM = 'ethereum';

    /**
     * Confirmations after which a Tron transfer is treated as irreversible.
     *
     * Tron blocks land roughly every three seconds and a transaction is final
     * once a two-thirds majority of the 27 super representatives has built on
     * it — 19 blocks, about a minute. Waiting is cheap here; a reversal after
     * the invoice has been marked paid is not.
     */
    public const FINAL_CONFIRMATIONS = 19;

    protected $fillable = [
        'payment_id', 'chain', 'token_standard',
        'tx_hash', 'from_address', 'to_address',
        'amount', 'network_fee', 'block_number', 'confirmations',
        'status', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            // decimal:N casts to a string, never a float. A network fee read as
            // a float is a float subtracted from a balance.
            'amount' => 'decimal:6',
            'network_fee' => 'decimal:6',
            'block_number' => 'integer',
            'confirmations' => 'integer',
            'verified_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function scopeOnChain(Builder $query, string $chain): Builder
    {
        return $query->where('chain', $chain);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    /**
     * Past the point of being reorganised away.
     *
     * A failed transfer never becomes final however many blocks pass, so the
     * status is checked as well as the depth.
     */
    public function isFinal(): bool
    {
        return $this->status !== 'failed'
            && $this->confirmations >= self::FINAL_CONFIRMATIONS;
    }

    public function hasFailed(): bool
    {
        return $this->status === 'failed';
    }

    /** How many more blocks before `isFinal()` turns true. */
    public function confirmationsRemaining(): int
    {
        return max(0, self::FINAL_CONFIRMATIONS - (int) $this->confirmations);
    }

    /**
     * Where a human goes to check this themselves.
     *
     * Chain-driven, not token-driven: the same USDT hash means nothing on the
     * other network's explorer.
     */
    public function explorerUrl(): ?string
    {
        return match ($this->chain) {
            self::CHAIN_TRON => 'https://tronscan.org/#/transaction/'.$this->tx_hash,
            self::CHAIN_ETHEREUM => 'https://etherscan.io/tx/'.$this->tx_hash,
            default => null,
        };
    }

    /** The hash as it is shown in a table: first six and last six. */
    public function shortHash(): string
    {
        $hash = (string) $this->tx_hash;

        return mb_strlen($hash) <= 16 ? $hash : mb_substr($hash, 0, 6).'…'.mb_substr($hash, -6);
    }

    /**
     * What arrived on chain minus what the payment says it settled, as a
     * decimal string. Positive is an overpayment, negative a shortfall.
     */
    public function deltaAgainstPayment(): ?string
    {
        if ($this->payment === null) {
            return null;
        }

        return (string) BigDecimal::of((string) $this->amount)
            ->minus(BigDecimal::of((string) $this->payment->amount));
    }

    /** How the on-chain amount reads, in the payment's own currency. */
    public function formattedAmount(): string
    {
        return Money::format((string) $this->amount, $this->payment?->currency ?? 'USDT');
    }

    protected static function newFactory(): CryptoPaymentFactory
    {
        return CryptoPaymentFactory::new();
    }
}
