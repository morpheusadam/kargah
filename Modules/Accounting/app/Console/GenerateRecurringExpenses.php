<?php

namespace Modules\Accounting\Console;

use Brick\Money\Money as BrickMoney;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\RecurringExpense;
use Modules\Accounting\Services\ExchangeRates;
use Modules\Accounting\Services\InvoiceIssuer;
use Modules\Accounting\Support\Money;

/**
 * Record the expenses that standing commitments have come due for.
 *
 * The twin of `GenerateRecurringInvoices`, and written to read like it. Where
 * it deviates it says so, because the two jobs answer the same question in
 * opposite directions and a reader should be able to hold both at once.
 *
 * **Three deviations, all deliberate:**
 *
 * 1. 🔴 **This writes a real expense, not a draft.** The invoice side stops at a
 *    draft because issuing freezes a rate against a client, which is a decision
 *    a person makes with the document in front of them. Nothing equivalent is
 *    being decided here: the hosting bill left the account whether or not
 *    anybody opened Kargah that morning, and an expense parked as a draft is the
 *    forgotten cost that quietly flatters a profit figure — the exact thing this
 *    feature exists to stop.
 *
 * 2. 🔴 **Each expense freezes its own reporting rate, on its own date.** Not
 *    the schedule's — the schedule holds no rate at all — and not today's when
 *    catching up. The rule and the code are lifted from `⚡expense-edit`, down to
 *    reading the currency from `InvoiceIssuer::reportingCurrency()`, so a
 *    generated expense and a typed one are indistinguishable once written. When
 *    no rate is on file for that date the figure is left null and the run says
 *    how many, exactly as the form does: an expense that is counted out loud as
 *    unconverted is worth far more than no expense at all, and infinitely more
 *    than one converted at a rate nobody could defend.
 *
 * 3. 🔴 **This posts nothing to the ledger, because a typed expense posts
 *    nothing to the ledger.** No application code in Kargah writes a
 *    `LedgerEntry::TYPE_EXPENSE` row — only the seeder and the factory do. Making
 *    the generated ones post would mean a cost recorded by cron moved a balance
 *    that the same cost typed by hand did not, and a balance that depends on
 *    *how* a row was created is worse than one that is consistently incomplete.
 *    If expenses ever start posting, they start posting from one place and this
 *    follows it. `⚡expense-edit::delete()` already reverses any entry it finds,
 *    so nothing here has to be revisited when that day comes.
 *
 * **Running it twice on the same day records nothing the second time.** The
 * occurrence is claimed by a *conditional* update — `next_run_on` is advanced
 * only if it still holds the date being claimed — and the claim and the insert
 * share one transaction. Two processes racing on the same schedule therefore
 * produce one expense: the loser's update matches zero rows and it writes
 * nothing. That is the claim pattern the rest of Kargah uses (see
 * `Modules\Social\Console\PublishDue` and `CampaignSender`).
 *
 * 🔴 **The invoice side's second mechanism has no equivalent here, on purpose.**
 * There it is the invoice *number*: `INV-R7-20260901` is the only number the
 * seventh schedule can produce for that date, so a duplicate is impossible even
 * if the claim failed. An expense has no number and no column pointing back at
 * its schedule, so the same trick would need either a new column on `expenses`
 * — which this task was not asked to add — or a guess-by-shape ("same vendor,
 * same amount, same day"), which would silently swallow a genuine second
 * payment to the same vendor on the same day, and would collide between two
 * schedules for two identical servers. A wrong suppression is a cost that
 * vanishes, which is the failure this feature exists to prevent. So the claim
 * was made atomic instead of adding a second, weaker guard on top of a
 * non-atomic one.
 *
 * A schedule that has been missed for months catches up in one run, one expense
 * per occurrence it owed, each dated to *its* occurrence rather than to today —
 * otherwise a year of neglect would land entirely in this month's profit and
 * loss. `MAX_CATCH_UP` is a runaway guard, not a policy: if it trips, the next
 * run picks up where this one stopped.
 */
