<?php

namespace Modules\Accounting\Console;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\InvoiceLine;
use Modules\Accounting\Models\RecurringInvoice;
use Modules\Accounting\Services\InvoiceIssuer;
use Modules\Accounting\Support\Money;

/**
 * Raise the drafts that recurring schedules are due to produce.
 *
 * **Drafts, never issued invoices.** Issuing freezes an exchange rate onto the
 * row and from then on the numbers cannot change; that is a decision a person
 * makes with the invoice in front of them, not one a cron job makes at nine in
 * the morning. The job puts the paperwork on the desk and stops.
 *
 * **Running it twice on the same day raises nothing the second time.** Two
 * independent mechanisms, because a scheduled job that double-bills a client is
 * the kind of bug that costs a relationship:
 *
 *   1. The occurrence date is claimed. `next_run_on` moves forward inside the
 *      same transaction that writes the draft, so a second pass finds nothing
 *      due.
 *   2. The invoice number *is* the occurrence. `INV-R7-20260901` is the only
 *      number the seventh schedule can produce for 1 September 2026, so even a
 *      schedule whose date somehow failed to advance cannot raise a second
 *      invoice for the same period.
 *
 * A schedule that has been missed for weeks catches up in one run, one draft
 * per occurrence it owed. `MAX_CATCH_UP` is a runaway guard, not a policy: if
 * it ever trips, the remaining occurrences are raised by the next run, and the
 * numbering still makes a duplicate impossible.
 */
class GenerateRecurringInvoices extends Command
{
    protected $signature = 'accounting:generate-recurring {--on= : Run as if it were this date, for a backfill}';

    protected $description = 'Raise draft invoices for every recurring schedule that has come due';

    /** How many missed occurrences one run will catch up on for one schedule. */
    private const MAX_CATCH_UP = 60;

    /** How long a generated draft gives the client to pay. */
    private const PAYMENT_DAYS = 30;

    public function __construct(private readonly InvoiceIssuer $issuer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $on = $this->on($this->option('on'));

        $schedules = RecurringInvoice::query()->due($on)->orderBy('next_run_on')->orderBy('id')->get();

        if ($schedules->isEmpty()) {
            $this->components->info('Nothing is due on '.$on->toDateString().'.');

            return self::SUCCESS;
        }

        $raised = 0;

        foreach ($schedules as $schedule) {
            foreach ($this->generate($schedule, $on) as $invoice) {
                $raised++;

                $this->components->info(
                    $invoice->number.' — '.$schedule->title.' — '
                    .$invoice->formattedTotal().' — draft, not issued',
                );
            }
        }

        $this->components->info(
            $raised === 0
                ? 'Every schedule that was due had already been raised.'
                : 'Raised '.$raised.' '.str('draft')->plural($raised).'. Nothing was issued.',
        );

        return self::SUCCESS;
    }

    /**
     * Everything one schedule owes up to a date.
     *
     * Public because the recurring page's "raise now" button runs exactly this
     * and must inherit exactly these guarantees — a second impatient click has
     * to be as harmless as a second cron run.
     *
     * @return list<Invoice> the drafts this call actually created
     */
    public function generate(RecurringInvoice $schedule, Carbon|string|null $on = null): array
    {
        $on = $this->on($on);

        $raised = [];
        $attempts = 0;

        while ($schedule->isDue($on) && $attempts < self::MAX_CATCH_UP) {
            $attempts++;

            $invoice = $this->raise($schedule, $schedule->next_run_on->copy());

            if ($invoice !== null) {
                $raised[] = $invoice;
            }
        }

        return $raised;
    }

    /**
     * One occurrence: the draft, its lines, and the date moving on.
     *
     * All three in one transaction. A draft written without the date advancing
     * would be raised again on the next run, and a date advanced without the
     * draft would silently skip a month's billing.
     */
    private function raise(RecurringInvoice $schedule, Carbon $occurrence): ?Invoice
    {
        return DB::transaction(function () use ($schedule, $occurrence): ?Invoice {
            $number = $schedule->numberFor($occurrence);

            // `withTrashed`, because the number column is unique across deleted
            // rows too. An occurrence whose draft was raised and then deleted
            // was still raised, and resurrecting it behind the owner's back
            // would be worse than skipping it.
            $already = Invoice::withTrashed()->where('number', $number)->exists();

            $this->advance($schedule, $occurrence);

            if ($already) {
                return null;
            }

            $invoice = Invoice::query()->create([
                'number' => $number,
                'company_id' => $schedule->company_id,
                'customer_id' => $schedule->customer_id,
                'status' => 'draft',
                'currency' => $schedule->currency,
                'tax_percent' => RecurringInvoice::decimal((string) $schedule->tax_percent, '0'),
                // The date the invoice is *for*. It is not issued: `sent_at` is
                // null, so `isIssued()` is false and the draft stays editable.
                'issued_on' => $occurrence->toDateString(),
                'due_on' => $occurrence->copy()->addDays(self::PAYMENT_DAYS)->toDateString(),
                'notes' => $schedule->notes,
                'terms' => $schedule->terms,
                'created_by' => $schedule->created_by,
            ]);

            $this->copyLines($schedule, $invoice);

            return $this->issuer->recalculate($invoice);
        });
    }

    private function copyLines(RecurringInvoice $schedule, Invoice $invoice): void
    {
        foreach ($schedule->templateLines() as $index => $line) {
            InvoiceLine::query()->create([
                'invoice_id' => $invoice->id,
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'amount' => Money::toStorage(
                    Money::lineTotal($line['quantity'], $line['unit_price'], $invoice->currency),
                ),
                // The same fractional ordering the boards use, spaced so a line
                // can be dropped between two others without renumbering.
                'position' => (string) BigDecimal::of('1024')
                    ->multipliedBy($index + 1)
                    ->toScale(10, RoundingMode::Down),
            ]);
        }
    }

    private function advance(RecurringInvoice $schedule, Carbon $occurrence): void
    {
        $schedule->forceFill([
            'last_run_on' => $occurrence->toDateString(),
            'next_run_on' => $schedule->advanceFrom($occurrence)->toDateString(),
        ])->save();

        $schedule->refresh();
    }

    private function on(Carbon|string|null $date): Carbon
    {
        if ($date === null || $date === '') {
            return today();
        }

        return $date instanceof Carbon ? $date->copy()->startOfDay() : Carbon::parse($date)->startOfDay();
    }
}
