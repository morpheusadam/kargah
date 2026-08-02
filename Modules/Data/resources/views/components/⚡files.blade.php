<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Files — Kargah')]
class extends Component
{
    public string $search = '';

    public string $view = 'grid';

    public function with(): array
    {
        return [
            'folders' => [
                ['name' => 'Resumes',   'count' => 4, 'icon' => 'ki-folder'],
                ['name' => 'Contracts', 'count' => 7, 'icon' => 'ki-folder'],
                ['name' => 'Invoices',  'count' => 41, 'icon' => 'ki-folder'],
                ['name' => 'Brand',     'count' => 12, 'icon' => 'ki-folder'],
            ],
            'files' => [
                ['name' => 'resume-fullstack-2026.pdf', 'size' => '412 KB', 'type' => 'pdf',  'date' => '2026-07-30'],
                ['name' => 'northwind-contract.pdf',    'size' => '288 KB', 'type' => 'pdf',  'date' => '2026-07-22'],
                ['name' => 'kargah-logo.svg',           'size' => '14 KB',  'type' => 'svg',  'date' => '2026-07-19'],
                ['name' => 'expenses-q2.csv',           'size' => '9 KB',   'type' => 'csv',  'date' => '2026-07-05'],
            ],
            'typeIcon' => [
                'pdf' => ['ki-file', 'text-destructive'],
                'svg' => ['ki-picture', 'text-info'],
                'csv' => ['ki-file-sheet', 'text-success'],
            ],
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Files</h1>
            <p class="text-sm text-secondary-foreground mt-1">Resumes, contracts, anything you attach to work.</p>
        </div>
        <div class="flex items-center gap-2">
            <div class="kt-input max-w-[220px]">
                <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                <input type="text" placeholder="Search files…" wire:model.live.debounce.300ms="search">
            </div>
            <button class="kt-btn kt-btn-primary gap-2"><i class="ki-filled ki-file-up"></i> Upload</button>
        </div>
    </div>

    <div>
        <h3 class="text-sm font-semibold text-mono mb-3">Folders</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach ($folders as $f)
                <button class="kt-card hover:border-primary/40 transition-colors">
                    <div class="kt-card-content flex items-center gap-3 p-4">
                        <i class="ki-filled {{ $f['icon'] }} text-2xl text-warning shrink-0"></i>
                        <div class="min-w-0 text-start">
                            <div class="text-sm font-medium text-mono truncate">{{ $f['name'] }}</div>
                            <div class="text-xs text-muted-foreground">{{ $f['count'] }} items</div>
                        </div>
                    </div>
                </button>
            @endforeach
        </div>
    </div>

    <div>
        <h3 class="text-sm font-semibold text-mono mb-3">Recent files</h3>
        <div class="kt-card">
            <div class="kt-card-table">
                <div class="kt-scrollable-x-auto">
                    <table class="kt-table align-middle text-sm">
                        <thead>
                            <tr>
                                <th class="min-w-[280px]">Name</th>
                                <th class="w-[110px]">Size</th>
                                <th class="w-[130px]">Modified</th>
                                <th class="w-[90px] text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($files as $file)
                                @php [$icon, $tone] = $typeIcon[$file['type']] ?? ['ki-file', 'text-muted-foreground']; @endphp
                                <tr>
                                    <td>
                                        <span class="flex items-center gap-2.5">
                                            <i class="ki-filled {{ $icon }} {{ $tone }} text-lg"></i>
                                            <span class="font-medium text-mono truncate">{{ $file['name'] }}</span>
                                        </span>
                                    </td>
                                    <td class="text-secondary-foreground">{{ $file['size'] }}</td>
                                    <td class="text-secondary-foreground">{{ $file['date'] }}</td>
                                    <td class="text-end">
                                        <button class="kt-btn kt-btn-icon kt-btn-ghost size-7"><i class="ki-filled ki-exit-down text-sm"></i></button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
