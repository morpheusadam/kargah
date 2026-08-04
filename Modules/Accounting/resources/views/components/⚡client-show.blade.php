<?php

use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Accounting\Models\Expense;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Models\RecurringInvoice;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Core\Models\Customer;
use Modules\Mailbox\Contracts\EmailReader;
use Modules\Project\Contracts\CardReader;

/**
 * Client record.
 *
 * Everything about one client on one page: what they are worth, what they owe,
 * and every document either side has raised. The tab lives in the query string
 * so a link to "Northwind, invoices tab" is a link someone can actually send.
 *
 * Five things are worth knowing before changing anything.
 *
 * **Archive is the ordinary answer; delete is the rare one.** A client is shared
 * by four modules and named by financial records that have to outlive them, so
 * `delete()` refuses anything an invoice, a schedule, a card or a message still
 * points at, and says which. See its own docblock for why the one case it does
 * allow is a `forceDelete` rather than a soft one.
 *
 * **A client that is not there is not an error.** The id comes out of the URL,
 * so it may be a client that was deleted, or one that never existed. That gets
 * an empty state and a way back, not a stack trace.
 *
 * **Nothing is totalled in SQL.** A decimal column on SQLite is a double, so
 * `SUM(total)` is approximate. The invoices are fetched with their payments and
 * added through `Money`, which works on decimal strings.
 *
 * **Currencies are never mixed.** A client billed in lira and in dollars gets
 * two figures. Adding them would need a rate, and a rate needs a date and a
 * source before anybody can argue with the result.
 *
 * **The revenue chart is twelve plain divs** rather than ApexCharts — at this
 * size a sparkline is a bar per month, and that does not need 400 KB of
 * JavaScript. The bar *heights* are percentages, which are not money; the
 * amounts they are derived from are added through `Money::sum()`.
 */
