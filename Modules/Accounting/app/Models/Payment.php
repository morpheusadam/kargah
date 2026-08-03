<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Database\Factories\PaymentFactory;
use Modules\Accounting\Support\Money;

/**
 * Money that actually landed against an invoice.
 *
 * A payment may arrive in a different currency from the invoice it settles, so
 * it carries three figures rather than one: what was received, the rate that
 * was in force when it was received, and what that settled in the invoice's own
 * currency. `fx_gain_loss` is the difference between what the invoice expected
 * that payment to be worth and what it turned out to be worth — realised the
 * moment the money lands, and never recomputed afterwards.
 *
 * No `LogsActivity` here on purpose: `PaymentRecorder` writes one entry against
 * the *invoice*, which is the subject a person is reading the feed of. A second
 * log on the payment row would say the same thing twice.
 */
class Payment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'invoice_id', 'currency', 'amount',
        'settlement_rate', 'applied_amount', 'fx_gain_loss',
        'method', 'paid_at', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            // decimal:N casts to a string, never a float.
            'amount' => 'decimal:6',
            'settlement_rate' => 'decimal:6',
            'applied_amount' => 'decimal:6',
            'fx_gain_loss' => 'decimal:6',
            'paid_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The on-chain half of a crypto payment.
     *
     * Separate from the payment because a bank transfer has no hash, no chain
     * and no confirmations, and nullable columns for all of that on every row
     * would say nothing about which ones are meaningful.
     */
    public function chainDetail(): HasOne
    {
        return $this->hasOne(CryptoPayment::class);
    }

    public function scopeCrypto(Builder $query): Builder
    {
        return $query->where('method', 'crypto');
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('paid_at', [$from, $to]);
    }

    public function isCrypto(): bool
    {
        return $this->method === 'crypto';
    }

    /** True when the payment currency differs from the invoice's own. */
    public function isCrossCurrency(): bool
    {
        return $this->currency !== $this->invoice?->currency;
    }

    /** How the received amount reads, in the currency it was received in. */
    public function formattedAmount(): string
    {
        return Money::format((string) $this->amount, $this->currency);
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }
}
