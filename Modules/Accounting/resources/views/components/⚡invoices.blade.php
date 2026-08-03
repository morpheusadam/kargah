<?php

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Support\Money;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * The invoice book, reading from the database.
 *
 * Three things here are worth knowing before changing anything.
 *
 * **Two islands: the filter tabs and the table body.** Every action that
 * changes what either of them shows names both, because an island nobody names
 * is rendered with `mode=skip` and the new markup is computed, sent and thrown
 * away. There are exactly two `@island` directives in this file and neither is
 * inside a loop — a directive in a `@foreach` shares one token across every
 * iteration and morphs the wrong row. See project-guaid/spec/04-frontend.md.
 *
 * **Cursor pagination, ordered by id.** The invoice book only grows, and offset
 * pagination scans and discards every row it skips. Ordering is by primary key
 * rather than by issue date because a cursor needs a column that is unique and
 * never null, and `issued_on` is neither — a draft has no issue date at all.
 *
 * **No money is added up in SQL.** The summary figures are read as rows and
 * summed through `Money`, per currency. A dollar and a lira do not add up, so
 * they are never put in the same total; `SUM(total)` across a mixed book is a
 * number that means nothing and looks authoritative.
 */
new
#[Title('Invoices — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** Enough rows to fill a screen, few enough that the query stays cheap. */
    private const PER_PAGE = 15;

    public const TABS = [
        'all' => 'All',
        'draft' => 'Draft',
        'sent' => 'Sent',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
    ];

    /**
     * Whole class strings, never built by concatenation.
     *
     * Tailwind's scanner reads source, so `"kt-badge-{$tone}"` produces a class
     * that exists in the DOM and in no stylesheet — see docs/frontend-conventions.md.
     */
    public const BADGES = [
        'draft' => 'kt-badge-outline',
        'sent' => 'kt-badge-info',
        'part_paid' => 'kt-badge-warning',
        'paid' => 'kt-badge-success',
        'overdue' => 'kt-badge-destructive',
        'void' => 'kt-badge-outline',
    ];

    public const LABELS = [
        'draft' => 'Draft',
        'sent' => 'Sent',
        'part_paid' => 'Part paid',
        'paid' => 'Paid',
        'overdue' => 'Overdue',
        'void' => 'Void',
    ];

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $search = '';

    /** The encoded cursor. Empty means the first page, and stays out of the URL. */
    #[Url]
    public string $cursor = '';

    /**
     * Per-request memos. Private, so Livewire neither ships nor rehydrates them,
     * and a new component instance starts empty.
     */
    private ?CursorPaginator $resolvedRows = null;

    private ?array $resolvedCounts = null;

    /* Reading the book ------------------------------------------------------ */

    private function searchTerm(): string
    {
        return trim($this->search);
    }

    private function baseQuery(): Builder
    {
        $term = $this->searchTerm();

        return Invoice::query()
            ->with(['customer', 'company'])
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($term): void {
                $like = '%'.$term.'%';

                $query->where('number', 'like', $like)
                    ->orWhere('notes', 'like', $like)
                    ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', $like))
                    ->orWhereHas('company', fn (Builder $c) => $c->where('name', 'like', $like));
            }));
    }

    /**
     * One tab's slice of the book.
     *
     * 'Sent' means issued and still owed, part payments included — an invoice
     * that has had half of it paid has not stopped being outstanding.
     */
    private function scoped(string $status): Builder
    {
        $query = $this->baseQuery();

        return match ($status) {
            'draft' => $query->draft(),
            'sent' => $query->issued()->whereIn('status', ['sent', 'part_paid']),
            'paid' => $query->where('status', 'paid'),
            'overdue' => $query->overdue(),
            default => $query,
        };
    }

    private function rows(): CursorPaginator
    {
        return $this->resolvedRows ??= $this->scoped($this->status)
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE, ['*'], 'cursor', $this->currentCursor());
    }

    /**
     * The cursor the address bar is carrying, if it is one.
     *
     * A cursor is base64 in a query string, which means it can arrive edited,
     * truncated or from a link someone pasted into a chat. An unreadable one is
     * the first page, not a stack trace.
     */
    private function currentCursor(): ?Cursor
    {
        if ($this->cursor === '') {
            return null;
        }

        return rescue(fn (): ?Cursor => Cursor::fromEncoded($this->cursor), null, false);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        if ($this->resolvedCounts !== null) {
            return $this->resolvedCounts;
        }

        $counts = [];

        foreach (array_keys(self::TABS) as $key) {
            $counts[$key] = $this->scoped($key)->count();
        }

        return $this->resolvedCounts = $counts;
    }

    /* The figures ------------------------------------------------------------ */

    /**
     * Outstanding and overdue, per currency, computed in PHP.
     *
     * Every open invoice is read with what has been paid against it, and the
     * remainder is summed through `Money` in its own currency. Two queries, no
     * arithmetic in SQL, and no currency added to another.
     *
     * @return array{outstanding: array<string, string>, overdue: array<string, string>}
     */
    private function openFigures(): array
    {
        $open = Invoice::query()
            ->issued()
            ->whereNotIn('status', ['paid', 'void'])
            ->get(['id', 'currency', 'total', 'due_on', 'status', 'sent_at', 'voided_at']);

        $paid = Payment::query()
            ->whereIn('invoice_id', $open->pluck('id'))
            ->get(['invoice_id', 'applied_amount'])
            ->groupBy('invoice_id');

        $outstanding = [];
        $overdue = [];

        foreach ($open as $invoice) {
            $settled = Money::sum(
                ($paid[$invoice->id] ?? collect())->map(fn ($payment): string => (string) $payment->applied_amount),
                $invoice->currency,
            );

            $owed = Money::fromStorage((string) $invoice->total, $invoice->currency)->minus($settled, Money::ROUNDING);

            if (! $owed->isPositive()) {
                continue;
            }

            $outstanding[$invoice->currency][] = $owed;

            if ($invoice->isOverdue()) {
                $overdue[$invoice->currency][] = $owed;
            }
        }

        return [
            'outstanding' => $this->formatPerCurrency($outstanding),
            'overdue' => $this->formatPerCurrency($overdue),
        ];
    }

    /** What landed this calendar month, in the currency it landed in. */
    private function receivedThisMonth(): array
    {
        $payments = Payment::query()
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->get(['currency', 'amount']);

        $received = [];

        foreach ($payments as $payment) {
            $received[$payment->currency][] = Money::fromStorage((string) $payment->amount, $payment->currency);
        }

        return $this->formatPerCurrency($received);
    }

    /**
     * @param  array<string, list<\Brick\Money\Money>>  $byCurrency
     * @return array<string, string>
     */
    private function formatPerCurrency(array $byCurrency): array
    {
        $formatted = [];

        foreach ($byCurrency as $currency => $amounts) {
            $formatted[$currency] = Money::format(Money::toStorage(Money::sum($amounts, $currency)), $currency);
        }

        return $formatted;
    }

    public function with(): array
    {
        $rows = $this->rows();
        $figures = $this->openFigures();

        return [
            'tabs' => self::TABS,
            'counts' => $this->counts(),
            'invoices' => $rows,
            'badges' => self::BADGES,
            'labels' => self::LABELS,
            'summary' => [
                ['label' => 'Outstanding', 'figures' => $figures['outstanding'], 'tone' => 'text-warning'],
                ['label' => 'Received this month', 'figures' => $this->receivedThisMonth(), 'tone' => 'text-success'],
                ['label' => 'Overdue', 'figures' => $figures['overdue'], 'tone' => 'text-destructive'],
            ],
        ];
    }

    /**
     * How a row's status reads, which is not always the column.
     *
     * `overdue` is a date having passed rather than a value someone wrote, so
     * an invoice can be sent in the table and overdue on the page without
     * anything having updated the row.
     */
    public function stateOf(Invoice $invoice): string
    {
        if ($invoice->isVoid()) {
            return 'void';
        }

        return $invoice->isOverdue() ? 'overdue' : $invoice->status;
    }

    /** Who the invoice is addressed to, which may be a person, a company, or neither. */
    public function billedTo(Invoice $invoice): string
    {
        return $invoice->company?->name ?? $invoice->customer?->name ?? 'No client on this invoice';
    }

    /* Actions ---------------------------------------------------------------- */

    /**
     * Redraw the tabs and the table body.
     *
     * Both islands, every time. An island nobody names keeps whatever the DOM
     * already had, so a filter change that named only the body would leave the
     * old tab highlighted and the old counts on screen.
     */
    private function refreshTable(): void
    {
        $this->resolvedRows = null;
        $this->resolvedCounts = null;

        $this->renderIsland('tabs');
        $this->renderIsland('rows');
    }

    public function filterBy(string $status): void
    {
        $this->status = array_key_exists($status, self::TABS) ? $status : 'all';

        // A cursor points into the list it was taken from. Kept across a filter
        // change it silently drops rows off the front of the new one.
        $this->cursor = '';

        $this->refreshTable();
    }

    public function updatedSearch(): void
    {
        $this->cursor = '';

        $this->refreshTable();
    }

    /** Step to the next or previous page. The encoded cursor comes from the paginator. */
    public function goToCursor(string $cursor = ''): void
    {
        $this->cursor = $cursor;

        $this->refreshTable();
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Invoices</h1>
            <p class="text-sm text-secondary-foreground mt-1">Bill clients and track what is still owed.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('accounting.recurring') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-arrows-circle"></i> Recurring
            </a>
            <a href="{{ route('accounting.invoice-create') }}" wire:navigate class="kt-btn kt-btn-primary gap-2">
                <i class="ki-filled ki-plus"></i> New invoice
            </a>
        </div>
    </div>

    {{--
        One card per figure, and one line per currency inside it. A dollar and a
        lira do not add up, so they are never shown as though they had.
    --}}
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        @foreach ($summary as $card)
            <div class="kt-card">
                <div class="kt-card-content p-5">
                    <div class="text-sm text-secondary-foreground">{{ $card['label'] }}</div>
                    @forelse ($card['figures'] as $code => $figure)
                        <div class="text-2xl font-semibold mt-1 {{ $card['tone'] }}">
                            {{ $figure }}
                            <span class="text-xs font-normal text-muted-foreground align-middle">{{ $code }}</span>
                        </div>
                    @empty
                        <div class="text-2xl font-semibold mt-1 text-muted-foreground">—</div>
                    @endforelse
                </div>
            </div>
        @endforeach
    </div>

    <div class="kt-card">

        <div class="kt-card-header flex-wrap gap-3">

            {{--
                Island one: the filter tabs and their counts.

                The search box sits outside it deliberately. It carries the
                focus while a search is being typed, and there is no reason to
                morph the element someone is typing into.
            --}}
            @island(name: 'tabs')
            <div class="flex flex-wrap gap-1">
                @foreach ($tabs as $key => $label)
                    <button wire:click="filterBy('{{ $key }}')" wire:loading.attr="disabled"
                            class="kt-btn kt-btn-sm gap-1.5 {{ $status === $key ? 'kt-btn-primary' : 'kt-btn-ghost' }}">
                        {{ $label }}
                        <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $counts[$key] }}</span>
                    </button>
                @endforeach
            </div>
            @endisland

            <div class="kt-input max-w-[240px]">
                <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                <input type="text" placeholder="Search invoices…" aria-label="Search invoices"
                       wire:model.live.debounce.300ms="search">
                <i class="ki-filled ki-loading animate-spin text-muted-foreground" wire:loading wire:target="search"></i>
            </div>
        </div>

        {{-- Island two: the table body, which is the part cursor pagination pages. --}}
        @island(name: 'rows')
        <div>
            <div class="kt-card-table">
                <div class="kt-scrollable-x-auto">
                    <table class="kt-table align-middle text-sm">
                        <thead>
                            <tr>
                                <th class="w-[150px]">Number</th>
                                <th class="min-w-[200px]">Client</th>
                                <th class="w-[120px]">Issued</th>
                                <th class="w-[120px]">Due</th>
                                <th class="w-[170px] text-end">Total</th>
                                <th class="w-[110px]">Status</th>
                                <th class="w-[60px]"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($invoices as $invoice)
                                @php($state = $this->stateOf($invoice))
                                <tr wire:key="invoice-{{ $invoice->id }}">
                                    <td>
                                        <a href="{{ route('accounting.invoice-show', ['invoice' => $invoice->id]) }}" wire:navigate
                                           class="font-medium text-mono hover:text-primary">{{ $invoice->number }}</a>
                                    </td>
                                    <td>{{ $this->billedTo($invoice) }}</td>
                                    <td class="text-secondary-foreground">
                                        {{ $invoice->issued_on?->format('j M Y') ?? '—' }}
                                    </td>
                                    <td class="text-secondary-foreground">
                                        {{ $invoice->due_on?->format('j M Y') ?? '—' }}
                                    </td>
                                    <td class="text-end whitespace-nowrap">
                                        <span class="font-medium text-mono">{{ $invoice->formattedTotal() }}</span>
                                        @if ($invoice->formattedReporting() !== null && $invoice->reporting_currency !== $invoice->currency)
                                            <span class="block text-xs text-muted-foreground mt-0.5">
                                                ≈ {{ $invoice->formattedReporting() }} converted
                                            </span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="kt-badge kt-badge-sm {{ $badges[$state] ?? 'kt-badge-outline' }}">
                                            {{ $labels[$state] ?? ucfirst($state) }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        @if ($invoice->isIssued())
                                            <a href="{{ route('accounting.invoice-show', ['invoice' => $invoice->id]) }}" wire:navigate
                                               class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Open invoice" aria-label="Open invoice">
                                                <i class="ki-filled ki-eye text-sm"></i>
                                            </a>
                                        @else
                                            <a href="{{ route('accounting.invoice-edit', ['invoice' => $invoice->id]) }}" wire:navigate
                                               class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Edit draft" aria-label="Edit draft">
                                                <i class="ki-filled ki-pencil text-sm"></i>
                                            </a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7">
                                        <div class="flex flex-col items-center justify-center text-center py-14">
                                            <i class="ki-filled ki-bill text-3xl text-muted-foreground mb-3"></i>
                                            <p class="text-sm text-secondary-foreground mb-4">
                                                @if ($search !== '')
                                                    Nothing in the book matches “{{ $search }}”.
                                                @elseif ($status !== 'all')
                                                    No {{ strtolower($tabs[$status]) }} invoices at the moment.
                                                @else
                                                    No invoices yet — raise one for whatever you have already delivered.
                                                @endif
                                            </p>
                                            <a href="{{ route('accounting.invoice-create') }}" wire:navigate
                                               class="kt-btn kt-btn-primary kt-btn-sm gap-2">
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

            @if ($invoices->hasPages())
                <div class="kt-card-footer flex items-center justify-between gap-3">
                    <span class="text-xs text-muted-foreground">
                        Newest first, {{ $invoices->count() }} on this page.
                    </span>
                    <div class="flex items-center gap-2">
                        <button wire:click="goToCursor('{{ $invoices->previousCursor()?->encode() }}')"
                                wire:loading.attr="disabled" wire:target="goToCursor"
                                @disabled($invoices->onFirstPage())
                                class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 disabled:opacity-40">
                            <i class="ki-filled ki-black-left text-xs"></i> Newer
                        </button>
                        <button wire:click="goToCursor('{{ $invoices->nextCursor()?->encode() }}')"
                                wire:loading.attr="disabled" wire:target="goToCursor"
                                @disabled(! $invoices->hasMorePages())
                                class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 disabled:opacity-40">
                            Older <i class="ki-filled ki-arrow-right text-xs"></i>
                        </button>
                    </div>
                </div>
            @endif
        </div>
        @endisland

    </div>
</div>
