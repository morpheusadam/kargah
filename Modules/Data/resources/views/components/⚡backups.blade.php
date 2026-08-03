<?php

use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

new
#[Title('Backups — Kargah')]
class extends Component
{
    // Held ready for the actions this page still needs. Nothing on it calls the
    // server yet — "Back up now" and the per-row downloads are plain markup with
    // no wire:click — so there is nothing here to report to the user.
    use InteractsWithToasts;

    public function with(): array
    {
        return [
            'targets' => [
                ['name' => 'Database', 'icon' => 'ki-data',   'enabled' => true,  'schedule' => 'Daily 03:00'],
                ['name' => 'Files',    'icon' => 'ki-folder', 'enabled' => true,  'schedule' => 'Weekly Sun'],
                ['name' => 'Vault',    'icon' => 'ki-lock',   'enabled' => false, 'schedule' => 'Manual'],
            ],
            'history' => [
                ['when' => '2026-08-02 03:00', 'target' => 'Database', 'size' => '1.4 MB', 'status' => 'ok'],
                ['when' => '2026-08-01 03:00', 'target' => 'Database', 'size' => '1.4 MB', 'status' => 'ok'],
                ['when' => '2026-07-28 04:00', 'target' => 'Files',    'size' => '84 MB',  'status' => 'ok'],
            ],
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Backups</h1>
            <p class="text-sm text-secondary-foreground mt-1">Scheduled by cron, stored off the web root.</p>
        </div>
        <button class="kt-btn kt-btn-primary gap-2">
            <i class="ki-filled ki-cloud-download"></i> Back up now
        </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        @foreach ($targets as $t)
            <div class="kt-card">
                <div class="kt-card-content p-5 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="inline-flex items-center justify-center size-10 rounded-lg bg-primary/10 text-primary shrink-0">
                            <i class="ki-filled {{ $t['icon'] }} text-lg"></i>
                        </span>
                        <div class="min-w-0">
                            <div class="font-semibold text-mono truncate">{{ $t['name'] }}</div>
                            <div class="text-xs text-muted-foreground">{{ $t['schedule'] }}</div>
                        </div>
                    </div>
                    <label class="kt-switch shrink-0">
                        <input type="checkbox" @checked($t['enabled'])>
                    </label>
                </div>
            </div>
        @endforeach
    </div>

    <div class="kt-card">
        <div class="kt-card-header"><h3 class="kt-card-title">History</h3></div>
        <div class="kt-card-table">
            <div class="kt-scrollable-x-auto">
                <table class="kt-table align-middle text-sm">
                    <thead>
                        <tr>
                            <th class="min-w-[180px]">When</th>
                            <th class="w-[140px]">Target</th>
                            <th class="w-[110px] text-end">Size</th>
                            <th class="w-[110px]">Status</th>
                            <th class="w-[100px] text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $h)
                            <tr>
                                <td class="font-medium text-mono">{{ $h['when'] }}</td>
                                <td><span class="kt-badge kt-badge-sm kt-badge-outline">{{ $h['target'] }}</span></td>
                                <td class="text-end text-secondary-foreground">{{ $h['size'] }}</td>
                                <td><span class="kt-badge kt-badge-sm kt-badge-success">Completed</span></td>
                                <td class="text-end">
                                    <button class="kt-btn kt-btn-sm kt-btn-outline gap-1">
                                        <i class="ki-filled ki-exit-down text-sm"></i> Download
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
