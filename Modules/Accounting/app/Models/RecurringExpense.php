<?php

namespace Modules\Accounting\Models;

use Brick\Money\Money as BrickMoney;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Accounting\Database\Factories\RecurringExpenseFactory;
use Modules\Accounting\Support\Money;
use Modules\Core\Models\Company;

/**
 * Hosting, a domain, a design tool, the accountant's monthly fee — money that
 * leaves on a rhythm.
 *
 * This is `RecurringInvoice` for the other direction, and it is written to look
 * like it on purpose: the same cadence vocabulary, the same `next_run_on` /
 * `last_run_on` pair, the same `is_active` pause, the same `advanceFrom()`
 * clamping rule. Anyone who has read one has read both.
 *
 * **Two deliberate differences from the invoice side**, both because an expense
 * is not an invoice:
 *
 *   1. **There is no template of lines and no tax.** An expense is one vendor,
 *      one amount, one date — that is what the `expenses` table holds, and a
 *      schedule that offered more than the thing it creates would be lying.
 *   2. **It records a real expense, not a draft.** An invoice is held back as a
 *      draft because issuing freezes a rate against a client and that is a
 *      person's decision. Nobody decides that a hosting bill was paid: it left
 *      the account whether or not anyone opened Kargah that morning, and an
 *      expense sitting in a drawer marked "draft" is exactly the forgotten cost
 *      this feature exists to stop.
 *
 * **Nothing here carries a rate.** Every expense the generator records freezes
 * its own `reporting_rate` on its own date — see `GenerateRecurringExpenses`.
 *
 * 🔴 `advanceFrom()`, `onPreferredDay()` and `decimal()` are copied from
 * `RecurringInvoice` rather than shared. They should be one trait, and they are
 * not one yet because `RecurringInvoice` belongs to another task in this wave
 * and a silent edit to it would destroy that work. The two copies are
 * *identical* — if you change the rhythm rules in one, change them in both, or
 * better, extract `Modules\Accounting\Concerns\Schedulable` and delete both.
 */
class RecurringExpense extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The same four cadences the invoice side offers, read from it rather than
     * retyped: a page that offered "fortnightly" for costs and not for income
     * would be a bug nobody noticed for a year.
     */
    public const CADENCES = RecurringInvoice::CADENCES;

    /**
     * The categories offered before the database has any of its own.
     *
     * 🔴 A **second copy** of the list in `⚡expense-edit::CATEGORIES`. It is
     * copied rather than imported because that const lives on the anonymous
     * Livewire class inside a single-file component and nothing outside that
     * file can name it. The right home for the list is a const on `Expense`,
     * which both forms already write to — that file belongs to nobody in this
     * wave, so the consolidation is reported rather than done. Until then: a
     * category added to one list and not the other is offered on one form and
     * not the other, and neither form will say so.
     */
    public const CATEGORIES = [
        'Hosting', 'Software', 'Email', 'Domains', 'Hardware', 'Travel', 'Subcontractors', 'Other',
    ];

    /**
     * How many times a cadence is paid in a year.
     *
     * 52 for weekly, not 365.25 ÷ 7. A weekly commitment is paid 52 times in
     * most years and 53 in some, and the page says the yearly figure is an
     * estimate for exactly this reason. Integers, because they multiply money
     * and a float would be refused at the door.
     */
    public const PER_YEAR = [
        'weekly' => 52,
        'monthly' => 12,
        'quarterly' => 4,
        'yearly' => 1,
    ];

    protected $fillable = [
        'company_id', 'vendor', 'category', 'description',
        'currency', 'amount', 'is_billable',
        'cadence', 'day_of_month', 'next_run_on', 'last_run_on',
        'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            // decimal:N casts to a string, never a float.
            'amount' => 'decimal:6',
            'next_run_on' => 'date',
            'last_run_on' => 'date',
            'day_of_month' => 'integer',
            'is_billable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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

    /* The money ---------------------------------------------------------------- */

    public function amountMoney(): BrickMoney
    {
        return Money::fromStorage((string) $this->amount, $this->currency);
    }

    /** How the amount reads, in the currency it is actually paid in. */
    public function formattedAmount(): string
    {
        return Money::format((string) $this->amount, $this->currency);
    }

    /**
     * What this commitment costs in a year, in its own currency.
     *
     * An estimate and labelled as one wherever it is shown: 52 weeks is not
     * quite a year, a schedule that starts in November costs two months this
     * calendar year and twelve the next, and a price rise makes the figure
     * stale. It is still the single most useful number on the page — the whole
     * point of listing standing costs is seeing what they come to.
     *
     * **Never added across currencies.** The page groups by currency and shows
     * one figure each; summing them would need a rate, and a rate needs a date
     * and a source before anybody could argue with the result.
     */
    public function annualisedAmount(): BrickMoney
    {
        return $this->amountMoney()->multipliedBy(
            self::PER_YEAR[$this->cadence] ?? 12,
            Money::ROUNDING,
        );
    }

    public function formattedAnnualised(): string
    {
        return Money::format(Money::toStorage($this->annualisedAmount()), $this->currency);
    }

    /* The rhythm ---------------------------------------------------------------- */

    public function cadenceLabel(): string
    {
        return self::CADENCES[$this->cadence] ?? $this->cadence;
    }

    /**
     * The occurrence after a given one.
     *
     * `…NoOverflow` throughout: `addMonth()` on 31 January lands on 3 March,
     * which is how a monthly subscription quietly skips February. The preferred
     * day is then reapplied, clamped to the length of the month it lands in, so
     * a bill set to the 31st is recorded on the 28th in February and on the 31st
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
     * A decimal string, or the fallback when the value is not one.
     *
     * Livewire hands form input back as a string and a half-typed one is
     * ordinary. Rejecting it here keeps `BigDecimal` from throwing on '1.' or on
     * an empty box, and keeps a float from being conjured to paper over it.
     */
    public static function decimal(mixed $value, string $fallback = '0'): string
    {
        $value = trim((string) $value);

        return preg_match('/^-?\d+(\.\d+)?$/', $value) === 1 ? $value : $fallback;
    }

    protected static function newFactory(): RecurringExpenseFactory
    {
        return RecurringExpenseFactory::new();
    }
}
