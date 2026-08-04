<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Accounting\Database\Factories\EstimateLineFactory;
use Modules\Accounting\Support\Money;

/**
 * One line on an estimate.
 *
 * Deliberately thinner than `InvoiceLine`: no `Linkable`. A card that gets
 * *billed* becomes an invoice line through Core's `links` table, and that link
 * is what an invoice has to keep. A quote is a proposal, nothing has been
 * delivered against it, and a model used polymorphically without an alias in
 * `MorphMap` throws — adding the trait means registering `'estimate_line'` from
 * `AccountingServiceProvider::boot()` first.
 *
 * Every money attribute is cast to `decimal:6` and hands back a *string*.
 * `position` is `decimal:10`, matching the column and the same fractional
 * ordering the boards and invoice lines use.
 */
class EstimateLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'estimate_id', 'description', 'quantity', 'unit_price', 'amount', 'position',
    ];

    protected function casts(): array
    {
        return [
            // decimal:N casts to a string, never a float.
            'quantity' => 'decimal:6',
            'unit_price' => 'decimal:6',
            'amount' => 'decimal:6',
            'position' => 'decimal:10',
        ];
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('id');
    }

    /** How the line total reads, in the estimate's own currency. */
    public function formattedAmount(?string $currency = null): string
    {
        return Money::format((string) $this->amount, $currency ?? $this->estimate->currency);
    }

    protected static function newFactory(): EstimateLineFactory
    {
        return EstimateLineFactory::new();
    }
}
