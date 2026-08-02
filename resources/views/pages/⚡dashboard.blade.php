<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Dashboard — Kargah')]
class extends Component
{
    /**
     * Placeholder metrics. Wired to real module data in the backend phase.
     */
    public function with(): array
    {
        return [
            'stats' => [
                ['label' => 'Open tasks',     'value' => '—', 'icon' => 'ki-abstract-26', 'hint' => 'across all boards'],
                ['label' => 'Unread mail',    'value' => '—', 'icon' => 'ki-sms',         'hint' => 'inbox'],
                ['label' => 'Unpaid invoices','value' => '—', 'icon' => 'ki-dollar',      'hint' => 'awaiting payment'],
                ['label' => 'Stored items',   'value' => '—', 'icon' => 'ki-folder',      'hint' => 'files & credentials'],
            ],
        ];
    }
};

?>

<div class="flex flex-col gap-5 lg:gap-7.5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Dashboard</h1>
            <p class="text-sm text-secondary-foreground mt-1">Everything that needs you today.</p>
        </div>
    </div>

    {{-- Stat cards --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
        @foreach ($stats as $stat)
            <div class="kt-card">
                <div class="kt-card-content flex items-center gap-4 p-5">
                    <span class="inline-flex items-center justify-center size-11 rounded-lg bg-primary/10 text-primary shrink-0">
                        <i class="ki-filled {{ $stat['icon'] }} text-xl"></i>
                    </span>
                    <div class="min-w-0">
                        <div class="text-2xl font-semibold text-mono leading-none">{{ $stat['value'] }}</div>
                        <div class="text-sm font-medium text-secondary-foreground mt-1.5">{{ $stat['label'] }}</div>
                        <div class="text-xs text-muted-foreground">{{ $stat['hint'] }}</div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Module entry points --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

        <div class="kt-card lg:col-span-2">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Recent activity</h3>
            </div>
            <div class="kt-card-content p-5">
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    <i class="ki-filled ki-chart-line-up text-4xl text-muted-foreground mb-3"></i>
                    <p class="text-sm text-secondary-foreground">Activity appears here once the backend modules are wired.</p>
                </div>
            </div>
        </div>

        <div class="kt-card">
            <div class="kt-card-header">
                <h3 class="kt-card-title">Quick actions</h3>
            </div>
            <div class="kt-card-content flex flex-col gap-2 p-5">
                <a href="{{ route('projects.boards') }}" class="kt-btn kt-btn-outline justify-start gap-2">
                    <i class="ki-filled ki-abstract-26"></i> New board
                </a>
                <a href="{{ route('mail.campaigns') }}" class="kt-btn kt-btn-outline justify-start gap-2">
                    <i class="ki-filled ki-send"></i> New campaign
                </a>
                <a href="{{ route('accounting.invoices') }}" class="kt-btn kt-btn-outline justify-start gap-2">
                    <i class="ki-filled ki-dollar"></i> New invoice
                </a>
                <a href="{{ route('data.links') }}" class="kt-btn kt-btn-outline justify-start gap-2">
                    <i class="ki-filled ki-link"></i> Save a link
                </a>
            </div>
        </div>

    </div>
</div>
