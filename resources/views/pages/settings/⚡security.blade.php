<?php

use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new
#[Title('Security — Kargah')]
class extends Component
{
    #[Validate('required|string')]
    public string $currentPassword = '';

    #[Validate('required|string|min:12|confirmed')]
    public string $password = '';

    public string $password_confirmation = '';

    public bool $twoFactor = false;

    public function with(): array
    {
        return [
            'sessions' => [
                ['device' => 'Windows · Chrome', 'ip' => '81.***.***.42', 'location' => 'London, UK', 'last' => 'Active now', 'current' => true],
                ['device' => 'Android · Chrome', 'ip' => '81.***.***.19', 'location' => 'London, UK', 'last' => '2 days ago', 'current' => false],
            ],
            'tokens' => [
                ['name' => 'Deploy script', 'scopes' => 'read', 'created' => '2026-06-11', 'lastUsed' => '2026-07-30'],
            ],
        ];
    }

    /** Persisted in the backend phase. */
    public function updatePassword(): void
    {
        $this->validate();
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

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Password</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-4 max-w-[520px]">
                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label font-normal text-mono">Current password</label>
                        <input type="password" autocomplete="current-password"
                               class="kt-input @error('currentPassword') border-destructive @enderror"
                               wire:model="currentPassword">
                        @error('currentPassword')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label font-normal text-mono">New password</label>
                        <input type="password" autocomplete="new-password"
                               class="kt-input @error('password') border-destructive @enderror"
                               wire:model="password">
                        <span class="text-xs text-muted-foreground mt-1">At least 12 characters.</span>
                        @error('password')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label font-normal text-mono">Confirm new password</label>
                        <input type="password" autocomplete="new-password" class="kt-input" wire:model="password_confirmation">
                    </div>
                    <div>
                        <button class="kt-btn kt-btn-primary" wire:click="updatePassword" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="updatePassword">Update password</span>
                            <span wire:loading wire:target="updatePassword" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-loading animate-spin"></i> Updating…
                            </span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Two-factor authentication</h3>
                    <label class="kt-switch">
                        <input type="checkbox" wire:model.live="twoFactor">
                    </label>
                </div>
                <div class="kt-card-content p-5">
                    @if ($twoFactor)
                        <div class="flex flex-col md:flex-row gap-6">
                            <div class="size-40 rounded-lg bg-muted flex items-center justify-center shrink-0">
                                <i class="ki-filled ki-scan-barcode text-4xl text-muted-foreground"></i>
                            </div>
                            <div class="flex flex-col gap-3 min-w-0">
                                <p class="text-sm text-secondary-foreground">
                                    Scan this code with an authenticator app, then enter the six-digit code to confirm.
                                </p>
                                <input type="text" inputmode="numeric" maxlength="6" placeholder="000000" class="kt-input max-w-[160px] tracking-widest text-center">
                                <button class="kt-btn kt-btn-primary self-start">Confirm and enable</button>
                            </div>
                        </div>
                    @else
                        <p class="text-sm text-secondary-foreground">
                            Off. Turning this on requires a code from an authenticator app at every sign-in.
                        </p>
                    @endif
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header"><h3 class="kt-card-title">Active sessions</h3></div>
                <div class="kt-card-table">
                    <div class="kt-scrollable-x-auto">
                        <table class="kt-table align-middle text-sm">
                            <thead>
                                <tr>
                                    <th class="min-w-[200px]">Device</th>
                                    <th class="w-[160px]">Location</th>
                                    <th class="w-[140px]">Last active</th>
                                    <th class="w-[110px] text-end"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sessions as $s)
                                    <tr>
                                        <td>
                                            <div class="font-medium text-mono">{{ $s['device'] }}</div>
                                            <div class="text-xs text-muted-foreground">{{ $s['ip'] }}</div>
                                        </td>
                                        <td class="text-secondary-foreground">{{ $s['location'] }}</td>
                                        <td>
                                            @if ($s['current'])
                                                <span class="kt-badge kt-badge-sm kt-badge-success">{{ $s['last'] }}</span>
                                            @else
                                                <span class="text-secondary-foreground">{{ $s['last'] }}</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            @unless ($s['current'])
                                                <button class="kt-btn kt-btn-sm kt-btn-ghost text-destructive">Revoke</button>
                                            @endunless
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">API tokens</h3>
                    <button class="kt-btn kt-btn-sm kt-btn-outline gap-2"><i class="ki-filled ki-plus text-sm"></i> New token</button>
                </div>
                <div class="kt-card-table">
                    <div class="kt-scrollable-x-auto">
                        <table class="kt-table align-middle text-sm">
                            <thead>
                                <tr>
                                    <th class="min-w-[180px]">Name</th>
                                    <th class="w-[120px]">Scopes</th>
                                    <th class="w-[120px]">Created</th>
                                    <th class="w-[130px]">Last used</th>
                                    <th class="w-[100px] text-end"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($tokens as $t)
                                    <tr>
                                        <td class="font-medium text-mono">{{ $t['name'] }}</td>
                                        <td><span class="kt-badge kt-badge-sm kt-badge-outline">{{ $t['scopes'] }}</span></td>
                                        <td class="text-secondary-foreground">{{ $t['created'] }}</td>
                                        <td class="text-secondary-foreground">{{ $t['lastUsed'] }}</td>
                                        <td class="text-end"><button class="kt-btn kt-btn-sm kt-btn-ghost text-destructive">Revoke</button></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-center py-10 text-secondary-foreground">No tokens issued.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
