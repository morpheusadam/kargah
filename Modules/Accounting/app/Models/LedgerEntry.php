<?php

namespace Modules\Accounting\Models;

use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Accounting\Database\Factories\LedgerEntryFactory;
use Modules\Accounting\Support\Money;

/**
 * One line of the ledger. Append only.
 *
 * There is no `updated_at` and no `deleted_at`, and that is the whole design
 * rather than an omission. A row here is never edited and never removed: a
 * mistake is corrected by a *reversing* entry that points back at the one it
 * undoes, so a correction reads as two rows instead of a gap. The moment a
 * ledger row can be quietly changed, the trail stops being evidence of
 * anything.
 *
 * `reference_type`/`reference_id` is a plain morph rather than a foreign key,
 * so deleting an invoice does not delete the entries that record what was
 * received against it. The trail outlives the document.
 */
class LedgerEntry extends Model
{
    use HasFactory;

    /** The table has `created_at` alone. Nothing here is ever updated. */
    public const UPDATED_AT = null;

    public const TYPE_INVOICE_PAYMENT = 'invoice_payment';

    public const TYPE_EXPENSE = 'expense';

    public const TYPE_FX_CONVERSION = 'fx_conversion';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_REVERSAL = 'reversal';

    protected $fillable = [
        'entry_type', 'reference_type', 'reference_id',
        'currency', 'amount', 'reporting_currency', 'reporting_amount',
        'description', 'reverses_id', 'occurred_at', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            // decimal:N casts to a string, never a float. A balance summed from
            // floats is a balance that drifts.
            'amount' => 'decimal:6',
            'reporting_amount' => 'decimal:6',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Append only, enforced rather than merely documented.
     *
     * Nothing in the module updates or deletes an entry, and this makes an
     * accidental `$entry->save()` five months from now fail loudly at the line
     * that wrote it instead of silently rewriting history.
     */
    protected static function booted(): void
    {
        static::updating(function (self $entry): void {
            throw new \LogicException(
                'Ledger entry #'.$entry->getKey().' cannot be updated. The ledger is append only — '
                .'correct it with reverse() and a fresh entry, which is what keeps the trail readable.',
            );
        });

        static::deleting(function (self $entry): void {
            throw new \LogicException(
                'Ledger entry #'.$entry->getKey().' cannot be deleted. A removed row is a gap; '
                .'a reversing entry is a record. Use reverse().',
            );
        });
    }

    /** The invoice, expense or payment this entry records. */
    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }

    /** The entry this one undoes, if it is a reversal. */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /** The entry that undid this one, if there is one. */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_id');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('entry_type', $type);
    }

    public function scopeForReference(Builder $query, Model $reference): Builder
    {
        return $query
            ->where('reference_type', $reference->getMorphClass())
            ->where('reference_id', $reference->getKey());
    }

    /** Entries that have not been reversed — what a balance is summed from. */
    public function scopeStanding(Builder $query): Builder
    {
        return $query
            ->where('entry_type', '!=', self::TYPE_REVERSAL)
            ->whereDoesntHave('reversal');
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('occurred_at', [$from, $to]);
    }

    public function isReversal(): bool
    {
        return $this->reverses_id !== null;
    }

    public function isReversed(): bool
    {
        return $this->reversal()->exists();
    }

    /**
     * Undo this entry with a new one carrying the opposite sign.
     *
     * The original is left exactly as it was — that is the point of the method
     * existing at all. The negation goes through `BigDecimal::negated()` rather
     * than `-$amount`, because the attribute is a decimal *string* and unary
     * minus on it would cast to float first, which is the one thing this module
     * never does.
     */
    public function reverse(?string $reason = null): self
    {
        if ($this->isReversed()) {
            throw new \DomainException(
                'Ledger entry #'.$this->getKey().' has already been reversed. '
                .'Reversing it twice would net back to the original and say nothing.',
            );
        }

        $reporting = $this->reporting_amount === null
            ? null
            : (string) BigDecimal::of((string) $this->reporting_amount)->negated();

        return static::query()->create([
            'entry_type' => self::TYPE_REVERSAL,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'currency' => $this->currency,
            'amount' => (string) BigDecimal::of((string) $this->amount)->negated(),
            'reporting_currency' => $this->reporting_currency,
            'reporting_amount' => $reporting,
            'description' => $reason ?? 'Reversal of ledger entry #'.$this->getKey(),
            'reverses_id' => $this->getKey(),
            'occurred_at' => now(),
            'created_by' => auth()->id(),
        ]);
    }

    /** How the signed amount reads, in the entry's own currency. */
    public function formattedAmount(): string
    {
        return Money::format((string) $this->amount, $this->currency);
    }

    protected static function newFactory(): LedgerEntryFactory
    {
        return LedgerEntryFactory::new();
    }
}