class GenerateRecurringExpenses extends Command
{
    protected $signature = 'accounting:generate-recurring-expenses {--on= : Run as if it were this date, for a backfill}';

    protected $description = 'Record an expense for every standing commitment that has come due';

    /** How many missed occurrences one run will catch up on for one schedule. */
    private const MAX_CATCH_UP = 60;

    public function __construct(private readonly ExchangeRates $rates)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $on = $this->on($this->option('on'));

        $schedules = RecurringExpense::query()->due($on)->orderBy('next_run_on')->orderBy('id')->get();

        if ($schedules->isEmpty()) {
            $this->components->info('Nothing is due on '.$on->toDateString().'.');

            return self::SUCCESS;
        }

        $recorded = 0;
        $unconverted = 0;

        foreach ($schedules as $schedule) {
            foreach ($this->generate($schedule, $on) as $expense) {
                $recorded++;

                if ($expense->reporting_amount === null) {
                    $unconverted++;
                }

                $this->components->info(
                    $expense->formattedAmount().' to '.$expense->vendor
                    .' on '.$expense->spent_on->format('j M Y')
                    .($expense->reporting_amount === null ? ' — no rate on file, reported figure left blank' : ''),
                );
            }
        }

        $this->components->info(
            $recorded === 0
                ? 'Every schedule that was due had already been recorded.'
                : 'Recorded '.$recorded.' '.str('expense')->plural($recorded).'.'
                    .($unconverted === 0
                        ? ''
                        : ' '.$unconverted.' of them could not be converted and '
                            .($unconverted === 1 ? 'carries' : 'carry').' no reporting figure.'),
        );

