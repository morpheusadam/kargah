<?php

use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The home screen.
 *
 * It answers one question: what needs me today. Everything on it is a shortcut
 * into a module, never a dead end.
 */
new
#[Title('Dashboard — Kargah')]
class extends Component
{
    public string $range = '30d';

    public function with(): array
    {
        return [
            'ranges' => ['7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last quarter'],

            'stats' => [
                [
                    'label' => 'Unpaid invoices', 'value' => '$3,380', 'sub' => '2 open, 1 overdue',
                    'icon' => 'ki-dollar', 'tone' => 'text-warning', 'bg' => 'bg-warning/10',
                    'route' => 'accounting.invoices',
                ],
                [
                    'label' => 'Cards due', 'value' => '4', 'sub' => '1 overdue',
                    'icon' => 'ki-abstract-26', 'tone' => 'text-primary', 'bg' => 'bg-primary/10',
                    'route' => 'projects.boards',
                ],
                [
                    'label' => 'Unread mail', 'value' => '3', 'sub' => '2 from clients',
                    'icon' => 'ki-sms', 'tone' => 'text-info', 'bg' => 'bg-info/10',
                    'route' => 'mail.inbox',
                ],
                [
                    'label' => 'Sending quota', 'value' => '0 / 433', 'sub' => 'across 3 providers',
                    'icon' => 'ki-paper-plane', 'tone' => 'text-success', 'bg' => 'bg-success/10',
                    'route' => 'mail.providers',
                ],
            ],

            'agenda' => [
                ['time' => '10:00', 'title' => 'Call — Northwind scope review', 'kind' => 'meeting', 'tone' => 'bg-info'],
                ['time' => '14:00', 'title' => 'Send follow-up to Startups DE list', 'kind' => 'campaign', 'tone' => 'bg-primary'],
                ['time' => '17:00', 'title' => 'Invoice INV-0042 due to be issued', 'kind' => 'invoice', 'tone' => 'bg-warning'],
            ],

            'dueCards' => [
                ['title' => 'Fix invoice PDF margins', 'board' => 'Client Work', 'due' => 'Overdue by 2 days', 'late' => true],
                ['title' => 'Send resume to 20 agencies', 'board' => 'Outreach',    'due' => 'Due today',        'late' => false],
                ['title' => 'Build Kargah mail module', 'board' => 'Client Work',   'due' => 'Due in 3 days',    'late' => false],
            ],

            'recentMail' => [
                ['from' => 'Sam Okafor', 'subject' => 'Re: Invoice INV-0041', 'time' => '09:24', 'unread' => true],
                ['from' => 'Rita Vance', 'subject' => 'Scope change for the landing page', 'time' => 'Yesterday', 'unread' => true],
                ['from' => 'Jonas Reyes', 'subject' => 'Contract signed', 'time' => 'Jul 28', 'unread' => false],
            ],

            'quickActions' => [
                ['label' => 'New board',    'icon' => 'ki-abstract-26', 'route' => 'projects.boards'],
                ['label' => 'New campaign', 'icon' => 'ki-paper-plane',        'route' => 'mail.campaigns'],
                ['label' => 'New invoice',  'icon' => 'ki-dollar',      'route' => 'accounting.invoices'],
                ['label' => 'Save a link',  'icon' => 'ki-arrow-up-right',        'route' => 'data.links'],
                ['label' => 'Add credential','icon' => 'ki-lock',       'route' => 'data.passwords'],
                ['label' => 'Publish post', 'icon' => 'ki-share',       'route' => 'social.publish'],
            ],
        ];
    }
};

?>

