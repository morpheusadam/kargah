<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\LedgerEntry;
use Modules\Accounting\Models\RecurringExpense;
use Modules\Accounting\Services\ExchangeRates;
use Modules\Accounting\Support\Currencies;
use Modules\Core\Models\Company;
use Tests\TestCase;

/**
 * Standing costs: hosting, domains, a design tool, the accountant's fee.
 *
 * What is asserted here is not "the command exited zero" but the four things
 * that make a scheduled money job trustworthy: it records the right amount on
 * the right date, it freezes a rate of its own, it cannot be made to record the
 * same period twice, and deleting the schedule leaves the money it already
 * recorded alone.
 *
 * The rate figures are the same ones `AccountingPagesTest` uses for a typed
 * expense — 40 lira to the dollar, so a $100 cost reports as ₺4,000 at
 * 40.000000 — precisely so that a generated expense and a typed one can be
 * compared line for line, which
 * `test_a_generated_expense_and_a_typed_one_carry_identical_reporting_fields`
 * then does directly.
 */
class RecurringExpensesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function schedule(array $attributes = []): RecurringExpense
    {
        return RecurringExpense::query()->create($attributes + [
            'vendor' => 'Hetzner',
            'category' => 'Hosting',
            'description' => 'The box everything runs on',
            'currency' => Currencies::USD,
            'amount' => '32.000000',
            'is_billable' => false,
            'cadence' => 'monthly',
            'day_of_month' => null,
            'next_run_on' => today()->toDateString(),
            'is_active' => true,
        ]);
    }

    /* Recording ------------------------------------------------------------------ */

    public function test_a_due_schedule_records_exactly_one_expense_and_inherits_the_template(): void
    {
        $company = Company::factory()->create();

        $schedule = $this->schedule([
            'company_id' => $company->id,
            'vendor' => 'Figma',
            'category' => 'Software',
            'amount' => '45.000000',
            'is_billable' => true,
        ]);

        $this->artisan('accounting:generate-recurring-expenses')->assertExitCode(0);

        $this->assertSame(1, Expense::query()->count());

        $expense = Expense::query()->firstOrFail();

        $this->assertSame('Figma', $expense->vendor);
        $this->assertSame('Software', $expense->category);
        $this->assertSame('The box everything runs on', $expense->description);
        $this->assertSame('45.000000', (string) $expense->amount);
        $this->assertSame(Currencies::USD, $expense->currency);
        $this->assertSame($company->id, $expense->company_id);
        $this->assertSame(today()->toDateString(), $expense->spent_on->toDateString());

        // Recoverable if the schedule says so, and never already rebilled: the
        // gap between the two is the money most easily forgotten.
        $this->assertTrue($expense->is_billable);
        $this->assertNull($expense->rebilled_on_invoice_id);

        // And the schedule moved on.
        $this->assertSame(today()->toDateString(), $schedule->fresh()->last_run_on->toDateString());
        $this->assertSame(today()->addMonthNoOverflow()->toDateString(), $schedule->fresh()->next_run_on->toDateString());
    }

    /**
     * The reporting figure is the schedule's whole reason for being trustworthy.
     *
     * It is frozen on the day the expense is recorded, from the rate table, and
     * the schedule itself holds no rate at all — otherwise a subscription set up
     * in January would report every month of the year at January's lira.
     */
    public function test_a_generated_expense_freezes_a_reporting_rate_of_its_own(): void
    {
        app(ExchangeRates::class)->record(Currencies::USD, Currencies::TRY, '40.000000', 'frankfurter', today());

        $this->schedule([
            'vendor' => 'Hetzner',
            'category' => 'Hosting',
            'currency' => Currencies::USD,
            'amount' => '100.000000',
        ]);

        $this->artisan('accounting:generate-recurring-expenses')->assertExitCode(0);

        $expense = Expense::query()->firstOrFail();

        $this->assertSame('100.000000', (string) $expense->amount);
        $this->assertSame(Currencies::TRY, $expense->reporting_currency);
        $this->assertSame('40.000000', (string) $expense->reporting_rate);
        $this->assertSame('4000.000000', (string) $expense->reporting_amount);

        // And it stays put when the market moves.
        app(ExchangeRates::class)->record(Currencies::USD, Currencies::TRY, '50.000000', 'frankfurter', today()->addDay());

        $this->assertSame('4000.000000', (string) $expense->fresh()->reporting_amount);
    }

    /**
     * 🔴 A cost recorded by cron and the same cost typed into the form are the
     * same row.
     *
     * The two reporting figures are computed by two methods in two files —
     * `GenerateRecurringExpenses::reportingFigures()` and
     * `⚡expense-edit::reportingFigures()` — because a Livewire single-file
     * component cannot be called from outside itself. Duplicated code drifts,
     * and the drift here would be a book where a cost means one thing if a
     * person typed it and another if cron did. This is the test that stops it,
     * so compare the fields rather than trusting the comment that says they
     * match.
     */
    public function test_a_generated_expense_and_a_typed_one_carry_identical_reporting_fields(): void
    {
        $spentOn = today()->toDateString();

        app(ExchangeRates::class)->record(Currencies::USD, Currencies::TRY, '40.000000', 'frankfurter', $spentOn);

        $this->schedule([
            'vendor' => 'Hetzner',
            'category' => 'Hosting',
            'currency' => Currencies::USD,
            'amount' => '32.000000',
        ]);

        $this->artisan('accounting:generate-recurring-expenses')->assertExitCode(0);

        $generated = Expense::query()->firstOrFail();

        Livewire::test('accounting::expense-edit')
            ->set('vendor', 'Hetzner')
            ->set('category', 'Hosting')
            ->set('amount', '32.00')
            ->set('currency', Currencies::USD)
            ->set('spentOn', $spentOn)
            ->call('save');

        $typed = Expense::query()->whereKeyNot($generated->id)->firstOrFail();

        $this->assertSame((string) $generated->amount, (string) $typed->amount);
        $this->assertSame($generated->reporting_currency, $typed->reporting_currency);
        $this->assertSame((string) $generated->reporting_rate, (string) $typed->reporting_rate);
        $this->assertSame((string) $generated->reporting_amount, (string) $typed->reporting_amount);

        // Not merely equal to each other — equal to the right thing. Two
        // methods that both stopped converting would agree perfectly.
        $this->assertSame(Currencies::TRY, $generated->reporting_currency);
        $this->assertSame('1280.000000', (string) $generated->reporting_amount);
    }

    /**
     * "An expense with no reporting figure that is counted out loud is better
     * than no expense at all."
     *
     * A dollar cost with no USD/TRY rate on file for its date: that is the pair
     * the job has to look up now that lira is the reporting currency, and a
     * missing rate must not stop the cost being recorded.
     */
    public function test_an_occurrence_with_no_rate_on_file_is_still_recorded_with_no_reporting_figure(): void
    {
        $this->schedule([
            'currency' => Currencies::USD,
            'amount' => '2500.000000',
        ]);

        $this->artisan('accounting:generate-recurring-expenses')->assertExitCode(0);

        $expense = Expense::query()->firstOrFail();

        $this->assertSame('2500.000000', (string) $expense->amount);
        $this->assertNull($expense->reporting_rate, 'A rate was invented for a date with no rate on file.');
        $this->assertNull($expense->reporting_amount);
        // The cost still left the account, so the row still says what it is in.
        $this->assertSame(Currencies::TRY, $expense->reporting_currency);
    }

    /**
     * The hard requirement for every job in this project.
     *
     * Cron misses runs and cron doubles runs. Doubling a cost overstates what
     * the business spends and understates what it made, and neither figure has
     * anything on it to say a duplicate is why.
     */
    public function test_running_the_command_twice_in_a_day_records_one_expense_not_two(): void
    {
        $schedule = $this->schedule();

        $this->artisan('accounting:generate-recurring-expenses')->assertExitCode(0);

        $afterFirst = Expense::query()->count();

        // Asserted before anything is read off the schedule: if the occurrence
        // is not being claimed, the catch-up loop runs to `MAX_CATCH_UP` and
        // this is the line that says so.
        $this->assertSame(1, $afterFirst);

        $nextRun = $schedule->fresh()->next_run_on->toDateString();
        $lastRun = $schedule->fresh()->last_run_on->toDateString();

        // The same day, again.
        $this->artisan('accounting:generate-recurring-expenses')->assertExitCode(0);

        $this->assertSame($afterFirst, Expense::query()->count(), 'A second run recorded the same cost twice.');
        $this->assertSame($nextRun, $schedule->fresh()->next_run_on->toDateString());
        $this->assertSame($lastRun, $schedule->fresh()->last_run_on->toDateString());
    }

    public function test_a_paused_schedule_records_nothing(): void
    {
        $schedule = $this->schedule(['is_active' => false]);

        $this->artisan('accounting:generate-recurring-expenses')->assertExitCode(0);

        $this->assertSame(0, Expense::query()->count());
        $this->assertSame(today()->toDateString(), $schedule->fresh()->next_run_on->toDateString());
        $this->assertNull($schedule->fresh()->last_run_on);
    }

    /* The rhythm ------------------------------------------------------------------ */

    /**
     * A bill due on the 31st, in a February that has no 31st.
     *
     * `addMonth()` on 31 January lands on 3 March, which is how a monthly
     * subscription quietly skips a month. The clamp must also not stick: after
     * landing on the 28th, March has to go back to the 31st.
     */
    public function test_next_run_on_advances_and_a_month_end_bill_clamps_without_sticking(): void
    {
        $schedule = $this->schedule([
            'day_of_month' => 31,
            'next_run_on' => '2026-01-31',
        ]);

        $this->artisan('accounting:generate-recurring-expenses', ['--on' => '2026-01-31'])->assertExitCode(0);

        $this->assertSame('2026-02-28', $schedule->fresh()->next_run_on->toDateString());
        $this->assertSame('2026-01-31', Expense::query()->firstOrFail()->spent_on->toDateString());

        $this->artisan('accounting:generate-recurring-expenses', ['--on' => '2026-02-28'])->assertExitCode(0);

        $this->assertSame(
            '2026-03-31',
            $schedule->fresh()->next_run_on->toDateString(),
            'February clamped the day and then kept it, so the bill moved to the 28th forever.',
        );
    }

    /**
     * A schedule nobody has run for three months.
     *
     * One expense per occurrence, each dated to *its* occurrence. Stamping them
     * all with today would move a quarter of costs into this month's profit and
     * loss, which is the report the whole feature exists to keep honest.
     */
    public function test_a_missed_schedule_catches_up_one_expense_per_occurrence_each_on_its_own_date(): void
    {
        $this->schedule(['next_run_on' => '2026-01-15']);

        $this->artisan('accounting:generate-recurring-expenses', ['--on' => '2026-04-01'])->assertExitCode(0);

        $this->assertSame(
            ['2026-01-15', '2026-02-15', '2026-03-15'],
            Expense::query()->orderBy('spent_on')->pluck('spent_on')
                ->map(fn ($date): string => $date->toDateString())->all(),
        );
    }

    /* The ledger -------------------------------------------------------------------- */

    /**
     * A generated expense posts nothing, because a typed expense posts nothing.
     *
     * No application code in Kargah writes a `TYPE_EXPENSE` entry — only the
     * seeder and the factory do. Making the generated ones post would mean a
     * cost recorded by cron moved a balance that the same cost typed by hand did
     * not. This test pins the decision so that changing it is deliberate.
     */
    public function test_a_generated_expense_posts_nothing_to_the_ledger(): void
    {
        $this->schedule();

        $this->artisan('accounting:generate-recurring-expenses')->assertExitCode(0);

        $this->assertSame(1, Expense::query()->count());
        $this->assertSame(0, LedgerEntry::query()->count());
    }

    /* The page ---------------------------------------------------------------------- */

    public function test_a_cost_schedule_created_on_the_page_is_stored_and_records_what_it_says(): void
    {
        Livewire::test('accounting::recurring')
            ->call('openCostForm')
            ->set('costVendor', 'Namecheap')
            ->set('costCategory', 'Domains')
            ->set('costCurrency', Currencies::USD)
            ->set('costAmount', '14.50')
            ->set('costCadence', 'yearly')
            ->set('costNextRunOn', today()->toDateString())
            ->call('saveCostSchedule')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $schedule = RecurringExpense::query()->where('vendor', 'Namecheap')->firstOrFail();

        $this->assertSame('14.500000', (string) $schedule->amount);
        $this->assertSame('$14.50', $schedule->formattedAmount());
        // Yearly, so a year of it is one of it.
        $this->assertSame('$14.50', $schedule->formattedAnnualised());

        $this->artisan('accounting:generate-recurring-expenses')->assertExitCode(0);

        $this->assertSame('14.500000', (string) Expense::query()->firstOrFail()->amount);
        $this->assertSame(
            today()->addYearsNoOverflow(1)->toDateString(),
            $schedule->fresh()->next_run_on->toDateString(),
        );
    }

    public function test_recording_twice_from_the_page_records_one_expense(): void
    {
        $schedule = $this->schedule(['next_run_on' => today()->addDays(10)->toDateString()]);

        $page = Livewire::test('accounting::recurring');

        $page->call('recordNow', $schedule->id)->assertDispatched('toast');
        $page->call('recordNow', $schedule->id)->assertDispatched('toast');

        $this->assertSame(
            1,
            Expense::query()->count(),
            'An impatient second click recorded the same cost twice.',
        );
    }

    public function test_the_page_refuses_to_record_a_paused_schedule(): void
    {
        $schedule = $this->schedule(['is_active' => false]);

        Livewire::test('accounting::recurring')
            ->call('recordNow', $schedule->id)
            ->assertDispatched('toast');

        $this->assertSame(0, Expense::query()->count());
    }

    /**
     * The money that already left does not leave the book with the schedule.
     *
     * A cancelled subscription does not make last month's payment for it untrue,
     * and nothing about the expense may change: not deleted, not detached, not
     * reversed.
     */
    public function test_deleting_a_schedule_leaves_the_expenses_it_recorded_alone(): void
    {
        $schedule = $this->schedule();

        $this->artisan('accounting:generate-recurring-expenses')->assertExitCode(0);

        $expense = Expense::query()->firstOrFail();

        Livewire::test('accounting::recurring')
            ->call('deleteCostSchedule', $schedule->id)
            ->assertDispatched('toast');

        $this->assertNull(RecurringExpense::query()->find($schedule->id), 'The schedule was not removed.');
        $this->assertNotNull(
            RecurringExpense::withTrashed()->find($schedule->id),
            'The schedule was hard-deleted; a standing cost is soft-deleted so its history stays readable.',
        );

        $fresh = Expense::query()->find($expense->id);

        $this->assertNotNull($fresh, 'Deleting the schedule took its expenses with it. That money really left.');
        $this->assertSame((string) $expense->amount, (string) $fresh->amount);
        $this->assertNull($fresh->deleted_at);
    }

    public function test_pausing_a_schedule_from_the_page_stops_the_job_recording_it(): void
    {
        $schedule = $this->schedule();

        Livewire::test('accounting::recurring')
            ->call('toggleCostSchedule', $schedule->id)
            ->assertDispatched('toast');

        $this->assertFalse($schedule->fresh()->is_active);

        $this->artisan('accounting:generate-recurring-expenses')->assertExitCode(0);

        $this->assertSame(0, Expense::query()->count());
    }

    /**
     * The one figure on this page that crosses a currency, and does not.
     *
     * A dollar subscription and a lira one get two numbers. Adding them needs a
     * rate, and a rate needs a date and a source before anybody could argue with
     * the result — so the card lists them side by side instead.
     */
    public function test_the_yearly_cost_is_one_figure_per_currency_and_never_added_across_them(): void
    {
        $this->schedule(['vendor' => 'Hetzner', 'currency' => Currencies::USD, 'amount' => '10.000000']);
        $this->schedule([
            'vendor' => 'Beşiktaş muhasebeci',
            'currency' => Currencies::TRY,
            'amount' => '1000.000000',
            'cadence' => 'quarterly',
        ]);

        $yearly = collect(Livewire::test('accounting::recurring')->viewData('yearlyCost'))
            ->pluck('formatted', 'currency')
            ->all();

        $this->assertCount(2, $yearly, 'Two currencies were merged into one figure.');
        $this->assertSame('$120.00', $yearly[Currencies::USD]);
        $this->assertSame('₺4,000.00', $yearly[Currencies::TRY]);
    }

    /**
     * A paused schedule is not a commitment.
     *
     * It is excluded from the yearly figure deliberately: a cancelled
     * subscription that is still on the page as a reminder must not go on
     * costing money in the only number anybody reads off this card.
     */
    public function test_a_paused_schedule_is_left_out_of_the_yearly_cost(): void
    {
        $this->schedule(['vendor' => 'Hetzner', 'amount' => '10.000000']);
        $this->schedule(['vendor' => 'Figma', 'amount' => '45.000000', 'is_active' => false]);

        $yearly = collect(Livewire::test('accounting::recurring')->viewData('yearlyCost'))
            ->pluck('formatted', 'currency')
            ->all();

        $this->assertSame(['USD' => '$120.00'], $yearly);
    }

    /**
     * Both halves of the page draw, on an empty database and with rows.
     *
     * The page is the one place a recurring invoice and a recurring cost are
     * shown together, and a page that needs a row to exist before it can draw
     * itself is broken for the first person who ever opens it.
     */
    public function test_the_page_shows_standing_costs_alongside_the_invoice_schedules(): void
    {
        $this->get('/accounting/recurring')->assertOk()->assertSee('No standing costs yet', false);

        $this->schedule(['vendor' => 'Porkbun', 'category' => 'Domains']);

        $this->get('/accounting/recurring')
            ->assertOk()
            ->assertSee('Porkbun')
            // Still the invoice half's own empty state: one page, two lists.
            ->assertSee('No recurring schedules yet', false);
    }
}
