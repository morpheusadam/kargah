<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Contacts — Kargah')]
class extends Component
{
    public string $activeList = 'agencies-uk';

    public string $search = '';

    public function with(): array
    {
        return [
            'lists' => [
                ['key' => 'agencies-uk', 'name' => 'Agencies UK', 'count' => 240, 'suppressed' => 3],
                ['key' => 'startups-de', 'name' => 'Startups DE', 'count' => 310, 'suppressed' => 0],
                ['key' => 'leads-raw',   'name' => 'Crawler leads', 'count' => 0, 'suppressed' => 0],
            ],
            'contacts' => [
                ['email' => 'hello@studio-nord.example', 'name' => 'Studio Nord',  'status' => 'subscribed', 'added' => '2026-07-18'],
                ['email' => 'jobs@pixelforge.example',   'name' => 'Pixelforge',   'status' => 'subscribed', 'added' => '2026-07-18'],
                ['email' => 'contact@brightlab.example', 'name' => 'Brightlab',    'status' => 'bounced',    'added' => '2026-07-18'],
                ['email' => 'team@northloop.example',    'name' => 'Northloop',    'status' => 'unsubscribed', 'added' => '2026-07-18'],
            ],
            'badge' => [
                'subscribed' => 'kt-badge-success',
                'bounced' => 'kt-badge-destructive',
                'unsubscribed' => 'kt-badge-outline',
            ],
        ];
    }
};

?>

<div class="flex flex-col gap-5">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-mono">Contacts</h1>
            <p class="text-sm text-secondary-foreground mt-1">Lists, subscribers and the suppression file.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="{{ route('mail.contact-import') }}" class="kt-btn kt-btn-outline gap-2"><i class="ki-filled ki-file-up"></i> Import CSV</a>
            <button class="kt-btn kt-btn-primary gap-2"><i class="ki-filled ki-plus"></i> New list</button>
        </div>
    </div>

    <div class="grid grid-cols-12 gap-5 items-start">

        <div class="col-span-12 lg:col-span-3">
            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Lists</h3></div>
                <div class="kt-card-content p-2 flex flex-col gap-0.5">
                    @foreach ($lists as $l)
                        <button wire:click="$set('activeList', '{{ $l['key'] }}')"
                                class="kt-btn kt-btn-ghost justify-between w-full {{ $activeList === $l['key'] ? 'bg-accent/60 text-primary' : '' }}">
                            <span class="truncate text-sm">{{ $l['name'] }}</span>
                            <span class="kt-badge kt-badge-sm kt-badge-outline shrink-0">{{ $l['count'] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>

            <div class="kt-card mt-5">
                <div class="kt-card-content p-4">
                    <div class="flex items-center gap-2 text-sm text-secondary-foreground">
                        <i class="ki-filled ki-shield-cross text-destructive"></i>
                        Suppression list
                    </div>
                    <p class="text-xs text-muted-foreground mt-2">
                        Hard bounces and complaints land here and are blocked across every provider.
                    </p>
                    <a href="{{ route('mail.suppression') }}" class="kt-btn kt-btn-sm kt-btn-outline w-full justify-center mt-3">View suppressed</a>
                </div>
            </div>
        </div>

        <div class="col-span-12 lg:col-span-9">
            <div class="kt-card">
                <div class="kt-card-header">
                    <div class="kt-input max-w-[260px]">
                        <i class="ki-filled ki-magnifier text-muted-foreground"></i>
                        <input type="text" placeholder="Search contacts…" wire:model.live.debounce.300ms="search">
                    </div>
                </div>
                <div class="kt-card-table">
                    <div class="kt-scrollable-x-auto">
                        <table class="kt-table align-middle text-sm">
                            <thead>
                                <tr>
                                    <th class="min-w-[240px]">Email</th>
                                    <th class="min-w-[160px]">Name</th>
                                    <th class="w-[140px]">Status</th>
                                    <th class="w-[130px]">Added</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($contacts as $c)
                                    <tr>
                                        <td class="font-medium text-mono">{{ $c['email'] }}</td>
                                        <td class="text-secondary-foreground">{{ $c['name'] }}</td>
                                        <td><span class="kt-badge kt-badge-sm {{ $badge[$c['status']] }}">{{ ucfirst($c['status']) }}</span></td>
                                        <td class="text-secondary-foreground">{{ $c['added'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
