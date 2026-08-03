<?php

use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

new
#[Title('Profile — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    public string $name = '';

    public string $email = '';

    public string $timezone = 'Europe/London';

    public string $locale = 'en';

    public string $dateFormat = 'Y-m-d';

    public string $bio = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->name = $user?->name ?? '';
        $this->email = $user?->email ?? '';
        $this->timezone = $user?->timezone ?? 'Europe/London';
        $this->locale = $user?->locale ?? 'en';
        $this->dateFormat = $user?->date_format ?? 'Y-m-d';
        $this->bio = $user?->bio ?? '';
    }

    public function with(): array
    {
        return [
            'timezones' => [
                'Europe/London' => 'London (GMT/BST)',
                'Europe/Berlin' => 'Berlin (CET)',
                'Asia/Tehran' => 'Tehran (IRST)',
                'Asia/Dubai' => 'Dubai (GST)',
                'America/New_York' => 'New York (ET)',
                'UTC' => 'UTC',
            ],
            'locales' => ['en' => 'English', 'fa' => 'فارسی'],
            'dateFormats' => [
                'Y-m-d' => '2026-08-02',
                'd/m/Y' => '02/08/2026',
                'm/d/Y' => '08/02/2026',
                'd M Y' => '02 Aug 2026',
            ],
        ];
    }

    /**
     * The one-user-per-install rule this page follows for the email field:
     * there is no mail-sending re-confirmation flow anywhere in Kargah, and
     * building one for a single self-hosted admin account is ceremony this
     * install does not need. Changing the address updates it immediately, but
     * honestly — `email_verified_at` is cleared, because the new address
     * genuinely has not been verified, even though nothing in this
     * application currently enforces that.
     */
    public function save(): void
    {
        $user = auth()->user();

        if ($user === null) {
            $this->toastError('You are not signed in', 'Sign in again and retry.');

            return;
        }

        $this->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'timezone' => 'required|string|max:64',
            'locale' => 'required|string|max:5',
            'dateFormat' => 'required|string|max:10',
            'bio' => 'nullable|string|max:2000',
        ]);

        $emailChanged = $this->email !== $user->email;

        $user->fill([
            'name' => $this->name,
            'email' => $this->email,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'date_format' => $this->dateFormat,
            'bio' => $this->bio,
        ]);

        if (! $user->isDirty()) {
            // Nothing changed. A save that writes nothing must not claim it did.
            $this->toastInfo('Nothing to save', 'The form matches what is already stored.');

            return;
        }

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        activity('profile')
            ->performedOn($user)
            ->causedBy($user)
            ->event('profile.updated')
            ->withProperties(['fields' => array_keys($user->getChanges())])
            ->log('updated their profile');

        $this->toastSuccess('Profile saved', 'Your details have been updated.');
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
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Identity</h3>
                </div>
                <div class="kt-card-content p-5 flex flex-col gap-5">

                    <div class="flex items-center gap-4">
                        <span class="inline-flex items-center justify-center size-16 rounded-full bg-primary/10 text-primary text-xl font-semibold shrink-0">
                            {{ strtoupper(substr($name ?: 'K', 0, 1)) }}
                        </span>
                        <div class="flex flex-col gap-2">
                            <div class="flex gap-2">
                                <button class="kt-btn kt-btn-sm kt-btn-outline gap-2">
                                    <i class="ki-filled ki-picture text-sm"></i> Upload
                                </button>
                                <button class="kt-btn kt-btn-sm kt-btn-ghost text-destructive">Remove</button>
                            </div>
                            <span class="text-xs text-muted-foreground">PNG or JPG, at least 256×256.</span>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1">
                            <label class="kt-form-label font-normal text-mono" for="name">Name</label>
                            <input id="name" type="text" class="kt-input @error('name') border-destructive @enderror" wire:model="name">
                            @error('name')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="kt-form-label font-normal text-mono" for="email">Email</label>
                            <input id="email" type="email" class="kt-input @error('email') border-destructive @enderror" wire:model="email">
                            @error('email')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label font-normal text-mono" for="bio">Short bio</label>
                        <textarea id="bio" class="kt-textarea min-h-[90px]" placeholder="Used on invoices and proposals." wire:model="bio"></textarea>
                    </div>

                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Regional</h3>
                </div>
                <div class="kt-card-content p-5 grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label font-normal text-mono">Time zone</label>
                        <select class="kt-select" wire:model="timezone">
                            @foreach ($timezones as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label font-normal text-mono">Language</label>
                        <select class="kt-select" wire:model="locale">
                            @foreach ($locales as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label font-normal text-mono">Date format</label>
                        <select class="kt-select" wire:model="dateFormat">
                            @foreach ($dateFormats as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button class="kt-btn kt-btn-ghost">Discard</button>
                <button class="kt-btn kt-btn-primary" wire:click="save" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="save">Save changes</span>
                    <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Saving…
                    </span>
                </button>
            </div>

        </div>
    </div>
</div>
