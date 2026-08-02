<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Title('Invoices — Kargah')]
class extends Component
{
    #[Url]
    public string $status = 'all';

    public string $search = '';

    public function with(): array
    {
        return [
            'tabs' => [
                'all' => 'All', 'draft' => 'Draft', 'sent' => 'Sent',
                'paid' => 'Paid', 'overdue' => 'Overdue',
            ],
            'summary' => [
                ['label' => 'Outstanding', 'value' => '—', 'tone' => 'text-warning'],
                ['label' => 'Paid this month', 'value' => '—', 'tone' => 'text-success'],
                ['label' => 'Overdue', 'value' => '—', 'tone' => 'text-destructive'],
            ],
            'invoices' => [
                ['id' => 41, 'no' => 'INV-0041', 'client' => 'Northwind Ltd', 'issued' => '2026-07-20', 'due' => '2026-08-19', 'total' => '$2,400.00', 'status' => 'sent'],
                ['id' => 40, 'no' => 'INV-0040', 'client' => 'Acme Studio',   'issued' => '2026-07-02', 'due' => '2026-08-01', 'total' => '$980.00',   'status' => 'overdue'],
                ['id' => 39, 'no' => 'INV-0039', 'client' => 'Bluepeak',      'issued' => '2026-06-18', 'due' => '2026-07-18', 'total' => '$5,150.00', 'status' => 'paid'],
                ['id' => 38, 'no' => 'INV-0038', 'client' => 'Northwind Ltd', 'issued' => '2026-06-01', 'due' => '2026-07-01', 'total' => '$1,200.00', 'status' => 'paid'],
            ],
            'badge' => [
                'draft' => 'kt-badge-outline',
                'sent' => 'kt-badge-info',
                'paid' => 'kt-badge-success',
                'overdue' => 'kt-badge-destructive',
            ],
        ];
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

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
        @foreach ($summary as $s)
            <div class="kt-card">
                <div class="kt-card-content p-5">
                    <div class="text-sm text-secondary-foreground">{{ $s['label'] }}</div>
                    <div class="text-2xl font-semibold mt-1 {{ $s['tone'] }}">{{ $s['value'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="kt-card">
        <div class="kt-card-header flex-wrap gap-3">
            <div class="flex gap-1">
                @foreach ($tabs as $key => $label)
                    <button wire:click="$set('status', '{{ $key }}')"
                            class="kt-btn kt-btn-sm {{ $status === $key ? 'kt-btn-primary' : 'kt-btn-ghost' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <div class="kt-input max-w-[240px]">
                <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                <input type="text" placeholder="Search invoices…" wire:model.live.debounce.300ms="search">
            </div>
        </div>

        <div class="kt-card-table">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table align-middle text-sm">
                    <thead>
                        <tr>
                            <th class="w-[120px]">Number</th>
                            <th class="min-w-[200px]">Client</th>
                            <th class="w-[120px]">Issued</th>
                            <th class="w-[120px]">Due</th>
                            <th class="w-[120px] text-end">Total</th>
                            <th class="w-[110px]">Status</th>
                            <th class="w-[60px]"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($invoices as $inv)
                            <tr>
                                <td>
                                    <a href="{{ route('accounting.invoice-show', ['invoice' => $inv['id']]) }}" wire:navigate
                                       class="font-medium text-mono hover:text-primary">{{ $inv['no'] }}</a>
                                </td>
                                <td>{{ $inv['client'] }}</td>
                                <td class="text-secondary-foreground">{{ $inv['issued'] }}</td>
                                <td class="text-secondary-foreground">{{ $inv['due'] }}</td>
                                <td class="text-end font-medium text-mono">{{ $inv['total'] }}</td>
                                <td>
                                    <span class="kt-badge kt-badge-sm {{ $badge[$inv['status']] }}">{{ ucfirst($inv['status']) }}</span>
                                </td>
                                <td class="text-end">
                                    <a href="{{ route('accounting.invoice-edit', ['invoice' => $inv['id']]) }}" wire:navigate
                                       class="kt-btn kt-btn-icon kt-btn-ghost size-7" title="Edit invoice" aria-label="Edit invoice">
                                        <i class="ki-filled ki-pencil text-sm"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
