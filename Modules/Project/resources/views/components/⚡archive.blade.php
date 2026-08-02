<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Archive — Kargah')]
class extends Component
{
    public string $search = '';

    public function with(): array
    {
        return [
            'items' => [
                ['title' => 'Migrate old invoices to new format', 'board' => 'Client Work', 'archived' => '2026-07-12'],
                ['title' => 'Cold email A/B subject test',        'board' => 'Outreach',    'archived' => '2026-06-28'],
                ['title' => 'Set up SPF and DKIM records',        'board' => 'Outreach',    'archived' => '2026-06-15'],
            ],
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Archive</h1>
            <p class="text-sm text-secondary-foreground mt-1">Cards you closed but did not delete.</p>
        </div>
        <div class="kt-input max-w-[260px]">
            <i class="ki-filled ki-magnifier text-muted-foreground"></i>
            <input type="text" placeholder="Search archive…" wire:model.live.debounce.300ms="search">
        </div>
    </div>

    <div class="kt-card">
        <div class="kt-card-table">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table align-middle text-sm">
                    <thead>
                        <tr>
                            <th class="min-w-[300px]">Card</th>
                            <th class="w-[160px]">Board</th>
                            <th class="w-[140px]">Archived</th>
                            <th class="w-[100px] text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr>
                                <td class="text-mono font-medium">{{ $item['title'] }}</td>
                                <td><span class="kt-badge kt-badge-sm kt-badge-outline">{{ $item['board'] }}</span></td>
                                <td class="text-secondary-foreground">{{ $item['archived'] }}</td>
                                <td class="text-end">
                                    <button class="kt-btn kt-btn-sm kt-btn-outline gap-1">
                                        <i class="ki-filled ki-arrow-circle-left text-sm"></i> Restore
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="text-center py-10 text-secondary-foreground">Nothing archived yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
