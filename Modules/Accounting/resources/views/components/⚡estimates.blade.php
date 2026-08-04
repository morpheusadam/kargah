<?php

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\Cursor;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Accounting\Models\Estimate;
use Modules\Accounting\Support\Money;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * The quote book. A sibling of `⚡invoices.blade.php`, deliberately.
 *
 * Same two islands, same cursor pagination, same per-currency figures — an
 * estimate list that behaved differently from the invoice list would be a second
 * application inside the first. What differs is what the numbers *mean*, and
 * that is worth knowing before changing anything.
 *
 * **Nothing here has been transacted.** These figures are what the work would
 * come to if every open quote were accepted. They are not receivables, they are
 * not revenue, and they never appear in a report — which is why this page says
 * "if they all say yes" out loud rather than presenting a total that looks like
 * money already owed.
 *
 * **Expired is a date, not a column.** `Estimate::isExpired()` derives it, and
 * `scopeExpired()` filters it in SQL, exactly as `Invoice::scopeOverdue()` does.
 * So the Expired tab and the Sent tab both read the `sent` rows and split them
 * on `valid_until`, and nothing has to run for a quote to go stale.
 *
 * **No money is added up in SQL.** The figures are read as rows and summed
 * through `Money`, per currency. A dollar and a lira do not add up.
 */
