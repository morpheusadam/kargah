<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Accounting\Database\Factories\InvoiceFactory;
use Modules\Accounting\Support\Money;
use Modules\Core\Concerns\Linkable;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * An invoice.
 *
 * Every money attribute is cast to `decimal:6`, which hands back a *string*.
 * That is the point: an Eloquent model that returned floats would undo the
 * whole money layer at the first `$invoice->total`.
 */
class Invoice extends Model
{
    use HasFactory;
    use Linkable;
    use LogsActivity;
    use SoftDeletes;

    protected $fillable = [
        'number', 'company_id', 'customer_id', 'status', 'currency',
        'subtotal', 'tax_percent', 'tax_amount', 'total',
        'reporting_currency', 'reporting_rate', 'reporting_amount',
        'issue_rate_to_try', 'issue_rate_source', 'issue_rate_date', 'try_equivalent', 'rate_note',
        'issued_on', 'due_on', 'sent_at', 'paid_at', 'voided_at',
        'notes', 'terms', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            // decimal:N casts to a string, never a float.
            'subtotal' => 'decimal:6',
            'tax_percent' => 'decimal:6',
            'tax_amount' => 'decimal:6',
            'total' => 'decimal:6',
            'reporting_rate' => 'decimal:6',
            'reporting_amount' => 'decimal:6',
            'issue_rate_to_try' => 'decimal:6',
            'try_equivalent' => 'decimal:6',
            'issued_on' => 'date',
            'due_on' => 'date',
            'issue_rate_date' => 'date',
            'sent_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('paid_at');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The ledger is not a relation with a foreign key on purpose.
     *
     * Deleting an invoice must not delete its ledger entries — the trail
     * outlives the document.
     */
    public function ledgerEntries()
    {
        return LedgerEntry::query()
            ->where('reference_type', $this->getMorphClass())
            ->where('reference_id', $this->getKey());
    }

    public function scopeIssued(Builder $query): Builder
    {
        return $query->whereNotNull('sent_at')->whereNull('voided_at');
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->whereNull('sent_at');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->issued()
            ->whereNotIn('status', ['paid', 'void'])
            ->whereDate('due_on', '<', now()->toDateString());
    }

    /**
     * Issued means the act has happened, not that a date is filled in.
     *
     * `issued_on` is the date printed on the document, and back-dating a draft
     * is ordinary — you write Monday's invoice on Wednesday. `sent_at` is the
     * moment `InvoiceIssuer` froze the rates, which is the moment the numbers
     * stopped being allowed to change. Reading the wrong one made every
     * back-dated draft refuse to be issued at all.
     */
    public function isIssued(): bool
    {
        return $this->sent_at !== null;
    }

    public function isVoid(): bool
    {
        return $this->voided_at !== null;
    }

    public function isOverdue(): bool
    {
        return $this->isIssued()
            && ! in_array($this->status, ['paid', 'void'], true)
            && $this->due_on !== null
            && $this->due_on->isBefore(now()->startOfDay());
    }

    /** How the total reads, in the invoice's own currency. */
    public function formattedTotal(): string
    {
        return Money::format((string) $this->total, $this->currency);
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
            ->logOnly(['status', 'total', 'currency', 'issued_on', 'due_on', 'voided_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('invoice');
    }

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }
}