        return self::SUCCESS;
    }

    /**
     * Everything one schedule owes up to a date.
     *
     * Public because the recurring page's "record now" button runs exactly this
     * and must inherit exactly these guarantees — an impatient second click has
     * to be as harmless as a second cron run.
     *
     * @return list<Expense> the expenses this call actually created
     */
    public function generate(RecurringExpense $schedule, Carbon|string|null $on = null): array
    {
        $on = $this->on($on);

        $recorded = [];
        $attempts = 0;

        while ($schedule->isDue($on) && $attempts < self::MAX_CATCH_UP) {
            $attempts++;

            $expense = $this->record($schedule, $schedule->next_run_on->copy());

            if ($expense === null) {
                // The occurrence went to somebody else — a concurrent run, or a
                // click that landed while this loop was mid-flight. Stop rather
                // than spin: the in-memory schedule is refreshed by `claim()`
                // only on the path that won.
                break;
            }

            $recorded[] = $expense;
        }

        return $recorded;
    }

    /**
     * One occurrence: the expense and the date moving on, in one transaction.
     *
     * The claim comes first and the insert second, so a failure anywhere rolls
     * back both. An expense written without the date advancing would be recorded
     * again on the next run; a date advanced without the expense would silently
     * skip a month's cost, which is the harder of the two to ever notice.
     */
    private function record(RecurringExpense $schedule, Carbon $occurrence): ?Expense
    {
        return DB::transaction(function () use ($schedule, $occurrence): ?Expense {
            if (! $this->claim($schedule, $occurrence)) {
                return null;
            }

            $amount = $schedule->amountMoney();

            // Read once and written onto the row, rather than read again where
            // it is used: a generated expense has to be able to say which
            // currency its figure is in even when the rate lookup came back
            // empty, and a second read could in principle see a different
            // setting mid-run.
            $reportingCurrency = InvoiceIssuer::reportingCurrency();

            [$rate, $reportingAmount] = $this->reportingFigures($schedule, $amount, $occurrence, $reportingCurrency);

            return Expense::query()->create([
                'company_id' => $schedule->company_id,
                'vendor' => $schedule->vendor,
                'category' => $schedule->category,
                'description' => $schedule->description,
                'currency' => $schedule->currency,
                'amount' => Money::toStorage($amount),

                'reporting_currency' => $reportingCurrency,
                'reporting_rate' => $rate,
                'reporting_amount' => $reportingAmount,

                // Recoverable if the schedule says so, and never already
                // rebilled: a cost the client agreed to cover stays unbilled
                // until an invoice actually carries it, and that gap is the
                // money most easily forgotten.
                'is_billable' => $schedule->is_billable,
                'rebilled_on_invoice_id' => null,

                // The date the money is *for*, not the date the job ran. A
                // catch-up run that stamped everything with today would move a
                // quarter's costs into this quarter's profit and loss.
                'spent_on' => $occurrence->toDateString(),

                // Deliberately blank. The receipt arrives by email after the
                // charge, and a reference copied from the schedule would be the
                // same string every month — which is worse than an empty field,
                // because it looks like a real one.
                'receipt_reference' => null,

                'created_by' => $schedule->created_by,
            ]);
        });
    }

    /**
     * Take the occurrence, or find that somebody else already has.
     *
     * One conditional UPDATE, and the whole of the idempotency story. The
     * `next_run_on` in the WHERE clause is the value being claimed, so exactly
     * one caller can move it — a second run, a second click, or a second
     * process all match zero rows and are told to write nothing.
     */
    private function claim(RecurringExpense $schedule, Carbon $occurrence): bool
    {
        $claimed = RecurringExpense::query()
            ->whereKey($schedule->getKey())
            ->whereDate('next_run_on', $occurrence->toDateString())
            ->update([
                'last_run_on' => $occurrence->toDateString(),
                'next_run_on' => $schedule->advanceFrom($occurrence)->toDateString(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        // The in-memory schedule still holds the old date; the catch-up loop
        // reads `next_run_on` off it on the very next pass.
        $schedule->refresh();

        return true;
    }

    /**
     * The reporting figure, frozen at the date the money left.
     *
     * Identical in rule and in shape to `⚡expense-edit::reportingFigures()`, so
     * a generated expense and a typed one carry the same provenance —
     * `RecurringExpensesTest` compares the two field for field. It is duplicated
     * rather than shared because that method lives on the anonymous class inside
     * a single-file Livewire component and nothing outside that file can call
     * it; the right fix is one service both call, and that means editing a
     * component this task does not own.
     *
     * The currency comes from `InvoiceIssuer::reportingCurrency()`, the same
     * place an invoice reads it at issue, so cron cannot report costs in a
     * different currency from the one the form and the invoices use. There is
     * no frozen currency to preserve on this path: every expense here is being
     * created for the first time, and the no-backfill rule the form obeys when
     * *editing* has nothing to bite on.
     *
     * 🔴 `rateFor()` inverts a stored pair but will not chain two, and it asks
     * for the `market` rate, so TCMB's buying and selling rows are not what this
     * reads. With lira as the reporting currency a lira schedule is the identity
     * and a dollar one needs Frankfurter's USD/TRY row for that date. USDT/TRY
     * is fetched only as the optional lira leg of the CoinGecko call, so a
     * stablecoin schedule converts on the days that leg was quoted and freezes
     * nothing on the days it was not. Either way the expense is recorded and the
     * run says how many came out unconverted.
     *
     * @return array{0: ?string, 1: ?string} the rate and the converted amount, both null when no rate exists
     */
    private function reportingFigures(
        RecurringExpense $schedule,
        BrickMoney $amount,
        Carbon $occurrence,
        string $reportingCurrency,
    ): array {
        if ($schedule->currency === $reportingCurrency) {
            return ['1.000000', Money::toStorage($amount)];
        }

        $rate = $this->rates->rateFor($schedule->currency, $reportingCurrency, $occurrence->toDateString());

        if ($rate === null) {
            // Nothing to defend a converted number with, so there is no
            // converted number. The row still says the amount and its currency,
            // and the run says how many rows came out this way.
            return [null, null];
        }

        return [$rate, Money::toStorage(Money::convert($amount, $rate, $reportingCurrency))];
    }

    private function on(Carbon|string|null $date): Carbon
    {
        if ($date === null || $date === '') {
            return today();
        }

        return $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse($date)->startOfDay();
    }
}