new
#[Title('Client — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public string $clientId = '1';

    #[Url]
    public string $tab = 'overview';

    public string $draftNote = '';

    /**
     * Per-request memos. Private, so Livewire neither ships nor rehydrates
     * them. Without them the page resolves the same customer six times and
     * re-reads their invoice book for every panel that mentions a figure.
     */
    private ?Customer $resolvedCustomer = null;

    private ?Collection $resolvedInvoices = null;

    private ?Collection $resolvedExpenses = null;

    private ?Collection $resolvedCards = null;

    public function mount(string $client): void
    {
        $this->clientId = $client;

        // Resolved, not trusted. `find()` on a stranger's id gives null, and
        // null is a page that says so rather than a five hundred.
        if ($this->customer() === null) {
            $this->toastError('That client is not here', 'The record was deleted, or the link is wrong.');
        }
    }

    /**
     * The customer this page is about.
     *
     * The Projects tab reads real cards, because proving a card can be found
     * from the person it is for is what the Project phase was for. Everything
     * else on the page now reads the same way — real invoices, real expenses,
     * real notes.
     */
    private function customer(): ?Customer
    {
        return $this->resolvedCustomer ??= Customer::query()->find((int) $this->clientId);
    }

    /**
     * This client's cards, read across the module boundary by contract.
     *
     * Memoised: the Projects tab and the delete guard both want them, and
     * without this the page asks Project for the same cards twice per render.
     */
    private function cards(): Collection
    {
        if ($this->resolvedCards !== null) {
            return $this->resolvedCards;
        }

        $customer = $this->customer();

        return $this->resolvedCards = $customer === null
            ? collect()
            : app(CardReader::class)->forCustomer($customer->id);
    }

    /* Reading the books ------------------------------------------------------- */

    /**
     * Every invoice raised against this client, newest first, with payments.
     *
     * @return Collection<int, Invoice>
     */
    private function invoices(): Collection
    {
        if ($this->resolvedInvoices !== null) {
            return $this->resolvedInvoices;
        }

        $customer = $this->customer();

        if ($customer === null) {
            return $this->resolvedInvoices = collect();
        }

        return $this->resolvedInvoices = Invoice::query()
            ->where('customer_id', $customer->id)
            ->whereNull('voided_at')
            ->with('payments')
            ->orderByDesc('issued_on')
            ->orderByDesc('id')
            ->get();
    }

    /** Issued invoices only. A draft is a sentence someone is still writing. */
    private function issued(): Collection
    {
        return $this->invoices()->filter(fn (Invoice $invoice): bool => $invoice->isIssued());
    }

    /**
     * What has been spent against this client's company.
     *
     * An expense belongs to a company, not to a person: the seat, the staging
     * box and the train fare are the company's cost. A client with no company
     * has no expenses by definition, and that is an empty state rather than a
     * blank.
     *
     * @return Collection<int, Expense>
     */
    private function expenses(): Collection
    {
        if ($this->resolvedExpenses !== null) {
            return $this->resolvedExpenses;
        }

        $companyId = $this->customer()?->company_id;

        if ($companyId === null) {
            return $this->resolvedExpenses = collect();
        }

        return $this->resolvedExpenses = Expense::query()
            ->where('company_id', $companyId)
            ->with('rebilledOn')
            ->orderByDesc('spent_on')
            ->orderByDesc('id')
            ->get();
    }

    /* Money -------------------------------------------------------------------- */

    /**
     * What is still owed on one invoice, in its own currency.
     *
     * The rule `PaymentRecorder::outstanding()` states, read off the
     * eager-loaded relation rather than a fresh query per invoice.
     */
    private function outstandingOf(Invoice $invoice): BrickMoney
    {
        $paid = Money::sum(
            $invoice->payments->map(fn (Payment $payment): string => (string) $payment->applied_amount),
            $invoice->currency,
        );

        $owed = Money::fromStorage((string) $invoice->total, $invoice->currency)->minus($paid, Money::ROUNDING);

        return $owed->isNegative() ? Money::zero($invoice->currency) : $owed;
    }

    /**
     * One formatted total per currency, added in PHP.
     *
     * @param  Collection<int, Invoice>  $invoices
     * @param  callable(Invoice): BrickMoney  $amountOf
     */
    private function perCurrency(Collection $invoices, callable $amountOf): string
    {
        $figures = $invoices
            ->groupBy('currency')
            ->map(fn (Collection $group, string $currency): string => Money::format(
                Money::toStorage(Money::sum($group->map($amountOf), $currency)),
                $currency,
            ))
            ->values()
            ->all();

        return $figures === [] ? '—' : implode(' · ', $figures);
    }

    /**
     * The currency this client is mostly billed in.
     *
     * The chart draws one currency, because twelve bars in a mix of currencies
     * is a picture of nothing.
     */
    private function primaryCurrency(): string
    {
        $counts = $this->issued()->countBy('currency');

        if ($counts->isEmpty()) {
            return $this->customer()?->company?->default_currency ?? Currencies::USD;
        }

        return (string) $counts->sortDesc()->keys()->first();
    }

    /**
     * A bar height, as a whole percentage.
     *
     * Not money — a ratio of two amounts that are. The division therefore
     * happens on BigDecimal at a stated scale and comes out an integer, rather
     * than on two floats.
     */
    private function share(BrickMoney $part, BrickMoney $peak): int
    {
        if ($peak->isZero() || $part->isNegativeOrZero()) {
            return 0;
        }

        return $part->getAmount()
            ->dividedBy($peak->getAmount(), 4, RoundingMode::HalfUp)
            ->multipliedBy(100)
            ->toScale(0, RoundingMode::HalfUp)
            ->toInt();
    }

    /**
     * Twelve months of revenue in the client's main currency.
     *
     * @return array{months: list<array{month: string, label: string, height: int}>, currency: string, excluded: int}
     */
    private function revenue(): array
    {
        $currency = $this->primaryCurrency();
        $inCurrency = $this->issued()->filter(fn (Invoice $invoice): bool => $invoice->currency === $currency);

        $months = [];
        $totals = [];

        for ($back = 11; $back >= 0; $back--) {
            $start = now()->startOfMonth()->subMonths($back);

            $total = Money::sum(
                $inCurrency
                    ->filter(fn (Invoice $invoice): bool => $invoice->issued_on !== null
                        && $invoice->issued_on->isSameMonth($start))
                    ->map(fn (Invoice $invoice): string => (string) $invoice->total),
                $currency,
            );

            $months[] = $start->format('M');
            $totals[] = $total;
        }

        $peak = Money::zero($currency);

        foreach ($totals as $total) {
            if ($total->isGreaterThan($peak)) {
                $peak = $total;
            }
        }

        return [
            'months' => array_map(fn (string $month, BrickMoney $total): array => [
                'month' => $month,
                'label' => Money::format(Money::toStorage($total), $currency),
                'height' => $this->share($total, $peak),
            ], $months, $totals),
            'currency' => $currency,
            'excluded' => $this->issued()->count() - $inCurrency->count(),
        ];
    }

    /**
     * How long this client takes to pay, in whole days.
     *
     * Measured from the date on the document to the day the last payment
     * against it landed. Null when nothing has been paid yet — an average of no
     * invoices is not zero days, it is nothing to say.
     */
    private function averageDaysToPay(): ?int
    {
        $spans = $this->issued()
            ->filter(fn (Invoice $invoice): bool => $invoice->issued_on !== null && $invoice->payments->isNotEmpty())
            ->map(function (Invoice $invoice): int {
                $last = $invoice->payments->max('paid_at');

                return (int) $invoice->issued_on->startOfDay()->diffInDays(Carbon::parse($last)->startOfDay());
            })
            ->all();

        return $spans === [] ? null : intdiv(array_sum($spans), count($spans));
    }

    /* Notes --------------------------------------------------------------------- */

    /**
     * The notes column, read as a list.
     *
     * `customers.notes` is one text column, so an entry is stored as a dated
     * paragraph and entries are separated by a blank line. Anything already in
     * there without a date — a note the seeder wrote — reads as one undated
     * entry rather than being thrown away.
     *
     * @return list<array{at: ?string, body: string}>
     */
    private function notes(): array
    {
        $raw = trim((string) $this->customer()?->notes);

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map(function (string $entry): ?array {
            $entry = trim($entry);

            if ($entry === '') {
                return null;
            }

            if (preg_match('/^\[(\d{4}-\d{2}-\d{2})\]\s*(.*)$/s', $entry, $matches) === 1) {
                return ['at' => Carbon::parse($matches[1])->format('d M Y'), 'body' => trim($matches[2])];
            }

            return ['at' => null, 'body' => $entry];
        }, preg_split('/\R\s*\R/', $raw) ?: [])));
    }

    /* View ----------------------------------------------------------------------- */

    public function with(): array
    {
        $customer = $this->customer();
        $company = $customer?->company;

        $issued = $this->issued();
        $open = $issued->filter(fn (Invoice $invoice): bool => $this->outstandingOf($invoice)->isPositive());
        $thisYear = $this->paymentsThisYear();
        $days = $this->averageDaysToPay();

        return [
            'customer' => $customer,
            'company' => $company,
            'tabs' => [
                'overview' => 'Overview',
                'projects' => 'Projects',
                'invoices' => 'Invoices',
                'expenses' => 'Expenses',
                'notes' => 'Notes',
            ],
            /*
             * Whole class strings in a map, never built by concatenation —
             * Tailwind's scanner reads source as text and cannot see a class
             * that only exists once PHP has run.
             *
             * The five states come from `Card::dueState()` in Project. This
             * page reads them through `CardReader`'s array, so it does not get
             * to use Project's own `Palette`; the tones are chosen to match
             * what the board renders rather than to be independently pretty.
             */
            'dueTone' => [
                'done' => 'text-success',
                'overdue' => 'text-destructive',
                'due' => 'text-destructive',
                'soon' => 'text-warning',
                'later' => 'text-secondary-foreground',
            ],
            // Real, from Project, through its contract. Accounting may read a
            // card; it may not hold one — see Modules\Project\Contracts\CardReader.
            'cards' => $this->cards(),

            // Whether the delete offers a confirmation or only an explanation.
            // The card count here is the *active* one, because those cards are
            // already loaded for the Projects tab and asking Project a second
            // time on every render to catch an archived card is not worth a
            // query. `delete()` re-asks including the archive and is the
            // authority; the worst this optimism costs is a confirmation
            // followed by a refusal that names the card.
            'deletable' => $this->whatStillPointsAtThisClient($this->cards()->count()) === [],

            'stats' => [
                [
                    'label' => 'Lifetime value',
                    'value' => $this->perCurrency($issued, fn (Invoice $invoice): BrickMoney => Money::fromStorage((string) $invoice->total, $invoice->currency)),
                    'tone' => 'text-mono',
                ],
                [
                    'label' => 'Outstanding',
                    'value' => $this->perCurrency($open, fn (Invoice $invoice): BrickMoney => $this->outstandingOf($invoice)),
                    'tone' => $open->isEmpty() ? 'text-success' : 'text-warning',
                ],
                [
                    'label' => 'Paid this year',
                    'value' => $thisYear,
                    'tone' => 'text-success',
                ],
                [
                    'label' => 'Avg. days to pay',
                    'value' => $days === null ? '—' : (string) $days,
                    'tone' => 'text-mono',
                ],
            ],

            'revenue' => $this->revenue(),

            'invoices' => $issued->concat(
                $this->invoices()->reject(fn (Invoice $invoice): bool => $invoice->isIssued()),
            )->map(fn (Invoice $invoice): array => [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'issued' => $invoice->issued_on?->format('d M Y') ?? '—',
                'due' => $invoice->due_on?->format('d M Y') ?? '—',
                'total' => $invoice->formattedTotal(),
                'outstanding' => $this->outstandingOf($invoice)->isPositive()
                    ? Money::format(Money::toStorage($this->outstandingOf($invoice)), $invoice->currency)
                    : null,
                'status' => $this->badgeKey($invoice),
            ])->all(),

            'expenses' => $this->expenses()->map(fn (Expense $expense): array => [
                'id' => $expense->id,
                'date' => $expense->spent_on->format('d M Y'),
                'vendor' => $expense->vendor,
                'description' => $expense->description,
                'category' => $expense->category ?: 'Uncategorised',
                'amount' => $expense->formattedAmount(),
                'state' => $expense->isRebilled() ? 'rebilled' : ($expense->is_billable ? 'unbilled' : 'absorbed'),
                'rebilledOn' => $expense->rebilledOn?->number,
            ])->all(),

            'notes' => $this->notes(),

            'statusBadges' => [
                'draft' => ['label' => 'Draft', 'class' => 'kt-badge kt-badge-sm kt-badge-outline'],
                'sent' => ['label' => 'Sent', 'class' => 'kt-badge kt-badge-sm kt-badge-info'],
                'part_paid' => ['label' => 'Part paid', 'class' => 'kt-badge kt-badge-sm kt-badge-warning'],
                'paid' => ['label' => 'Paid', 'class' => 'kt-badge kt-badge-sm kt-badge-success'],
                'overdue' => ['label' => 'Overdue', 'class' => 'kt-badge kt-badge-sm kt-badge-destructive'],
                'void' => ['label' => 'Void', 'class' => 'kt-badge kt-badge-sm kt-badge-outline'],
            ],
            'expenseBadges' => [
                'rebilled' => ['label' => 'Rebilled', 'class' => 'kt-badge kt-badge-sm kt-badge-success'],
                'unbilled' => ['label' => 'Recoverable', 'class' => 'kt-badge kt-badge-sm kt-badge-warning'],
                'absorbed' => ['label' => 'Absorbed', 'class' => 'kt-badge kt-badge-sm kt-badge-outline'],
            ],
        ];
    }

    /**
     * Which badge an invoice wears.
     *
     * Overdue is not a stored status — it is a due date that has passed on an
     * invoice nobody has paid, so it is worked out rather than read. A status
     * the map has never heard of falls back to the plain outline badge instead
     * of taking the page down.
     */
    private function badgeKey(Invoice $invoice): string
    {
        if ($invoice->isOverdue()) {
            return 'overdue';
        }

        return in_array($invoice->status, ['draft', 'sent', 'part_paid', 'paid', 'void'], true)
            ? $invoice->status
            : 'draft';
    }

    /** What actually landed this calendar year, in the currency each invoice was raised in. */
    private function paymentsThisYear(): string
    {
        $figures = $this->issued()
            ->groupBy('currency')
            ->map(function (Collection $group, string $currency): BrickMoney {
                $applied = $group->flatMap(fn (Invoice $invoice): Collection => $invoice->payments)
                    ->filter(fn (Payment $payment): bool => $payment->paid_at !== null
                        && $payment->paid_at->greaterThanOrEqualTo(now()->startOfYear()))
                    ->map(fn (Payment $payment): string => (string) $payment->applied_amount);

                return Money::sum($applied, $currency);
            })
            ->reject(fn (BrickMoney $total): bool => $total->isZero())
            ->map(fn (BrickMoney $total, string $currency): string => Money::format(Money::toStorage($total), $currency))
            ->values()
            ->all();

        return $figures === [] ? '—' : implode(' · ', $figures);
    }

    /* Actions --------------------------------------------------------------------- */

    /** Stores the note against the client. One text column, one dated paragraph. */
    public function addNote(): void
    {
        $customer = $this->customer();

        if ($customer === null) {
            $this->toastError('That client is not here', 'Nothing was saved.');

            return;
        }

        $body = trim($this->draftNote);

        if ($body === '') {
            $this->toastError('The note is empty', 'Write what you want to remember, then add it.');

            return;
        }

        $existing = trim((string) $customer->notes);

        $customer->forceFill([
            'notes' => '['.now()->toDateString().'] '.$body.($existing === '' ? '' : "\n\n".$existing),
        ])->save();

        $this->draftNote = '';
        $this->resolvedCustomer = null;

        $this->toastSuccess('Note saved');
    }

    /** Hides the client from pickers without deleting the history, or puts them back. */
    public function archive(): void
    {
        $customer = $this->customer();

        if ($customer === null) {
            $this->toastError('That client is not here', 'Nothing was changed.');

            return;
        }

        $wasArchived = $customer->isArchived();

        $customer->forceFill(['archived_at' => $wasArchived ? null : now()])->save();

        $this->resolvedCustomer = null;

        $wasArchived
            ? $this->toastSuccess($customer->name.' restored', 'They are back on the active client list.')
            : $this->toastSuccess(
                $customer->name.' archived',
                'They are off the active list. Every invoice and note is untouched.',
            );
    }

    /**
     * Delete a client — but only one that nothing at all refers to.
     *
     * 🔴 **Archive is the right answer for almost every client, and this method
     * exists for the one case it is not.** A `customers` row is named by
     * `invoices.customer_id`, and an invoice carries **no snapshot** of who it
     * was for: no billing name, no address, nothing but the foreign key. Remove
     * the client and the invoice can no longer say whose it was, which is
     * strictly worse than a long client list. Every one of those columns is
     * `nullOnDelete`, so the database will happily do exactly that on request.
     *
     * The one case worth serving is the client typed in by mistake: wrong
     * spelling, wrong address, nothing raised against them. Archive does not
     * serve it, and the reason is `⚡clients`' `unique:customers,email` rule —
     * it counts a soft-deleted row, so an archived or soft-deleted typo holds
     * that email address hostage for ever and the client cannot be re-added.
     * Neither page offers an edit, so the address would simply be lost.
     *
     * 🔴 **Hence `forceDelete()`, not `delete()`.** A soft delete here would be
     * the worst of both: the row claims to be gone while still blocking its own
     * email address. It is only reached when nothing anywhere points at the
     * client, so a hard delete orphans nothing — and "delete" then means what
     * the confirmation says it means.
     *
     * What counts as "points at": an invoice in any state including soft-deleted
     * and voided (a number is never reused, so the document survives its own
     * deletion), a recurring schedule, a card on the boards, a message in the
     * mailbox. The last two are read through Project's and Mailbox's contracts,
     * never their models.
     *
     * **Known blind spot:** `contacts.customer_id` and `campaigns.customer_id`
     * in Mailbox have no contract to count them, and both are `nullOnDelete`, so
     * a delete would quietly unlink them. Neither is a financial record and
     * neither is lost, but if a Mailbox reader ever grows a count for them it
     * belongs in the list below.
     */
    public function delete(): void
    {
        $customer = $this->customer();

        if ($customer === null) {
            $this->toastError('That client is not here', 'Nothing was changed.');

            return;
        }

        // The authoritative count, unlike the one the page rendered with:
        // archived cards are included, because a card in the archive still names
        // this client and comes back naming them if it is restored.
        $blockers = $this->whatStillPointsAtThisClient(
            app(CardReader::class)->forCustomer($customer->id, includeArchived: true)->count(),
        );

        if ($blockers !== []) {
            $this->toastError(
                $customer->name.' cannot be deleted',
                'Kargah still has '.$this->readableList($blockers).' naming them, and a record whose '
                .'client cannot be named is worse than a long client list. Archive them instead — that '
                .'takes them off the active list and changes nothing else.',
            );

            return;
        }

        $name = $customer->name;
        $email = $customer->email;

        $customer->forceDelete();

        $this->flashToast(
            'success',
            $name.' deleted',
            'Nothing was pointing at them — no invoice, no schedule, no card, no message — so the record '
            .'is gone rather than archived, and '.$email.' is free to use again.',
        );

        $this->redirect(route('accounting.clients'), navigate: true);
    }

    /**
     * Everything that would be left naming nobody if this client went.
     *
     * The card count is a parameter rather than read here, because the two
     * callers legitimately want different counts: the render wants the cards it
     * has already loaded, and `delete()` wants the archive included too.
     *
     * Counting rows in SQL is fine — it is *adding money* in SQL that is not.
     *
     * @return list<string> phrases like "2 invoices", empty when nothing refers
     */
    private function whatStillPointsAtThisClient(int $cardCount): array
    {
        $customer = $this->customer();

        if ($customer === null) {
            return [];
        }

        /** @var list<array{0: string, 1: string, 2: int}> $counts */
        $counts = [
            ['invoice', 'invoices', Invoice::query()->withTrashed()->where('customer_id', $customer->id)->count()],
            ['recurring schedule', 'recurring schedules', RecurringInvoice::query()->withTrashed()->where('customer_id', $customer->id)->count()],
            ['card on the boards', 'cards on the boards', $cardCount],
            ['message in the mailbox', 'messages in the mailbox', app(EmailReader::class)->countForCustomer($customer->id)],
        ];

        $phrases = [];

        foreach ($counts as [$singular, $plural, $count]) {
            if ($count > 0) {
                $phrases[] = $count.' '.($count === 1 ? $singular : $plural);
            }
        }

        return $phrases;
    }

    /**
     * A list a person would read aloud.
     *
     * `implode(', ')` on three things gives a sentence nobody writes, and the
     * refusal is the whole point of the feature — it has to be readable.
     *
     * @param  list<string>  $phrases
     */
    private function readableList(array $phrases): string
    {
        if (count($phrases) < 2) {
            return implode('', $phrases);
        }

        $last = array_pop($phrases);

        return implode(', ', $phrases).' and '.$last;
    }
};

