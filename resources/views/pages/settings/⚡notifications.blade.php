<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Notifications — Kargah')]
class extends Component
{
    public array $prefs = [];

    public string $digest = 'daily';

    public bool $quietHours = false;

    public string $quietFrom = '22:00';

    public string $quietTo = '08:00';

    public function mount(): void
    {
        foreach ($this->rows() as $row) {
            $this->prefs[$row['key']] = $row['default'];
        }
    }

    private function rows(): array
    {
        return [
            ['key' => 'card_due',       'group' => 'Projects',   'label' => 'A card is due today',            'default' => ['app' => true,  'email' => false]],
            ['key' => 'card_assigned',  'group' => 'Projects',   'label' => 'A card is assigned to me',       'default' => ['app' => true,  'email' => false]],
            ['key' => 'mail_received',  'group' => 'Mail',       'label' => 'New message in the inbox',       'default' => ['app' => true,  'email' => false]],
            ['key' => 'campaign_done',  'group' => 'Mail',       'label' => 'A campaign finishes sending',    'default' => ['app' => true,  'email' => true]],
            ['key' => 'bounce_spike',   'group' => 'Mail',       'label' => 'Bounce rate crosses 2%',         'default' => ['app' => true,  'email' => true]],
            ['key' => 'quota_low',      'group' => 'Mail',       'label' => 'A provider is near its quota',   'default' => ['app' => true,  'email' => true]],
            ['key' => 'invoice_paid',   'group' => 'Accounting', 'label' => 'An invoice is paid',             'default' => ['app' => true,  'email' => true]],
            ['key' => 'invoice_late',   'group' => 'Accounting', 'label' => 'An invoice goes overdue',        'default' => ['app' => true,  'email' => true]],
            ['key' => 'backup_failed',  'group' => 'Data',       'label' => 'A backup fails',                 'default' => ['app' => true,  'email' => true]],
            ['key' => 'post_failed',    'group' => 'Social',     'label' => 'A scheduled post fails',         'default' => ['app' => true,  'email' => true]],
        ];
    }

    public function with(): array
    {
        return ['groups' => collect($this->rows())->groupBy('group')];
    }
};

?>

<div class="flex flex-col gap-5">

    <div>
        <h1 class="text-xl font-semibold text-mono">Settings</h1>
        <p class="text-sm text-secondary-foreground mt-1">How Kargah behaves for you.</p>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        <div class="col-span-12 lg:col-span-3">
            @include('partials.settings-nav')
        </div>

        <div class="col-span-12 lg:col-span-9 flex flex-col gap-5">

            @foreach ($groups as $groupName => $rows)
                <div class="kt-card">
                    <div class="kt-card-header"><h3 class="kt-card-title">{{ $groupName }}</h3></div>
                    <div class="kt-card-table">
                        <div class="kt-scrollable-x-auto">
                            <table class="kt-table align-middle text-sm">
                                <thead>
                                    <tr>
                                        <th class="min-w-[280px]">Event</th>
                                        <th class="w-[110px] text-center">In app</th>
                                        <th class="w-[110px] text-center">Email</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($rows as $row)
                                        <tr>
                                            <td class="text-mono">{{ $row['label'] }}</td>
                                            <td class="text-center">
                                                <label class="kt-switch">
                                                    <input type="checkbox" wire:model="prefs.{{ $row['key'] }}.app">
                                                </label>
                                            </td>
                                            <td class="text-center">
                                                <label class="kt-switch">
                                                    <input type="checkbox" wire:model="prefs.{{ $row['key'] }}.email">
                                                </label>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Delivery</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-5">

                    <div class="flex flex-col gap-1 max-w-[280px]">
                        <label class="kt-form-label font-normal text-mono">Email digest</label>
                        <select class="kt-select" wire:model="digest">
                            <option value="instant">Send each one immediately</option>
                            <option value="daily">Daily summary</option>
                            <option value="weekly">Weekly summary</option>
                            <option value="off">No email at all</option>
                        </select>
                    </div>

                    <label class="flex items-center justify-between gap-4">
                        <span>
                            <span class="block text-sm font-medium text-mono">Quiet hours</span>
                            <span class="block text-xs text-muted-foreground">Hold notifications overnight and deliver them in the morning.</span>
                        </span>
                        <span class="kt-switch shrink-0"><input type="checkbox" wire:model.live="quietHours"></span>
                    </label>

                    @if ($quietHours)
                        <div class="flex items-center gap-3">
                            <input type="time" class="kt-input max-w-[140px]" wire:model="quietFrom">
                            <span class="text-sm text-muted-foreground">to</span>
                            <input type="time" class="kt-input max-w-[140px]" wire:model="quietTo">
                        </div>
                    @endif

                </div>
            </div>

        </div>
    </div>
</div>
