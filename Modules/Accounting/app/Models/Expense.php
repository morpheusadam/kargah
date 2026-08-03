<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Database\Factories\ExpenseFactory;
use Modules\Accounting\Support\Money;
use Modules\Core\Concerns\Linkable;
use Modules\Core\Models\Company;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * What the business costs to run.
 *
 * An expense carries its own reporting figure, frozen the same way an invoice's
 * is, so a year can be totalled without re-deriving rates that have since
 * moved. The alternative — converting at read time — makes last March's cost
 * change every time the lira does.
 *
 * `is_billable` and `rebilled_on_invoice_id` are two different questions and
 * are stored as two columns for that reason: a cost the client agreed to cover
 * is billable the day it is incurred, and stays unbilled until an invoice
 * actually carries it. `unbilled()` is the gap between the two, and it is the
 * money most easily forgotten.
 */
class Expense extends Model
{
    use HasFactory;
    use Linkable;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'vendor', 'category', 'description',
        'currency', 'amount',
        'reporting_currency', 'reporting_rate', 'reporting_amount',
        'is_billable', 'rebilled_on_invoice_id',
        'spent_on', 'receipt_reference', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            // decimal:N casts to a string, never a float.
            'amount' => 'decimal:6',
            'reporting_rate' => 'decimal:6',
            'reporting_amount' => 'decimal:6',
            'is_billable' => 'boolean',
            // A date, not an instant: something bought on 31 July was bought on
            // 31 July wherever the row is read.
            'spent_on' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The invoice that passed this cost on to the client, if one has. */
    public function rebilledOn(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'rebilled_on_invoice_id');
    }

    public function scopeBillable(Builder $query): Builder
    {
        return $query->where('is_billable', true);
    }

    /** Agreed to be recoverable and not yet on an invoice. */
    public function scopeUnbilled(Builder $query): Builder
    {
        return $query->where('is_billable', true)->whereNull('rebilled_on_invoice_id');
    }

    public function scopeInCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('spent_on', [$from, $to]);
    }

    public function isRebilled(): bool
    {
        return $this->rebilled_on_invoice_id !== null;
    }

    /** How the amount reads, in the currency it was actually spent in. */
    public function formattedAmount(): string
    {
        return Money::format((string) $this->amount, $this->currency);
    }

    /** The reporting figure, marked as converted — never shown on its own. */
    public function formattedReporting(): ?string
    {
        if ($this->reporting_amount === null || $this->reporting_currency === null) {
            return null;
        }

        return Money::format((string) $this->reporting_amount, $this->reporting_currency);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['vendor', 'category', 'amount', 'currency', 'is_billable', 'rebilled_on_invoice_id', 'spent_on'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('expense');
    }

    protected static function newFactory(): ExpenseFactory
    {
        return ExpenseFactory::new();
    }
}