?>

<div class="flex flex-col gap-5">

    @if ($customer === null)

        <div class="kt-card">
            <div class="kt-card-content px-6 py-16 text-center">
                <i class="ki-filled ki-people text-3xl text-muted-foreground"></i>
                <h1 class="text-xl font-semibold text-mono mt-3">That client is not here</h1>
                <p class="text-sm text-secondary-foreground mt-2">
                    Client {{ $clientId }} was deleted, or the link points at a record that never existed.
                </p>
                <a href="{{ route('accounting.clients') }}" wire:navigate class="kt-btn kt-btn-primary kt-btn-sm mt-5">
                    Back to clients
                </a>
            </div>
        </div>

    @else

    {{-- Identity --}}
    <div class="kt-card">
        <div class="kt-card-content p-5 sm:p-6 flex flex-wrap items-start justify-between gap-5">

            <div class="flex items-center gap-4 min-w-0">
                <span class="inline-flex items-center justify-center size-14 rounded-lg bg-primary/10 text-primary text-lg font-semibold shrink-0">
                    {{ $customer->initials() }}
                </span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl font-semibold text-mono truncate">{{ $customer->name }}</h1>
                        @if ($company?->country)
                            <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $company->country }}</span>
                        @endif
                        @if ($customer->isArchived())
                            <span class="kt-badge kt-badge-sm kt-badge-outline">Archived</span>
                        @else
                            <span class="kt-badge kt-badge-sm kt-badge-success">Active</span>
                        @endif
                    </div>
                    <p class="text-sm text-secondary-foreground mt-1">
                        {{ $company?->name ?? 'No company' }}@if ($customer->role) · {{ $customer->role }}@endif
                        · client since {{ $customer->created_at?->format('F Y') ?? 'unknown' }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-end gap-6">
                <div class="text-end">
                    <div class="text-xs text-muted-foreground">Lifetime value</div>
                    <div class="text-2xl font-semibold text-mono mt-0.5">{{ $stats[0]['value'] }}</div>
                </div>
                <div class="flex items-center gap-2">
                    <a href="{{ route('accounting.invoice-create') }}" wire:navigate class="kt-btn kt-btn-primary gap-2">
                        <i class="ki-filled ki-plus"></i> New invoice
                    </a>
                    <div data-kt-dropdown="true" data-kt-dropdown-trigger="click" data-kt-dropdown-placement="bottom-end">
                        <button class="kt-btn kt-btn-outline kt-btn-icon" data-kt-dropdown-toggle="true" title="More actions" aria-label="More actions">
                            <i class="ki-filled ki-dots-vertical"></i>
                        </button>
                        <div class="kt-dropdown-menu w-[200px]" data-kt-dropdown-menu="true">
                            <div class="p-2 flex flex-col gap-1">
                                <a href="mailto:{{ $customer->email }}" class="kt-btn kt-btn-ghost justify-start gap-2">
                                    <i class="ki-filled ki-sms"></i> Email contact
                                </a>
                                <a href="{{ route('accounting.recurring') }}" wire:navigate class="kt-btn kt-btn-ghost justify-start gap-2">
                                    <i class="ki-filled ki-arrows-circle"></i> Recurring invoices
                                </a>
                                <button wire:click="archive" wire:loading.attr="disabled" wire:target="archive"
                                        class="kt-btn kt-btn-ghost justify-start gap-2 {{ $customer->isArchived() ? '' : 'text-destructive' }}">
                                    <i class="ki-filled ki-archive"></i>
                                    {{ $customer->isArchived() ? 'Restore client' : 'Archive client' }}
                                </button>
                                {{-- Offered always, permitted rarely. No `wire:confirm` when
                                     something still refers to the client: nothing is going to
                                     happen, and the click's job is to say what refers to them.
                                     When it is permitted the confirmation names the address that
                                     comes free again, because that is the reason to want it. --}}
                                <button wire:click="delete" wire:loading.attr="disabled" wire:target="delete"
                                        @if ($deletable) wire:confirm="Delete {{ $customer->name }} for good?&#10;&#10;No invoice, schedule, card or message refers to them, so the record goes rather than being archived and {{ $customer->email }} is free to use again. Nothing in Kargah brings it back." @endif
                                        class="kt-btn kt-btn-ghost justify-start gap-2 text-destructive">
                                    <i class="ki-filled ki-trash"></i> Delete client
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        {{-- Tabs --}}
        <div class="px-5 sm:px-6">
            <div class="kt-tabs kt-tabs-line">
                @foreach ($tabs as $key => $label)
                    <button wire:click="$set('tab', '{{ $key }}')"
                            class="kt-tab-toggle {{ $tab === $key ? '!text-primary !border-primary' : '' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        {{-- Tab panel --}}
        <div class="col-span-12 lg:col-span-8 flex flex-col gap-5">

            @if ($tab === 'projects')

                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">Cards</h3>
                        <span class="text-sm text-muted-foreground">
                            {{ $cards->count() }} {{ $cards->count() === 1 ? 'card' : 'cards' }} on the boards
                        </span>
                    </div>
                    <div class="kt-card-table">
                        <div class="kt-scrollable-x-auto">
                            <table class="kt-table align-middle text-sm">
                                <thead>
                                    <tr>
                                        <th>Card</th>
                                        <th class="w-[150px]">Board</th>
                                        <th class="w-[130px]">List</th>
                                        <th class="w-[120px]">Due</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($cards as $card)
                                        <tr wire:key="card-{{ $card['id'] }}">
                                            <td>
                                                <a href="{{ $card['url'] }}" wire:navigate class="text-mono hover:text-primary">
                                                    {{ $card['title'] }}
                                                </a>
                                            </td>
                                            <td class="text-secondary-foreground">{{ $card['board'] }}</td>
                                            <td class="text-secondary-foreground">{{ $card['list'] }}</td>
                                            <td>
                                                @if ($card['due_on'])
                                                    {{--
                                                        `due` is its own state — a card due exactly today.
                                                        It used to be folded into `soon`, and when it was
                                                        split out this branch silently stopped colouring
                                                        the one card a person most wants to notice.
                                                    --}}
                                                    <span class="{{ $dueTone[$card['due_state']] ?? 'text-secondary-foreground' }}">
                                                        {{ $card['due_on'] }}
                                                    </span>
                                                @else
                                                    <span class="text-muted-foreground">—</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="py-10 text-center">
                                                <i class="ki-filled ki-element-plus text-2xl text-muted-foreground"></i>
                                                <p class="text-sm text-muted-foreground mt-2">
                                                    No cards point at this client yet. Set the client on a card from its drawer.
                                                </p>
                                                <a href="{{ route('projects.boards') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-outline mt-4">
                                                    Open the boards
                                                </a>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            @elseif ($tab === 'overview')

                <div class="grid grid-cols-2 xl:grid-cols-4 gap-5">
                    @foreach ($stats as $s)
                        <div class="kt-card">
                            <div class="kt-card-content p-4">
                                <div class="text-xs text-muted-foreground">{{ $s['label'] }}</div>
                                <div class="text-lg font-semibold mt-1 {{ $s['tone'] }}">{{ $s['value'] }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">Revenue, last twelve months</h3>
                        <span class="text-sm text-muted-foreground">Invoiced in {{ $revenue['currency'] }}</span>
                    </div>
                    <div class="kt-card-content p-5">
                        <div class="flex items-end justify-between gap-1.5 h-[140px]">
                            @foreach ($revenue['months'] as $m)
                                <div class="flex flex-col items-center gap-2 grow min-w-0" title="{{ $m['month'] }} — {{ $m['label'] }}">
                                    <div class="w-full rounded-t bg-primary/70 hover:bg-primary transition-colors min-h-[2px]"
                                         style="height: {{ max($m['height'], 2) }}%"></div>
                                    <span class="text-[10px] text-muted-foreground">{{ $m['month'] }}</span>
                                </div>
                            @endforeach
                        </div>
                        @if ($revenue['excluded'] > 0)
                            <p class="text-xs text-muted-foreground mt-4">
                                {{ $revenue['excluded'] }} {{ $revenue['excluded'] === 1 ? 'invoice is' : 'invoices are' }}
                                in another currency and {{ $revenue['excluded'] === 1 ? 'is' : 'are' }} not on this chart —
                                two currencies in one bar would be a picture of nothing.
                            </p>
                        @endif
                    </div>
                </div>

                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Latest invoices</h3></div>
                    <div class="kt-card-table">
                        <div class="kt-scrollable-x-auto">
                            <table class="kt-table align-middle text-sm">
                                <thead>
                                    <tr>
                                        <th class="w-[120px]">Number</th>
                                        <th class="w-[130px]">Issued</th>
                                        <th class="w-[120px] text-end">Total</th>
                                        <th class="w-[110px]">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse (array_slice($invoices, 0, 3) as $inv)
                                        <tr wire:key="latest-{{ $inv['id'] }}">
                                            <td>
                                                <a href="{{ route('accounting.invoice-show', ['invoice' => $inv['id']]) }}" wire:navigate
                                                   class="font-medium text-mono hover:text-primary">{{ $inv['number'] }}</a>
                                            </td>
                                            <td class="text-secondary-foreground">{{ $inv['issued'] }}</td>
                                            <td class="text-end font-medium text-mono">{{ $inv['total'] }}</td>
                                            <td>
                                                <span class="{{ $statusBadges[$inv['status']]['class'] }}">
                                                    {{ $statusBadges[$inv['status']]['label'] }}
                                                </span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4" class="py-10 text-center text-sm text-muted-foreground">
                                                You have not invoiced this client yet.
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            @elseif ($tab === 'invoices')

                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">Invoices</h3>
                        <a href="{{ route('accounting.invoice-create') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-outline gap-2">
                            <i class="ki-filled ki-plus"></i> New invoice
                        </a>
                    </div>
                    <div class="kt-card-table">
                        <div class="kt-scrollable-x-auto">
                            <table class="kt-table align-middle text-sm">
                                <thead>
                                    <tr>
                                        <th class="w-[120px]">Number</th>
                                        <th class="w-[130px]">Issued</th>
                                        <th class="w-[130px]">Due</th>
                                        <th class="w-[140px] text-end">Total</th>
                                        <th class="w-[110px]">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($invoices as $inv)
                                        <tr wire:key="invoice-{{ $inv['id'] }}">
                                            <td>
                                                <a href="{{ route('accounting.invoice-show', ['invoice' => $inv['id']]) }}" wire:navigate
                                                   class="font-medium text-mono hover:text-primary">{{ $inv['number'] }}</a>
                                            </td>
                                            <td class="text-secondary-foreground">{{ $inv['issued'] }}</td>
                                            <td class="text-secondary-foreground">{{ $inv['due'] }}</td>
                                            <td class="text-end">
                                                <div class="font-medium text-mono">{{ $inv['total'] }}</div>
                                                @if ($inv['outstanding'])
                                                    <div class="text-xs text-warning">{{ $inv['outstanding'] }} outstanding</div>
                                                @endif
                                            </td>
                                            <td>
                                                <span class="{{ $statusBadges[$inv['status']]['class'] }}">
                                                    {{ $statusBadges[$inv['status']]['label'] }}
                                                </span>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5">
                                                <div class="flex flex-col items-center justify-center text-center py-12">
                                                    <i class="ki-filled ki-bill text-3xl text-muted-foreground mb-3"></i>
                                                    <p class="text-sm text-secondary-foreground mb-4">You have not invoiced this client yet.</p>
                                                    <a href="{{ route('accounting.invoice-create') }}" wire:navigate class="kt-btn kt-btn-primary kt-btn-sm gap-2">
                                                        <i class="ki-filled ki-plus"></i> New invoice
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            @elseif ($tab === 'expenses')

                <div class="kt-card">
                    <div class="kt-card-header">
                        <h3 class="kt-card-title">Expenses on this account</h3>
                        <a href="{{ route('accounting.expense-create') }}" wire:navigate class="kt-btn kt-btn-sm kt-btn-outline gap-2">
                            <i class="ki-filled ki-plus"></i> Record expense
                        </a>
                    </div>
                    <div class="kt-card-table">
                        <div class="kt-scrollable-x-auto">
                            <table class="kt-table align-middle text-sm">
                                <thead>
                                    <tr>
                                        <th class="w-[130px]">Date</th>
                                        <th class="min-w-[180px]">Vendor</th>
                                        <th class="w-[130px]">Category</th>
                                        <th class="w-[130px]">Rebilling</th>
                                        <th class="w-[120px] text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($expenses as $e)
                                        <tr wire:key="expense-{{ $e['id'] }}">
                                            <td class="text-secondary-foreground">{{ $e['date'] }}</td>
                                            <td>
                                                <div class="font-medium text-mono">{{ $e['vendor'] }}</div>
                                                @if ($e['description'])
                                                    <div class="text-xs text-muted-foreground line-clamp-1">{{ $e['description'] }}</div>
                                                @endif
                                            </td>
                                            <td><span class="kt-badge kt-badge-sm kt-badge-outline">{{ $e['category'] }}</span></td>
                                            <td>
                                                <span class="{{ $expenseBadges[$e['state']]['class'] }}"
                                                      @if ($e['rebilledOn']) title="On {{ $e['rebilledOn'] }}" @endif>
                                                    {{ $expenseBadges[$e['state']]['label'] }}
                                                </span>
                                            </td>
                                            <td class="text-end font-medium text-mono">{{ $e['amount'] }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5">
                                                <div class="flex flex-col items-center justify-center text-center py-12">
                                                    <i class="ki-filled ki-wallet text-3xl text-muted-foreground mb-3"></i>
                                                    <p class="text-sm text-secondary-foreground mb-4">
                                                        @if ($company === null)
                                                            This client belongs to no company, and an expense is recorded against a company.
                                                        @else
                                                            Nothing has been spent against {{ $company->name }}.
                                                        @endif
                                                    </p>
                                                    <a href="{{ route('accounting.expense-create') }}" wire:navigate class="kt-btn kt-btn-primary kt-btn-sm gap-2">
                                                        <i class="ki-filled ki-plus"></i> Record expense
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            @else

                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">Notes</h3></div>
                    <div class="kt-card-content p-5 flex flex-col gap-4">
                        <div class="flex flex-col gap-2">
                            <textarea wire:model="draftNote" rows="3" class="kt-textarea w-full"
                                      placeholder="What should you remember about this client?"></textarea>
                            <div class="flex justify-end">
                                <button wire:click="addNote" wire:loading.attr="disabled" wire:target="addNote"
                                        @disabled(trim($draftNote) === '')
                                        class="kt-btn kt-btn-primary kt-btn-sm gap-2">
                                    <span wire:loading.remove wire:target="addNote" class="inline-flex items-center gap-2">
                                        <i class="ki-filled ki-notepad-edit"></i> Add note
                                    </span>
                                    <span wire:loading wire:target="addNote" class="inline-flex items-center gap-2">
                                        <i class="ki-filled ki-loading animate-spin"></i> Saving…
                                    </span>
                                </button>
                            </div>
                        </div>

                        <div class="flex flex-col divide-y divide-border border-t border-border">
                            @forelse ($notes as $note)
                                <div class="py-4">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-sm font-medium text-mono">{{ $customer->name }}</span>
                                        <span class="text-xs text-muted-foreground shrink-0">{{ $note['at'] ?? 'Undated' }}</span>
                                    </div>
                                    <p class="text-sm text-secondary-foreground mt-1.5 leading-relaxed whitespace-pre-line">{{ $note['body'] }}</p>
                                </div>
                            @empty
                                <div class="flex flex-col items-center justify-center text-center py-12">
                                    <i class="ki-filled ki-notepad text-3xl text-muted-foreground mb-3"></i>
                                    <p class="text-sm text-secondary-foreground">Nothing noted about this client yet.</p>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </div>

            @endif

        </div>

        {{-- Contact and billing --}}
        <div class="col-span-12 lg:col-span-4 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Contact</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-3 text-sm">
                    <div class="flex items-center gap-3">
                        <i class="ki-filled ki-user text-base text-muted-foreground shrink-0"></i>
                        <span class="text-mono truncate">{{ $customer->name }}@if ($customer->role) · {{ $customer->role }}@endif</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <i class="ki-filled ki-sms text-base text-muted-foreground shrink-0"></i>
                        <a href="mailto:{{ $customer->email }}" class="text-primary hover:underline truncate">{{ $customer->email }}</a>
                    </div>
                    @if ($customer->phone)
                        <div class="flex items-center gap-3">
                            <i class="ki-filled ki-phone text-base text-muted-foreground shrink-0"></i>
                            <span class="text-mono">{{ $customer->phone }}</span>
                        </div>
                    @endif
                    @if ($customer->timezone)
                        <div class="flex items-center gap-3">
                            <i class="ki-filled ki-time text-base text-muted-foreground shrink-0"></i>
                            <span class="text-secondary-foreground">{{ $customer->timezone }}</span>
                        </div>
                    @endif
                    @if ($company?->website)
                        <div class="flex items-center gap-3">
                            <i class="ki-filled ki-map text-base text-muted-foreground shrink-0"></i>
                            <a href="https://{{ $company->website }}" target="_blank" rel="noopener"
                               class="text-primary hover:underline truncate">{{ $company->website }}</a>
                        </div>
                    @endif
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Billing</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-4">
                    @if ($company === null)
                        <p class="text-sm text-secondary-foreground">
                            No company on file, so invoices go to {{ $customer->name }} personally.
                        </p>
                    @else
                        <p class="text-sm text-mono font-medium">{{ $company->billingName() }}</p>
                        @if ($company->address)
                            <p class="text-sm text-secondary-foreground whitespace-pre-line leading-relaxed">{{ $company->address }}</p>
                        @endif
                        <dl class="flex flex-col gap-2 text-sm border-t border-border pt-4">
                            @if ($company->tax_number)
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-secondary-foreground">Tax number</dt>
                                    <dd class="text-mono font-medium truncate">{{ $company->tax_number }}</dd>
                                </div>
                            @endif
                            @if ($company->tax_office)
                                <div class="flex items-center justify-between gap-3">
                                    <dt class="text-secondary-foreground">Tax office</dt>
                                    <dd class="text-mono font-medium">{{ $company->tax_office }}</dd>
                                </div>
                            @endif
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-secondary-foreground">Currency</dt>
                                <dd class="text-mono font-medium">{{ $company->default_currency ?? '—' }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-3">
                                <dt class="text-secondary-foreground">Turkish buyer</dt>
                                <dd class="text-mono font-medium">{{ $company->is_domestic ? 'Yes — needs the lira equivalent' : 'No' }}</dd>
                            </div>
                        </dl>
                    @endif
                </div>
            </div>

        </div>
    </div>

    @endif
</div>
