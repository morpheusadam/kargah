<?php

namespace Modules\Accounting\Models;

use Brick\Money\Money as BrickMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Accounting\Database\Factories\RecurringInvoiceFactory;
use Modules\Accounting\Support\Money;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;

/**
 * A retainer, a hosting bill, anything billed on a rhythm.
 *
 * The schedule holds a template and a date. It never holds an issued figure,
 * because nothing here has been issued: `accounting:generate-recurring` raises
 * a **draft** and a person decides when it becomes an invoice. The totals this
 * model reports are therefore *estimates* of what the next draft will say, and
 * they are computed through `Money` like every other figure in Kargah.
 *
 * `next_run_on` is the business key of the next occurrence. Advancing it is how
 * the generator stays idempotent, so nothing but the generator writes it.
 */
class RecurringInvoice extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const CADENCES = [
        'weekly' => 'Every week',
        'monthly' => 'Every month',
        'quarterly' => 'Every quarter',
        'yearly' => 'Every year',
    ];

    protected $fillable = [
        'company_id', 'customer_id', 'title',
        'currency', 'tax_percent', 'cadence', 'day_of_month',
        'next_run_on', 'last_run_on', 'lines',
        'notes', 'terms', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            // decimal:N casts to a string, never a float.
            'tax_percent' => 'decimal:6',
            'next_run_on' => 'date',
            'last_run_on' => 'date',
            'lines' => 'array',
            'day_of_month' => 'integer',
            'is_active' => 'boolean',
        ];
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
     * The drafts this schedule has raised.
     *
     * Matched on the number the generator derives from the schedule rather than
     * a foreign key: an invoice raised here is an ordinary invoice from the
     * moment it exists, and deleting the schedule must not cascade into the
     * invoice book.
     */
    public function raisedInvoices(): Builder
    {
        return Invoice::query()->where('number', 'like', $this->numberPrefix().'%');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePaused(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /** Schedules whose next occurrence has arrived. */
    public function scopeDue(Builder $query, Carbon|string|null $on = null): Builder
    {
        $on = $on === null ? today() : ($on instanceof Carbon ? $on : Carbon::parse($on));

        return $query->active()->whereDate('next_run_on', '<=', $on->toDateString());
    }

    public function isDue(Carbon|string|null $on = null): bool
    {
        $on = $on === null ? today() : ($on instanceof Carbon ? $on : Carbon::parse($on));

        return $this->is_active
            && $this->next_run_on !== null
            && ! $this->next_run_on->startOfDay()->isAfter($on->copy()->startOfDay());
    }

    /* The template ---------------------------------------------------------- */

    /**
     * The template lines, normalised and with every figure a decimal string.
     *
     * A template that has been edited by hand, or written by an older version
     * of the form, may be missing a key. A missing quantity is one, and a
     * missing price is nothing — both are decimal strings, because the money
     * layer refuses anything else at the door.
     *
     * @return list<array{description: string, quantity: string, unit_price: string}>
     */
    public function templateLines(): array
    {
        return array_values(array_map(fn (array $line): array => [
            'description' => (string) ($line['description'] ?? ''),
            'quantity' => self::decimal($line['quantity'] ?? '1', '1'),
            'unit_price' => self::decimal($line['unit_price'] ?? '0', '0'),
        ], is_array($this->lines) ? $this->lines : []));
    }

    /** What the next draft's subtotal will come to. */
    public function subtotal(): BrickMoney
    {
        return Money::sum(
            array_map(
                fn (array $line): BrickMoney => Money::lineTotal($line['quantity'], $line['unit_price'], $this->currency),
                $this->templateLines(),
            ),
            $this->currency,
        );
    }

    public function taxAmount(): BrickMoney
    {
        return Money::percentageOf($this->subtotal(), self::decimal((string) $this->tax_percent, '0'));
    }

    /** What the next draft will total, as a decimal string. */
    public function estimatedTotal(): string
    {
        return Money::toStorage($this->subtotal()->plus($this->taxAmount(), Money::ROUNDING));
    }

    /** How that total reads, in the schedule's own currency. */
    public function formattedTotal(): string
    {
        return Money::format($this->estimatedTotal(), $this->currency);
    }

    /* The rhythm ------------------------------------------------------------- */

    public function cadenceLabel(): string
    {
        return self::CADENCES[$this->cadence] ?? $this->cadence;
    }

    /**
     * The occurrence after a given one.
     *
     * `…NoOverflow` throughout: `addMonth()` on 31 January lands on 3 March,
     * which is how a monthly retainer quietly skips February. The preferred day
     * is then reapplied, clamped to the length of the month it lands in, so a
     * schedule set to the 31st bills on the 28th in February and on the 31st
     * again in March.
     */
    public function advanceFrom(Carbon $from): Carbon
    {
        $next = match ($this->cadence) {
            'weekly' => $from->copy()->addWeek(),
            'quarterly' => $from->copy()->addMonthsNoOverflow(3),
            'yearly' => $from->copy()->addYearsNoOverflow(1),
            default => $from->copy()->addMonthNoOverflow(),
        };

        return $this->onPreferredDay($next);
    }

    private function onPreferredDay(Carbon $date): Carbon
    {
        if ($this->day_of_month === null || $this->cadence === 'weekly') {
            return $date;
        }

        return $date->copy()->day(min($this->day_of_month, $date->daysInMonth));
    }

    /**
     * The stem of every invoice number this schedule raises.
     *
     * The generator completes it with the occurrence date, which makes the
     * number the natural key of an occurrence: a re-run lands on the row that
     * is already there instead of raising a second invoice for the same period.
     */
    public function numberPrefix(): string
    {
        return 'INV-R'.$this->getKey().'-';
    }

    public function numberFor(Carbon $occurrence): string
    {
        return $this->numberPrefix().$occurrence->format('Ymd');
    }

    /**
     * A decimal string, or the fallback when the value is not one.
     *
     * Livewire hands form input back as a string and a half-typed one is
     * ordinary. Rejecting it here keeps `BigDecimal` from throwing on '1.' or
     * on an empty box, and keeps a float from being conjured to paper over it.
     */
    public static function decimal(mixed $value, string $fallback = '0'): string
    {
        $value = trim((string) $value);

        return preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? $value : $fallback;
    }

    protected static function newFactory(): RecurringInvoiceFactory
    {
        return RecurringInvoiceFactory::new();
    }
}
