<?php

namespace Modules\Accounting\Models;

use Brick\Money\Money as BrickMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Database\Factories\EstimateFactory;
use Modules\Accounting\Services\InvoiceIssuer;
use Modules\Accounting\Support\Money;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;

/**
 * A quote: what the work would cost if the client says yes.
 *
 * The front half of the job. You quote, they accept, you bill what you quoted —
 * and `convertToInvoice()` is the step that makes the second document say the
 * same numbers as the first instead of somebody retyping them.
 *
 * Three decisions are worth reading before changing anything here.
 *
 * **An estimate freezes no exchange rate, ever.** This is the obvious thing to
 * copy from `Invoice` and it would be wrong. An invoice carries
 * `reporting_rate`, `issue_rate_to_try` and their dates because *issuing* is the
 * moment its figures stop being allowed to move. Nothing has been transacted on
 * an estimate; the rate that will matter is the one in force when the invoice is
 * issued, weeks later. A rate captured here would be a rate nobody agreed to,
 * printed on a document that is still a proposal.
 *
 * **It does not touch the invoice sequence.** `EST-0001`, its own counter. A
 * sequential invoice number is never reused and never left unexplained, so a
 * quote that took one and was then declined would leave a hole in the invoice
 * book that no rule accounts for.
 *
 * **It is not used polymorphically.** No `Linkable`, no `LogsActivity` —
 * `MorphMap::enforce()` throws for any model that is used that way without an
 * alias registered from the module's service provider, and this model has no
 * alias. Adding either trait means adding `'estimate' => Estimate::class` to
 * `AccountingServiceProvider::boot()` first.
 *
 * Every money attribute is cast to `decimal:6`, which hands back a *string*.
 */
