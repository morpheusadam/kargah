<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

new
#[Title('Reports — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public string $period = 'ytd';

    public function with(): array
    {
        return [
            'periods' => ['month' => 'This month', 'quarter' => 'This quarter', 'ytd' => 'Year to date', 'all' => 'All time'],
            'kpis' => [
                ['label' => 'Revenue',      'value' => '—', 'icon' => 'ki-arrow-up',    'tone' => 'text-success'],
                ['label' => 'Expenses',     'value' => '—', 'icon' => 'ki-arrow-down',  'tone' => 'text-destructive'],
                ['label' => 'Net profit',   'value' => '—', 'icon' => 'ki-chart-line-up', 'tone' => 'text-primary'],
                ['label' => 'Avg. invoice', 'value' => '—', 'icon' => 'ki-dollar',      'tone' => 'text-secondary-foreground'],
            ],
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Reports</h1>
            <p class="text-sm text-secondary-foreground mt-1">Where the money went and where it came from.</p>
        </div>
        <div class="flex items-center gap-2">
            <select class="kt-select max-w-[180px]" wire:model.live="period">
                @foreach ($periods as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <button class="kt-btn kt-btn-outline gap-2">
                <i class="ki-filled ki-exit-down"></i> Export
            </button>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        @foreach ($kpis as $k)
            <div class="kt-card">
                <div class="kt-card-content p-5">
                    <div class="flex items-center gap-2 text-sm text-secondary-foreground">
                        <i class="ki-filled {{ $k['icon'] }} {{ $k['tone'] }}"></i>
                        {{ $k['label'] }}
                    </div>
                    <div class="text-2xl font-semibold text-mono mt-2">{{ $k['value'] }}</div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div class="kt-card">
            <div class="kt-card-header"><h3 class="kt-card-title">Revenue vs expenses</h3></div>
            <div class="kt-card-content p-5">
                <div class="min-h-[280px] flex items-center justify-center text-sm text-muted-foreground">
                    Wired to ApexCharts in the backend phase.
                </div>
            </div>
        </div>
        <div class="kt-card">
            <div class="kt-card-header"><h3 class="kt-card-title">Top clients by revenue</h3></div>
            <div class="kt-card-content p-5">
                <div class="min-h-[280px] flex items-center justify-center text-sm text-muted-foreground">
                    Wired to ApexCharts in the backend phase.
                </div>
            </div>
        </div>
    </div>
</div>
