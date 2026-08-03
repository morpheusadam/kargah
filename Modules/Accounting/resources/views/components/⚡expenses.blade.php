<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

new
#[Title('Expenses — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public string $search = '';

    public function with(): array
    {
        return [
            'expenses' => [
                ['date' => '2026-07-28', 'vendor' => 'Hostinger',   'category' => 'Hosting',  'amount' => '$71.88',  'method' => 'Card'],
                ['date' => '2026-07-25', 'vendor' => 'KeenThemes',  'category' => 'Software', 'amount' => '$49.00',  'method' => 'Card'],
                ['date' => '2026-07-14', 'vendor' => 'Amazon SES',  'category' => 'Email',    'amount' => '$12.40',  'method' => 'Card'],
                ['date' => '2026-07-02', 'vendor' => 'Namecheap',   'category' => 'Domains',  'amount' => '$28.00',  'method' => 'PayPal'],
            ],
            'categories' => ['Hosting', 'Software', 'Email', 'Domains', 'Hardware', 'Other'],
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Expenses</h1>
            <p class="text-sm text-secondary-foreground mt-1">What the business costs you to run.</p>
        </div>
        <a href="{{ route('accounting.expense-create') }}" wire:navigate class="kt-btn kt-btn-primary gap-2">
            <i class="ki-filled ki-plus"></i> Record expense
        </a>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        <div class="kt-card lg:col-span-2">
            <div class="kt-card-header flex-wrap gap-3">
                <h3 class="kt-card-title">Recent expenses</h3>
                <div class="kt-input max-w-[220px]">
                    <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                    <input type="text" placeholder="Search…" wire:model.live.debounce.300ms="search">
                </div>
            </div>
            <div class="kt-card-table">
                <div class="kt-scrollable-x-auto">
                    <table class="kt-table align-middle text-sm">
                        <thead>
                            <tr>
                                <th class="w-[120px]">Date</th>
                                <th class="min-w-[160px]">Vendor</th>
                                <th class="w-[130px]">Category</th>
                                <th class="w-[110px]">Method</th>
                                <th class="w-[110px] text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($expenses as $e)
                                <tr>
                                    <td class="text-secondary-foreground">{{ $e['date'] }}</td>
                                    <td class="font-medium text-mono">{{ $e['vendor'] }}</td>
                                    <td><span class="kt-badge kt-badge-sm kt-badge-outline">{{ $e['category'] }}</span></td>
                                    <td class="text-secondary-foreground">{{ $e['method'] }}</td>
                                    <td class="text-end font-medium text-mono">{{ $e['amount'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">By category</h3>
            </div>
            <div class="kt-card-content p-5">
                <div id="expense_chart" class="min-h-[220px] flex items-center justify-center text-sm text-muted-foreground">
                    Chart renders once expenses are stored.
                </div>
                <div class="flex flex-col gap-2 mt-4">
                    @foreach ($categories as $c)
                        <div class="flex items-center justify-between text-sm">
                            <span class="text-secondary-foreground">{{ $c }}</span>
                            <span class="text-mono font-medium">—</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

    </div>
</div>
