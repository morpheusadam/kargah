<?php

use Brick\Money\Money as BrickMoney;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Accounting\Models\Invoice;
use Modules\Accounting\Models\Payment;
use Modules\Accounting\Support\Money;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Core\Models\Company;
use Modules\Core\Models\Customer;

/**
 * Everyone you invoice, with what they are worth and what they owe.
 *
 * **Every figure is added in PHP.** `SUM(total)` would be a sum of doubles on
 * SQLite and a figure nobody could defend; the invoices are fetched with their
 * payments and totalled through `Money`. That costs one query for the whole
 * page rather than one per client, which is the trade that makes it affordable.
 *
 * **Currencies are never mixed.** A client billed in lira and in dollars gets
 * two figures, not one converted one. Converting here would need a rate, and a
 * rate needs a date and a source before anyone can argue with the number.
 */
new
#[Title('Clients — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    #[Url]
    public string $search = '';

    /** One of 'active', 'archived', 'all'. */
    #[Url(as: 'show')]
    public string $filter = 'active';

    public bool $creating = false;

    #[Validate('required|string|max:190')]
    public string $newName = '';

    #[Validate('required|email|max:190|unique:customers,email')]
    public string $newEmail = '';

    public string $newCompanyId = '';

    private ?Collection $resolvedCustomers = null;

    private ?Collection $resolvedInvoices = null;

    /* Reading ---------------------------------------------------------------- */

    private function customers(): Collection
    {
        if ($this->resolvedCustomers !== null) {
            return $this->resolvedCustomers;
        }

        $query = Customer::query()->with('company')->orderBy('name');

        match ($this->filter) {
            'archived' => $query->archived(),
            'all' => null,
            default => $query->active(),
        };

        $term = trim($this->search);

        if ($term !== '') {
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', '%'.$term.'%')
                    ->orWhere('email', 'like', '%'.$term.'%')
                    ->orWhere('role', 'like', '%'.$term.'%')
                    ->orWhereHas('company', fn ($c) => $c->where('name', 'like', '%'.$term.'%'));
            });
        }

        return $this->resolvedCustomers = $query->get();
    }

    /**
     * Every invoice belonging to the clients on screen, with its payments.
     *
     * One query and one eager load for the page. The alternative — asking each
     * client for its own invoices — is a list that gets slower the better the
     * year went.
     *
     * @return Collection<int, Collection<int, Invoice>> keyed by customer id
     */
    private function invoicesByCustomer(): Collection
    {
        return $this->resolvedInvoices ??= Invoice::query()
            ->whereIn('customer_id', $this->customers()->pluck('id'))
            ->whereNull('voided_at')
            ->with('payments')
            ->get()
            ->groupBy('customer_id');
    }

    /* Money ------------------------------------------------------------------- */

    /**
     * What is still owed on one invoice, in its own currency.
     *
     * The same rule `PaymentRecorder::outstanding()` applies, read off the
     * eager-loaded relation instead of a fresh query — the figure has to match
     * what the invoice page says, so the rule is stated once and copied
     * deliberately rather than approximated.
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
     * @return list<string>
     */
    private function perCurrency(Collection $invoices, callable $amountOf): array
    {
        return $invoices
            ->groupBy('currency')
            ->map(fn (Collection $group, string $currency): string => Money::format(
                Money::toStorage(Money::sum($group->map($amountOf), $currency)),
                $currency,
            ))
            ->values()
            ->all();
    }

    /* View --------------------------------------------------------------------- */

    public function with(): array
    {
        $invoices = $this->invoicesByCustomer();

        return [
            'clients' => $this->customers()->map(function (Customer $customer) use ($invoices): array {
                /** @var Collection<int, Invoice> $theirs */
                $theirs = $invoices->get($customer->id, collect());

                // Only an issued invoice is money anyone owes. A draft is a
                // sentence someone is still writing.
                $issued = $theirs->filter(fn (Invoice $invoice): bool => $invoice->isIssued());
                $open = $issued->reject(fn (Invoice $invoice): bool => $invoice->status === 'paid');
                $overdue = $issued->filter(fn (Invoice $invoice): bool => $invoice->isOverdue());

                $outstanding = $open->filter(
                    fn (Invoice $invoice): bool => $this->outstandingOf($invoice)->isPositive(),
                );

                return [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    'initials' => $customer->initials(),
                    'role' => $customer->role,
                    'email' => $customer->email,
                    'company' => $customer->company?->name,
                    'country' => $customer->company?->country,
                    'archived' => $customer->isArchived(),
                    'invoiceCount' => $theirs->count(),
                    'draftCount' => $theirs->count() - $issued->count(),
                    'openCount' => $open->count(),
                    'overdueCount' => $overdue->count(),
                    'billed' => $this->perCurrency(
                        $issued,
                        fn (Invoice $invoice): BrickMoney => Money::fromStorage((string) $invoice->total, $invoice->currency),
                    ),
                    'outstanding' => $this->perCurrency(
                        $outstanding,
                        fn (Invoice $invoice): BrickMoney => $this->outstandingOf($invoice),
                    ),
                ];
            })->all(),

            'companies' => Company::query()->active()->orderBy('name')->get(),
            'filters' => ['active' => 'Active', 'archived' => 'Archived', 'all' => 'Everyone'],
            'anyCustomers' => Customer::query()->count() > 0,
        ];
    }

    /* Filters ------------------------------------------------------------------- */

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['active', 'archived', 'all'], true) ? $filter : 'active';
        $this->resolvedCustomers = null;
        $this->resolvedInvoices = null;
    }

    /* Adding a client ------------------------------------------------------------ */

    public function startAdding(): void
    {
        $this->creating = true;
    }

    public function cancelAdding(): void
    {
        $this->creating = false;
        $this->reset(['newName', 'newEmail', 'newCompanyId']);
        $this->resetValidation();
    }

    public function addClient(): void
    {
        $this->validate();

        $customer = Customer::query()->create([
            'name' => trim($this->newName),
            'email' => trim($this->newEmail),
            'company_id' => $this->newCompanyId === '' ? null : (int) $this->newCompanyId,
            'created_by' => auth()->id(),
        ]);

        $this->creating = false;
        $this->reset(['newName', 'newEmail', 'newCompanyId']);
        $this->resolvedCustomers = null;
        $this->resolvedInvoices = null;

        $this->toastSuccess(
            $customer->name.' added',
            $customer->company === null
                ? 'They belong to no company, so invoices go to them personally.'
                : 'Filed under '.$customer->company->name.'.',
        );
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Clients</h1>
            <p class="text-sm text-secondary-foreground mt-1">Everyone you invoice.</p>
        </div>
        <div class="flex items-center gap-2">
            <div class="kt-input max-w-[220px]">
                <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                <input type="text" placeholder="Name, email or company…" aria-label="Search clients"
                       wire:model.live.debounce.300ms="search">
            </div>
            <button wire:click="startAdding" class="kt-btn kt-btn-primary gap-2">
                <i class="ki-filled ki-plus"></i> Add client
            </button>
        </div>
    </div>

    {{-- The strip scrolls rather than pushing the page sideways. `.kt-tab-toggle`
         inherits the theme's `white-space: nowrap`, so a strip that does not fit
         cannot shrink to fit — it widens the body instead. `⚡client-show`'s
         identical strip was measured ending at 414px inside a 375px viewport on
         4 August 2026; this one is only three tabs long and does not overflow
         yet, which is why it gets the wrapper before a fourth filter arrives. --}}
    <div class="kt-scrollable-x-auto">
        <div class="kt-tabs kt-tabs-line">
            @foreach ($filters as $key => $label)
                <button wire:click="setFilter('{{ $key }}')"
                        class="kt-tab-toggle {{ $filter === $key ? '!text-primary !border-primary' : '' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- Inline creation. Driven from component state rather than KTUI, because
         the morph strips a class the browser added. --}}
    @if ($creating)
        <div class="kt-card">
            <div class="kt-card-header"><h3 class="kt-card-title">New client</h3></div>
            <div class="kt-card-content p-5 flex flex-col gap-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="client_name">Name</label>
                        <input id="client_name" type="text" wire:model.blur="newName" autofocus
                               placeholder="Who you deal with"
                               class="kt-input @error('newName') border-destructive @enderror">
                        @error('newName')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="client_email">Email</label>
                        <input id="client_email" type="email" wire:model.blur="newEmail"
                               placeholder="name@company.example"
                               class="kt-input @error('newEmail') border-destructive @enderror">
                        @error('newEmail')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label class="kt-form-label" for="client_company">Company</label>
                        <select id="client_company" wire:model="newCompanyId" class="kt-select">
                            <option value="">No company</option>
                            @foreach ($companies as $company)
                                <option value="{{ $company->id }}">{{ $company->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button wire:click="addClient" wire:loading.attr="disabled" wire:target="addClient"
                            class="kt-btn kt-btn-primary kt-btn-sm gap-2">
                        <span wire:loading.remove wire:target="addClient" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-check"></i> Add client
                        </span>
                        <span wire:loading wire:target="addClient" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Adding…
                        </span>
                    </button>
                    <button wire:click="cancelAdding" class="kt-btn kt-btn-sm kt-btn-ghost">Cancel</button>
                </div>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
        @forelse ($clients as $c)
            <div class="kt-card" wire:key="client-{{ $c['id'] }}">
                <div class="kt-card-content p-5 flex flex-col gap-4">

                    <div class="flex items-start justify-between gap-3">
                        <a href="{{ route('accounting.client-show', ['client' => $c['id']]) }}" wire:navigate
                           class="flex items-center gap-3 min-w-0 group">
                            <span class="inline-flex items-center justify-center size-11 rounded-lg bg-primary/10 text-primary font-semibold shrink-0">
                                {{ $c['initials'] }}
                            </span>
                            <div class="min-w-0">
                                <div class="font-semibold text-mono truncate group-hover:text-primary">{{ $c['name'] }}</div>
                                <div class="text-sm text-secondary-foreground truncate">
                                    {{ $c['company'] ?? $c['role'] ?? 'No company' }}
                                </div>
                            </div>
                        </a>
                        <div class="flex flex-col items-end gap-1 shrink-0">
                            @if ($c['archived'])
                                <span class="kt-badge kt-badge-sm kt-badge-outline">Archived</span>
                            @elseif ($c['country'])
                                <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $c['country'] }}</span>
                            @endif
                            @if ($c['overdueCount'] > 0)
                                <span class="kt-badge kt-badge-sm kt-badge-destructive">
                                    {{ $c['overdueCount'] }} overdue
                                </span>
                            @endif
                        </div>
                    </div>

                    <a href="mailto:{{ $c['email'] }}" class="text-sm text-primary hover:underline truncate">{{ $c['email'] }}</a>

                    <div class="flex items-start justify-between gap-3 pt-3 border-t border-border">
                        <div class="min-w-0">
                            <div class="text-xs text-muted-foreground">Billed to date</div>
                            @forelse ($c['billed'] as $figure)
                                <div class="font-semibold text-mono truncate">{{ $figure }}</div>
                            @empty
                                <div class="font-semibold text-muted-foreground">—</div>
                            @endforelse
                        </div>
                        <div class="text-end min-w-0">
                            <div class="text-xs text-muted-foreground">Outstanding</div>
                            @forelse ($c['outstanding'] as $figure)
                                <div class="font-semibold text-warning truncate">{{ $figure }}</div>
                            @empty
                                <div class="font-semibold text-success">Nothing owed</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="text-xs text-muted-foreground">
                        @if ($c['invoiceCount'] === 0)
                            Not invoiced yet.
                        @else
                            {{ $c['invoiceCount'] }} {{ $c['invoiceCount'] === 1 ? 'invoice' : 'invoices' }},
                            {{ $c['openCount'] }} still open@if ($c['draftCount'] > 0), {{ $c['draftCount'] }} in draft@endif.
                        @endif
                    </div>

                </div>
            </div>
        @empty
            <div class="md:col-span-2 xl:col-span-3 rounded-lg border border-dashed border-border px-6 py-14 text-center">
                <i class="ki-filled ki-people text-3xl text-muted-foreground"></i>
                @if ($anyCustomers)
                    <p class="text-sm text-secondary-foreground mt-3">
                        No client matches this search on the {{ strtolower($filters[$filter]) }} list.
                    </p>
                    <button wire:click="setFilter('all')" class="kt-btn kt-btn-outline kt-btn-sm mt-4">
                        Show everyone
                    </button>
                @else
                    <p class="text-sm text-secondary-foreground mt-3">
                        No clients yet. Add the first one and the invoices have somewhere to point.
                    </p>
                    <button wire:click="startAdding" class="kt-btn kt-btn-primary kt-btn-sm gap-2 mt-4">
                        <i class="ki-filled ki-plus"></i> Add client
                    </button>
                @endif
            </div>
        @endforelse
    </div>
</div>