<div class="flex flex-col gap-5 lg:gap-7.5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">
                {{ now()->hour < 12 ? 'Good morning' : (now()->hour < 18 ? 'Good afternoon' : 'Good evening') }}{{ auth()->user()?->name ? ', ' . str(auth()->user()->name)->before(' ') : '' }}
            </h1>
            <p class="text-sm text-secondary-foreground mt-1">Here is what needs you today.</p>
        </div>
        <select class="kt-select max-w-[170px]" wire:model.live="range">
            @foreach ($ranges as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    {{-- Headline numbers --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
        @foreach ($stats as $stat)
            <a href="{{ route($stat['route']) }}" class="kt-card hover:border-primary/40 transition-colors">
                <div class="kt-card-content flex items-start gap-4 p-5">
                    <span class="inline-flex items-center justify-center size-11 rounded-lg {{ $stat['bg'] }} {{ $stat['tone'] }} shrink-0">
                        <i class="ki-filled {{ $stat['icon'] }} text-xl"></i>
                    </span>
                    <div class="min-w-0">
                        <div class="text-2xl font-semibold text-mono leading-none">{{ $stat['value'] }}</div>
                        <div class="text-sm font-medium text-secondary-foreground mt-1.5">{{ $stat['label'] }}</div>
                        <div class="text-xs text-muted-foreground">{{ $stat['sub'] }}</div>
                    </div>
                </div>
            </a>
        @endforeach
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        {{-- Left column --}}
        <div class="col-span-12 xl:col-span-8 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Revenue and expenses</h3>
                    <a href="{{ route('accounting.reports') }}" class="kt-btn kt-btn-sm kt-btn-ghost gap-1">
                        Reports <i class="ki-filled ki-black-right text-xs"></i>
                    </a>
                </div>
                <div class="kt-card-content p-5">
                    <div id="dashboard_revenue_chart" class="min-h-[260px] flex items-center justify-center">
                        <div class="flex flex-col items-center text-center">
                            <i class="ki-filled ki-chart-line-up text-4xl text-muted-foreground mb-3"></i>
                            <p class="text-sm text-secondary-foreground">The chart fills in once invoices and expenses are stored.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Cards that need attention</h3>
                    <a href="{{ route('projects.boards') }}" class="kt-btn kt-btn-sm kt-btn-ghost gap-1">
                        Boards <i class="ki-filled ki-black-right text-xs"></i>
                    </a>
                </div>
                <div class="kt-card-content p-0 divide-y divide-border">
                    @forelse ($dueCards as $card)
                        <a href="{{ route('projects.boards') }}" class="flex items-center gap-3 px-5 py-3.5 hover:bg-accent/30 transition-colors">
                            <span class="size-2 rounded-full shrink-0 {{ $card['late'] ? 'bg-destructive' : 'bg-primary' }}"></span>
                            <span class="min-w-0 grow">
                                <span class="block text-sm text-mono truncate">{{ $card['title'] }}</span>
                                <span class="block text-xs text-muted-foreground">{{ $card['board'] }}</span>
                            </span>
                            <span class="text-xs shrink-0 {{ $card['late'] ? 'text-destructive font-medium' : 'text-muted-foreground' }}">
                                {{ $card['due'] }}
                            </span>
                        </a>
                    @empty
                        <div class="flex flex-col items-center py-12 text-center">
                            <i class="ki-filled ki-check-circle text-4xl text-success mb-3"></i>
                            <p class="text-sm text-secondary-foreground">Nothing is due. Enjoy it.</p>
                        </div>
                    @endforelse
                </div>
            </div>

        </div>

        {{-- Right column --}}
        <div class="col-span-12 xl:col-span-4 flex flex-col gap-5">

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Today</h3></div>
                <div class="kt-card-content p-5">
                    @forelse ($agenda as $item)
                        <div class="flex gap-3 pb-4 last:pb-0 relative">
                            <div class="flex flex-col items-center shrink-0">
                                <span class="size-2.5 rounded-full {{ $item['tone'] }} mt-1.5"></span>
                                @unless ($loop->last)
                                    <span class="w-px grow bg-border mt-1"></span>
                                @endunless
                            </div>
                            <div class="min-w-0 pb-1">
                                <div class="text-xs text-muted-foreground">{{ $item['time'] }}</div>
                                <div class="text-sm text-mono mt-0.5">{{ $item['title'] }}</div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-secondary-foreground text-center py-6">Nothing scheduled.</p>
                    @endforelse
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Recent mail</h3>
                    <a href="{{ route('mail.inbox') }}" class="kt-btn kt-btn-sm kt-btn-ghost gap-1">
                        Inbox <i class="ki-filled ki-black-right text-xs"></i>
                    </a>
                </div>
                <div class="kt-card-content p-0 divide-y divide-border">
                    @foreach ($recentMail as $m)
                        <a href="{{ route('mail.inbox') }}" class="flex items-start gap-3 px-5 py-3 hover:bg-accent/30 transition-colors">
                            <span class="inline-flex items-center justify-center size-8 rounded-full bg-primary/10 text-primary text-xs font-semibold shrink-0">
                                {{ strtoupper(substr($m['from'], 0, 1)) }}
                            </span>
                            <span class="min-w-0 grow">
                                <span class="block text-sm truncate {{ $m['unread'] ? 'font-semibold text-mono' : 'text-secondary-foreground' }}">{{ $m['from'] }}</span>
                                <span class="block text-xs text-muted-foreground truncate">{{ $m['subject'] }}</span>
                            </span>
                            <span class="text-[11px] text-muted-foreground shrink-0">{{ $m['time'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Quick actions</h3></div>
                <div class="kt-card-content grid grid-cols-2 gap-2 p-4">
                    @foreach ($quickActions as $action)
                        <a href="{{ route($action['route']) }}"
                           class="flex flex-col items-center justify-center gap-2 py-4 rounded-lg border border-border hover:border-primary/40 hover:bg-accent/30 transition-colors text-center">
                            <i class="ki-filled {{ $action['icon'] }} text-lg text-primary"></i>
                            <span class="text-xs text-mono leading-tight">{{ $action['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>

        </div>
    </div>
</div>