new
#[Title('Estimates — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** Enough rows to fill a screen, few enough that the query stays cheap. */
    private const PER_PAGE = 15;

    public const TABS = [
        'all' => 'All',
        'draft' => 'Draft',
        'sent' => 'Sent',
        'accepted' => 'Accepted',
        'declined' => 'Declined',
        'expired' => 'Expired',
    ];

    /**
     * Whole class strings, never built by concatenation.
     *
     * Tailwind's scanner reads source, so `"kt-badge-{$tone}"` produces a class
     * that exists in the DOM and in no stylesheet.
     */
    public const BADGES = [
        'draft' => 'kt-badge-outline',
        'sent' => 'kt-badge-info',
        'accepted' => 'kt-badge-success',
        'declined' => 'kt-badge-destructive',
        'expired' => 'kt-badge-warning',
    ];

    public const LABELS = [
        'draft' => 'Draft',
        'sent' => 'Sent',
        'accepted' => 'Accepted',
        'declined' => 'Declined',
        'expired' => 'Expired',
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

    private function baseQuery(): Builder
    {
        $term = trim($this->search);

        return Estimate::query()
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
     * 'Sent' means out with the client and still inside its validity date. A
     * quote whose date has passed has stopped being an offer, so it moves to
     * Expired rather than sitting in Sent forever pretending to be live.
     */
    private function scoped(string $status): Builder
    {
        $query = $this->baseQuery();

        return match ($status) {
            'draft' => $query->where('status', 'draft'),
            'sent' => $query->awaiting(),
            'accepted' => $query->where('status', 'accepted'),
            'declined' => $query->where('status', 'declined'),
            'expired' => $query->expired(),
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
     * A cursor is base64 in a query string, so it can arrive edited, truncated
     * or from a link somebody pasted into a chat. An unreadable one is the first
     * page, not a stack trace.
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
     * What is out there and what has been said yes to, per currency.
     *
     * Neither is money. "Out with clients" is what the open quotes would come to
     * if every one of them were accepted, and "accepted, not yet invoiced" is
     * work agreed but not yet billed — the queue of invoices waiting to be
     * raised. Both are read as rows and summed through `Money`.
     *
     * @return array{awaiting: array<string, string>, accepted: array<string, string>}
     */
    private function figures(): array
    {
        $awaiting = [];
        $accepted = [];

        foreach (Estimate::query()->awaiting()->get(['currency', 'total']) as $estimate) {
            $awaiting[$estimate->currency][] = Money::fromStorage((string) $estimate->total, $estimate->currency);
        }

        $unbilled = Estimate::query()
            ->where('status', 'accepted')
            ->whereNull('converted_at')
            ->get(['currency', 'total']);

        foreach ($unbilled as $estimate) {
            $accepted[$estimate->currency][] = Money::fromStorage((string) $estimate->total, $estimate->currency);
        }

        return [
            'awaiting' => $this->formatPerCurrency($awaiting),
            'accepted' => $this->formatPerCurrency($accepted),
        ];
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
        $figures = $this->figures();

        return [
            'tabs' => self::TABS,
            'counts' => $this->counts(),
            'estimates' => $this->rows(),
            'badges' => self::BADGES,
            'labels' => self::LABELS,
            'summary' => [
                [
                    'label' => 'Out with clients',
                    'note' => 'if they all say yes',
                    'figures' => $figures['awaiting'],
                    'tone' => 'text-info',
                ],
                [
                    'label' => 'Accepted, not yet invoiced',
                    'note' => 'waiting to be converted',
                    'figures' => $figures['accepted'],
                    'tone' => 'text-success',
                ],
            ],
        ];
    }

    /** Who the quote is addressed to, which may be a person, a company, or neither. */
    public function quotedTo(Estimate $estimate): string
    {
        return $estimate->company?->name ?? $estimate->customer?->name ?? 'No client on this estimate';
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
            <h1 class="text-xl font-semibold text-mono">Estimates</h1>
            <p class="text-sm text-secondary-foreground mt-1">
                Quote the work first. An accepted quote becomes a draft invoice saying the same numbers.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('accounting.invoices') }}" wire:navigate class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-bill"></i> Invoices
            </a>
            <a href="{{ route('accounting.estimate-create') }}" wire:navigate class="kt-btn kt-btn-primary gap-2">
                <i class="ki-filled ki-plus"></i> New estimate
            </a>
        </div>
    </div>

    {{--
        One card per figure, one line per currency inside it. Neither figure is
        money that exists: the note under each says what it actually is, because
        a big number on an accounting page reads as revenue unless it says
        otherwise.
    --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
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
                    <div class="text-xs text-muted-foreground mt-1">{{ $card['note'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="kt-card">

        <div class="kt-card-header flex-wrap gap-3">

            {{--
                Island one: the filter tabs and their counts.

                The search box sits outside it deliberately. It carries the focus
                while a search is being typed, and there is no reason to morph
                the element somebody is typing into.
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
                <input type="text" placeholder="Search estimates…" aria-label="Search estimates"
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
                                <th class="w-[140px]">Number</th>
                                <th class="min-w-[200px]">Client</th>
                                <th class="w-[130px]">Valid until</th>
                                <th class="w-[170px] text-end">Total</th>
                                <th class="w-[110px]">Status</th>
                                <th class="min-w-[150px]">Became</th>
                                <th class="w-[60px]"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($estimates as $estimate)
                                @php($state = $estimate->state())
                                <tr wire:key="estimate-{{ $estimate->id }}">
                                    <td>
                                        <a href="{{ route('accounting.estimate-edit', ['estimate' => $estimate->id]) }}" wire:navigate
                                           class="font-medium text-mono hover:text-primary">{{ $estimate->number }}</a>
                                    </td>
                                    <td>{{ $this->quotedTo($estimate) }}</td>
                                    <td class="text-secondary-foreground">
                                        {{ $estimate->valid_until?->format('j M Y') ?? 'No expiry' }}
                                    </td>
                                    <td class="text-end whitespace-nowrap">
                                        <span class="font-medium text-mono">{{ $estimate->formattedTotal() }}</span>
                                        <span class="block text-xs text-muted-foreground mt-0.5">{{ $estimate->currency }}</span>
                                    </td>
                                    <td>
                                        <span class="kt-badge kt-badge-sm {{ $badges[$state] ?? 'kt-badge-outline' }}">
                                            {{ $labels[$state] ?? ucfirst($state) }}
                                        </span>
                                    </td>
                                    <td>
                                        {{-- The invoice it turned into, named even if that draft has
                                             since been deleted — the number stays reserved either way. --}}
                                        @if ($estimate->isConverted())
                                            @if ($estimate->convertedInvoice)
                                                <a href="{{ route('accounting.invoice-show', ['invoice' => $estimate->converted_invoice_id]) }}"
                                                   wire:navigate class="text-2sm text-primary hover:underline">
                                                    {{ $estimate->converted_invoice_number }}
                                                </a>
                                            @else
                                                <span class="text-2sm text-muted-foreground">
                                                    {{ $estimate->converted_invoice_number }}
                                                </span>
                                            @endif
                                        @else
                                            <span class="text-2sm text-muted-foreground">—</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        <a href="{{ route('accounting.estimate-edit', ['estimate' => $estimate->id]) }}" wire:navigate
                                           class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Open estimate" aria-label="Open estimate">
                                            <i class="ki-filled ki-eye text-sm"></i>
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7">
                                        <div class="flex flex-col items-center justify-center text-center py-14">
                                            <i class="ki-filled ki-document text-3xl text-muted-foreground mb-3"></i>
                                            <p class="text-sm text-secondary-foreground mb-4">
                                                @if ($search !== '')
                                                    Nothing in the quote book matches “{{ $search }}”.
                                                @elseif ($status !== 'all')
                                                    No {{ strtolower($tabs[$status]) }} estimates at the moment.
                                                @else
                                                    No estimates yet — quote the next job before you do it.
                                                @endif
                                            </p>
                                            <a href="{{ route('accounting.estimate-create') }}" wire:navigate
                                               class="kt-btn kt-btn-primary kt-btn-sm gap-2">
                                                <i class="ki-filled ki-plus"></i> New estimate
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            @if ($estimates->hasPages())
                <div class="kt-card-footer flex items-center justify-between gap-3">
                    <span class="text-xs text-muted-foreground">
                        Newest first, {{ $estimates->count() }} on this page.
                    </span>
                    <div class="flex items-center gap-2">
                        <button wire:click="goToCursor('{{ $estimates->previousCursor()?->encode() }}')"
                                wire:loading.attr="disabled" wire:target="goToCursor"
                                @disabled($estimates->onFirstPage())
                                class="kt-btn kt-btn-sm kt-btn-outline gap-1.5 disabled:opacity-40">
                            <i class="ki-filled ki-black-left text-xs"></i> Newer
                        </button>
                        <button wire:click="goToCursor('{{ $estimates->nextCursor()?->encode() }}')"
                                wire:loading.attr="disabled" wire:target="goToCursor"
                                @disabled(! $estimates->hasMorePages())
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