class Estimate extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The statuses a person actually chooses.
     *
     * `expired` is not one of them — see `isExpired()`. It is a date having
     * passed, exactly as `overdue` is on an invoice, and it is derived on read.
     */
    public const STATUSES = [
        'draft' => 'Draft',
        'sent' => 'Sent',
        'accepted' => 'Accepted',
        'declined' => 'Declined',
    ];

    /** How long the invoice raised from a converted estimate gives the client to pay. */
    public const PAYMENT_DAYS = 30;

    protected $fillable = [
        'number', 'company_id', 'customer_id', 'status', 'currency', 'total',
        'valid_until', 'notes', 'terms',
        'converted_invoice_id', 'converted_invoice_number', 'converted_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            // decimal:N casts to a string, never a float.
            'total' => 'decimal:6',
            'valid_until' => 'date',
            'converted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(EstimateLine::class)->orderBy('position');
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
     * The invoice this estimate became.
     *
     * `withTrashed()` because a draft invoice can be deleted and a soft-deleted
     * invoice keeps its number reserved — the estimate still became it, and the
     * page has to be able to say so rather than showing a blank where an invoice
     * number used to be.
     */
    public function convertedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id')->withTrashed();
    }

    /* Reading the state -------------------------------------------------------- */

    /**
     * Expiry is derived, never stored.
     *
     * A stored `expired` status needs something to run. Nothing does: there is
     * no estimate cron, and writing the status on page load would mean the
     * column is right on the page somebody happens to open and wrong everywhere
     * else — in a filter, in a count, in a report. `Invoice::isOverdue()` made
     * exactly this call for exactly this reason and `scopeOverdue()` shows what
     * it costs: nothing. A date comparison filters in SQL perfectly well, which
     * is what `scopeExpired()` is.
     *
     * Only a *sent* estimate can expire. An accepted or declined one has had its
     * answer, and a draft was never in front of anybody.
     */
    public function isExpired(): bool
    {
        return $this->status === 'sent'
            && $this->valid_until !== null
            && $this->valid_until->isBefore(now()->startOfDay());
    }

    /**
     * The fact of conversion, which outlives the invoice row.
     *
     * Read from `converted_at` rather than from `converted_invoice_id`, because
     * the foreign key is `nullOnDelete`: force-deleting the invoice would null
     * it and this estimate would offer to be converted a second time, quietly
     * billing the client twice for one accepted quote.
     */
    public function isConverted(): bool
    {
        return $this->converted_at !== null;
    }

    /** How the row reads, which is not always the column. */
    public function state(): string
    {
        return $this->isExpired() ? 'expired' : $this->status;
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', 'sent')
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->toDateString());
    }

    /** Sent, and still inside its own validity date. */
    public function scopeAwaiting(Builder $query): Builder
    {
        return $query->where('status', 'sent')
            ->where(fn (Builder $q) => $q->whereNull('valid_until')
                ->orWhereDate('valid_until', '>=', now()->toDateString()));
    }

    /* Money --------------------------------------------------------------------- */

    /**
     * What the lines come to, added through `Money` and never in SQL.
     *
     * On SQLite a decimal column has NUMERIC affinity, so a non-integer is
     * stored as an IEEE double and `SUM(amount)` is approximate. The rows are
     * fetched and added in PHP, in one currency.
     */
    public function computedTotal(): BrickMoney
    {
        return Money::sum(
            $this->lines()->pluck('amount')->map(fn ($amount): string => (string) $amount),
            $this->currency,
        );
    }

    /** Write the total the lines add up to. Safe to call as often as you like. */
    public function recalculateTotal(): static
    {
        $this->forceFill(['total' => Money::toStorage($this->computedTotal())])->save();

        return $this->refresh();
    }

    /** How the total reads, in the estimate's own currency. */
    public function formattedTotal(): string
    {
        return Money::format((string) $this->total, $this->currency);
    }

    /* Numbering ------------------------------------------------------------------ */

    /**
     * The next estimate number.
     *
     * Read in PHP rather than as `MAX()` in SQL, and counting trashed rows,
     * both for the same reason `⚡invoice-edit::nextNumber()` does: the unique
     * index holds a deleted row's number, so a number computed from the live
     * rows alone collides on save the first time an estimate is deleted.
     */
    public static function nextNumber(): string
    {
        $highest = 0;

        foreach (self::withTrashed()->pluck('number') as $number) {
            if (preg_match('/^EST-(\d+)$/', (string) $number, $matches) === 1) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return 'EST-'.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * The next number in the *invoice* book, for a conversion.
     *
     * 🔴 A deliberate duplicate of `⚡invoice-edit::nextNumber()`. That method is
     * private to a Livewire single-file component, and extracting the pair into
     * a shared service would mean editing `⚡invoice-edit.blade.php`, which
     * belongs to another agent this wave. The two must stay in step: the shape
     * is `INV-0001`, the scan counts `withTrashed()`, and the recurring
     * generator's own `INV-R7-20260901` shape is skipped by the pattern rather
     * than sorted against — a string maximum across both shapes is whichever
     * sorts last, not whichever is highest.
     */
    public static function nextInvoiceNumber(): string
    {
        $highest = 0;

        foreach (Invoice::withTrashed()->pluck('number') as $number) {
            if (preg_match('/^INV-(\d+)$/', (string) $number, $matches) === 1) {
                $highest = max($highest, (int) $matches[1]);
            }
        }

        return 'INV-'.str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT);
    }

    /* Conversion ------------------------------------------------------------------- */

    /**
     * Turn an accepted estimate into a **draft** invoice.
     *
     * Draft, never issued. Issuing freezes the exchange rates onto the row and
     * consumes a sequential number for good; it is a deliberate act a person
     * performs with the invoice in front of them, and it lives in
     * `InvoiceIssuer`. A convert that silently issued would burn a number on a
     * click and freeze a rate nobody had looked at.
     *
     * 🔴 **Once, and only once.** A second conversion is refused rather than
     * recorded. The alternative — allow it, keep a list — was considered and
     * rejected: the failure it protects against is an impatient second click
     * billing a client twice for one accepted quote, and an estimate that can
     * name several invoices can no longer answer the question the link exists to
     * answer. A soft-deleted invoice does not reopen the door either; it kept
     * its number, it still exists to be restored, and this estimate still says
     * what it became. If a genuinely second invoice is wanted for the same work,
     * raise it in the invoice book, where somebody chooses its number knowingly.
     *
     * The line amounts are carried across as stored rather than recomputed, so
     * the invoice says the figure the client was quoted down to the last decimal
     * — recomputation is where a rounding difference between two documents that
     * are meant to agree gets in.
     *
     * @throws \DomainException when the estimate has not been accepted, or has
     *                          already been converted.
     */
    public function convertToInvoice(): Invoice
    {
        if ($this->status !== 'accepted') {
            throw new \DomainException(
                $this->number.' has not been accepted yet, so there is nothing to bill. '
                .'Mark it accepted once the client has said yes, then convert it.',
            );
        }

        if ($this->isConverted()) {
            throw new \DomainException(
                $this->number.' already became invoice '.$this->converted_invoice_number.' on '
                .$this->converted_at->format('j F Y').'. Converting it again would bill the client twice for '
                .'one accepted quote. Open that invoice instead, or raise a new one from the invoice book.',
            );
        }

        return DB::transaction(function (): Invoice {
            $invoice = Invoice::query()->create([
                'number' => self::nextInvoiceNumber(),
                'company_id' => $this->company_id,
                'customer_id' => $this->customer_id,
                'status' => 'draft',
                'currency' => $this->currency,
                // No tax on an estimate, so none is asserted on the invoice.
                // Whether KDV applies — and whether the export exemption holds —
                // is a judgement made per invoice, on the invoice.
                'tax_percent' => '0',
                // The date the invoice is *for*. `sent_at` stays null, so
                // `isIssued()` is false and the draft remains editable.
                'issued_on' => today()->toDateString(),
                'due_on' => today()->addDays(self::PAYMENT_DAYS)->toDateString(),
                'notes' => $this->notes,
                'terms' => $this->terms,
                'created_by' => $this->created_by,
            ]);

            foreach ($this->lines()->get() as $line) {
                InvoiceLine::query()->create([
                    'invoice_id' => $invoice->id,
                    'description' => (string) $line->description,
                    'quantity' => (string) $line->quantity,
                    'unit_price' => (string) $line->unit_price,
                    'amount' => (string) $line->amount,
                    'position' => (string) $line->position,
                ]);
            }

            // The service owns the arithmetic on the way into the database, so
            // the invoice's stored totals come from the same code every other
            // invoice's do rather than from a second implementation here.
            $invoice = app(InvoiceIssuer::class)->recalculate($invoice);

            $this->forceFill([
                'converted_invoice_id' => $invoice->getKey(),
                // Kept as a copy, not read through the relation: the estimate
                // has to be able to name the invoice even if that row is one day
                // force-deleted and the foreign key nulled.
                'converted_invoice_number' => $invoice->number,
                'converted_at' => now(),
            ])->save();

            return $invoice;
        });
    }

    protected static function newFactory(): EstimateFactory
    {
        return EstimateFactory::new();
    }
}
