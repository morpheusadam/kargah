<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Core\Models\Customer;
use Modules\Project\Contracts\CardReader;

/**
 * Client record.
 *
 * Everything about one client on one page: what they are worth, what they owe, and
 * every document either side has raised. The tab lives in the query string so a
 * link to "Northwind, invoices tab" is a link someone can actually send.
 *
 * The revenue chart is twelve plain divs rather than ApexCharts — at this size a
 * sparkline is a bar per month, and that does not need 400 KB of JavaScript.
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

    public function mount(string $client): void
    {
        $this->clientId = $client;

        // Backend phase resolves the stored client here.
    }

    public function with(): array
    {
        $revenue = [
            ['month' => 'Sep', 'value' => 1200.00],
            ['month' => 'Oct', 'value' => 0.00],
            ['month' => 'Nov', 'value' => 2400.00],
            ['month' => 'Dec', 'value' => 1800.00],
            ['month' => 'Jan', 'value' => 950.00],
            ['month' => 'Feb', 'value' => 2400.00],
            ['month' => 'Mar', 'value' => 0.00],
            ['month' => 'Apr', 'value' => 3200.00],
            ['month' => 'May', 'value' => 1200.00],
            ['month' => 'Jun', 'value' => 1200.00],
            ['month' => 'Jul', 'value' => 2400.00],
            ['month' => 'Aug', 'value' => 0.00],
        ];

        $peak = max(array_map(fn (array $m) => $m['value'], $revenue)) ?: 1.0;

        return [
            'client' => [
                'name' => 'Northwind Ltd',
                'contact' => 'Sam Okafor',
                'role' => 'Head of Product',
                'email' => 'sam@northwind.example',
                'phone' => '+44 20 7946 0918',
                'website' => 'northwind.example',
                'country' => 'UK',
                'since' => 'September 2025',
                'terms' => 'Net 30',
                'vat' => 'GB 771 4402 09',
                'billing' => "Northwind Ltd\n42 Bevis Marks\nLondon EC3A 7BA\nUnited Kingdom",
            ],
            'tabs' => [
                'overview' => 'Overview',
                'projects' => 'Projects',
                'invoices' => 'Invoices',
                'expenses' => 'Expenses',
                'notes' => 'Notes',
            ],
            // Real, from Project, through its contract. Accounting may read a
            // card; it may not hold one — see Modules\Project\Contracts\CardReader.
            'cards' => $this->cards(),
            'stats' => [
                ['label' => 'Lifetime value', 'value' => $this->money(16750.00), 'tone' => 'text-mono'],
                ['label' => 'Outstanding',    'value' => $this->money(2400.00),  'tone' => 'text-warning'],
                ['label' => 'Paid this year', 'value' => $this->money(11350.00), 'tone' => 'text-success'],
                ['label' => 'Avg. days to pay', 'value' => '24', 'tone' => 'text-mono'],
            ],
            'revenue' => array_map(fn (array $m) => [
                'month' => $m['month'],
                'label' => $this->money($m['value']),
                'height' => (int) round(($m['value'] / $peak) * 100),
            ], $revenue),
            'invoices' => [
                ['id' => 41, 'no' => 'INV-0041', 'issued' => '20 Jul 2026', 'due' => '19 Aug 2026', 'total' => $this->money(2400.00), 'status' => 'sent'],
                ['id' => 38, 'no' => 'INV-0038', 'issued' => '01 Jun 2026', 'due' => '01 Jul 2026', 'total' => $this->money(1200.00), 'status' => 'paid'],
                ['id' => 34, 'no' => 'INV-0034', 'issued' => '12 Apr 2026', 'due' => '12 May 2026', 'total' => $this->money(3200.00), 'status' => 'paid'],
                ['id' => 29, 'no' => 'INV-0029', 'issued' => '03 Feb 2026', 'due' => '05 Mar 2026', 'total' => $this->money(2400.00), 'status' => 'paid'],
            ],
            'expenses' => [
                ['date' => '18 Jul 2026', 'vendor' => 'Figma',        'category' => 'Software', 'amount' => $this->money(45.00),  'rebilled' => true],
                ['date' => '02 Jun 2026', 'vendor' => 'Great Western Railway', 'category' => 'Travel', 'amount' => $this->money(112.40), 'rebilled' => true],
                ['date' => '14 Apr 2026', 'vendor' => 'Adobe Stock',  'category' => 'Software', 'amount' => $this->money(29.99),  'rebilled' => false],
            ],
            'notes' => [
                ['author' => 'Nima Fazlipour', 'at' => '21 Jul 2026', 'body' => 'Sam wants the analytics dashboard scoped before the new financial year. Follow up in September.'],
                ['author' => 'Nima Fazlipour', 'at' => '02 Jun 2026', 'body' => 'Procurement pay on the 15th and the last working day only — issue invoices before the 10th.'],
                ['author' => 'Nima Fazlipour', 'at' => '14 Sep 2025', 'body' => 'Signed the master services agreement. Rate is £85/hour, reviewed each January.'],
            ],
            'badge' => [
                'draft' => 'kt-badge-outline',
                'sent' => 'kt-badge-info',
                'paid' => 'kt-badge-success',
                'overdue' => 'kt-badge-destructive',
            ],
        ];
    }

    /**
     * The customer this page is about, once one exists.
     *
     * The money on this page is still a fixture until the Accounting phase.
     * The Projects tab is not — it reads real cards, because proving a card
     * can be found from the person it is for is what the Project phase was
     * for.
     */
    private function customer(): ?Customer
    {
        return Customer::query()->find((int) $this->clientId);
    }

    /** This client's cards, read across the module boundary by contract. */
    private function cards(): \Illuminate\Support\Collection
    {
        $customer = $this->customer();

        return $customer === null ? collect() : app(CardReader::class)->forCustomer($customer->id);
    }

    /* ---- Actions the backend will implement. Signatures are final. ---- */

    public function addNote(): void
    {
        // Stores the note against the client once the record exists.
        $this->draftNote = '';

        $this->toastInfo('Not connected yet', 'Notes are stored once the client record exists. Nothing was saved.');
    }

    public function archive(): void
    {
        // Hides the client from pickers without deleting the history.
        $this->toastInfo('Not connected yet', 'Archiving a client lands with the backend phase. The client is unchanged.');
    }

    protected function money(float $amount): string
    {
        return '$' . number_format($amount, 2);
    }
};

?>

<div class="flex flex-col gap-5">

    {{-- Identity --}}
    <div class="kt-card">
        <div class="kt-card-content p-5 sm:p-6 flex flex-wrap items-start justify-between gap-5">

            <div class="flex items-center gap-4 min-w-0">
                <span class="inline-flex items-center justify-center size-14 rounded-lg bg-primary/10 text-primary text-lg font-semibold shrink-0">
                    {{ strtoupper(substr($client['name'], 0, 2)) }}
                </span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="text-xl font-semibold text-mono truncate">{{ $client['name'] }}</h1>
                        <span class="kt-badge kt-badge-sm kt-badge-outline">{{ $client['country'] }}</span>
                        <span class="kt-badge kt-badge-sm kt-badge-success">Active</span>
                    </div>
                    <p class="text-sm text-secondary-foreground mt-1">
                        {{ $client['contact'] }} · {{ $client['role'] }} · client since {{ $client['since'] }}
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
                                <a href="mailto:{{ $client['email'] }}" class="kt-btn kt-btn-ghost justify-start gap-2">
                                    <i class="ki-filled ki-sms"></i> Email contact
                                </a>
                                <a href="{{ route('accounting.recurring') }}" wire:navigate class="kt-btn kt-btn-ghost justify-start gap-2">
                                    <i class="ki-filled ki-arrows-circle"></i> Recurring invoices
                                </a>
                                <button wire:click="archive" class="kt-btn kt-btn-ghost justify-start gap-2 text-destructive">
                                    <i class="ki-filled ki-archive"></i> Archive client
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
                                                    <span class="{{ $card['due_state'] === 'overdue' ? 'text-destructive' : ($card['due_state'] === 'soon' ? 'text-warning' : 'text-secondary-foreground') }}">
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
                        <span class="text-sm text-muted-foreground">{{ $stats[2]['value'] }} this year</span>
                    </div>
                    <div class="kt-card-content p-5">
                        <div class="flex items-end justify-between gap-1.5 h-[140px]">
                            @foreach ($revenue as $m)
                                <div class="flex flex-col items-center gap-2 grow min-w-0" title="{{ $m['month'] }} — {{ $m['label'] }}">
                                    <div class="w-full rounded-t bg-primary/70 hover:bg-primary transition-colors min-h-[2px]"
                                         style="height: {{ max($m['height'], 2) }}%"></div>
                                    <span class="text-[10px] text-muted-foreground">{{ $m['month'] }}</span>
                                </div>
                            @endforeach
                        </div>
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
                                    @foreach (array_slice($invoices, 0, 3) as $inv)
                                        <tr>
                                            <td>
                                                <a href="{{ route('accounting.invoice-show', ['invoice' => $inv['id']]) }}" wire:navigate
                                                   class="font-medium text-mono hover:text-primary">{{ $inv['no'] }}</a>
                                            </td>
                                            <td class="text-secondary-foreground">{{ $inv['issued'] }}</td>
                                            <td class="text-end font-medium text-mono">{{ $inv['total'] }}</td>
                                            <td><span class="kt-badge kt-badge-sm {{ $badge[$inv['status']] }}">{{ ucfirst($inv['status']) }}</span></td>
                                        </tr>
                                    @endforeach
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
                                        <th class="w-[120px] text-end">Total</th>
                                        <th class="w-[110px]">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($invoices as $inv)
                                        <tr>
                                            <td>
                                                <a href="{{ route('accounting.invoice-show', ['invoice' => $inv['id']]) }}" wire:navigate
                                                   class="font-medium text-mono hover:text-primary">{{ $inv['no'] }}</a>
                                            </td>
                                            <td class="text-secondary-foreground">{{ $inv['issued'] }}</td>
                                            <td class="text-secondary-foreground">{{ $inv['due'] }}</td>
                                            <td class="text-end font-medium text-mono">{{ $inv['total'] }}</td>
                                            <td><span class="kt-badge kt-badge-sm {{ $badge[$inv['status']] }}">{{ ucfirst($inv['status']) }}</span></td>
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
                                        <th class="w-[120px]">Rebilled</th>
                                        <th class="w-[120px] text-end">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($expenses as $e)
                                        <tr>
                                            <td class="text-secondary-foreground">{{ $e['date'] }}</td>
                                            <td class="font-medium text-mono">{{ $e['vendor'] }}</td>
                                            <td><span class="kt-badge kt-badge-sm kt-badge-outline">{{ $e['category'] }}</span></td>
                                            <td>
                                                <span class="kt-badge kt-badge-sm {{ $e['rebilled'] ? 'kt-badge-success' : 'kt-badge-outline' }}">
                                                    {{ $e['rebilled'] ? 'Rebilled' : 'Absorbed' }}
                                                </span>
                                            </td>
                                            <td class="text-end font-medium text-mono">{{ $e['amount'] }}</td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="5">
                                                <div class="flex flex-col items-center justify-center text-center py-12">
                                                    <i class="ki-filled ki-wallet text-3xl text-muted-foreground mb-3"></i>
                                                    <p class="text-sm text-secondary-foreground mb-4">Nothing has been spent against this client.</p>
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
                                        <span class="text-sm font-medium text-mono">{{ $note['author'] }}</span>
                                        <span class="text-xs text-muted-foreground shrink-0">{{ $note['at'] }}</span>
                                    </div>
                                    <p class="text-sm text-secondary-foreground mt-1.5 leading-relaxed">{{ $note['body'] }}</p>
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
                        <span class="text-mono truncate">{{ $client['contact'] }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <i class="ki-filled ki-sms text-base text-muted-foreground shrink-0"></i>
                        <a href="mailto:{{ $client['email'] }}" class="text-primary hover:underline truncate">{{ $client['email'] }}</a>
                    </div>
                    <div class="flex items-center gap-3">
                        <i class="ki-filled ki-phone text-base text-muted-foreground shrink-0"></i>
                        <span class="text-mono">{{ $client['phone'] }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <i class="ki-filled ki-map text-base text-muted-foreground shrink-0"></i>
                        <a href="https://{{ $client['website'] }}" target="_blank" rel="noopener"
                           class="text-primary hover:underline truncate">{{ $client['website'] }}</a>
                    </div>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Billing address</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-4">
                    <p class="text-sm text-secondary-foreground whitespace-pre-line leading-relaxed">{{ $client['billing'] }}</p>
                    <dl class="flex flex-col gap-2 text-sm border-t border-border pt-4">
                        <div class="flex items-center justify-between">
                            <dt class="text-secondary-foreground">Payment terms</dt>
                            <dd class="text-mono font-medium">{{ $client['terms'] }}</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-secondary-foreground">VAT number</dt>
                            <dd class="text-mono font-medium">{{ $client['vat'] }}</dd>
                        </div>
                        <div class="flex items-center justify-between">
                            <dt class="text-secondary-foreground">Currency</dt>
                            <dd class="text-mono font-medium">USD</dd>
                        </div>
                    </dl>
                </div>
            </div>

        </div>
    </div>
</div>
