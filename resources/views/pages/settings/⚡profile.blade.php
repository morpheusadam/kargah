<?php

use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Platform\Support\ConnectionHealth;

/**
 * Who you are, and how Kargah writes dates and times for you.
 *
 * The page used to be two cards named after the columns behind them —
 * "Identity" and "Regional" — with no word anywhere about what changing any of
 * them would do. It is now two cards named after the two questions somebody
 * arrives with, and every field carries one sentence naming what visibly
 * changes when it is altered. Those sentences are not decoration: "Language"
 * and "Date format" sit next to each other and do completely different things,
 * and the only way to tell before saving was to save.
 *
 * The time zone and date format previews are `wire:model.live` on purpose,
 * against the house default of a plain `wire:model`. They are the one case
 * where the sentence can be replaced by the thing itself — the field says
 * "changes how every date is written out" and the line underneath writes
 * today's date out that way as you pick.
 */
new
#[Title('Profile — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** The settings-nav search box. See `partials/settings-nav.blade.php`. */
    public string $settingsFilter = '';

    public string $name = '';

    public string $email = '';

    public string $timezone = 'Europe/London';

    public string $locale = 'en';

    public string $dateFormat = 'Y-m-d';

    public string $bio = '';

    public function mount(): void
    {
        $this->readFromUser();
    }

    /**
     * Load the form from the stored record.
     *
     * Its own method rather than inline in `mount()` because `discard()` needs
     * exactly the same thing — a Discard button that did not actually put the
     * stored values back was one of the two dead controls on this page.
     */
    private function readFromUser(): void
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
        $user = auth()->user();

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
            // Both of these are written by the server and by nothing on this
            // page, so an install that has neither prints an em dash rather
            // than "never" or a zero — `docs/frontend-conventions.md`, Content.
            // "Never verified" is not the same claim as "we do not know", and
            // only one of the two is true here: nothing in Kargah has ever run
            // a verification flow, so the honest answer is the dash.
            'emailConfirmed' => $user?->email_verified_at?->toDayDateTimeString() ?? ConnectionHealth::UNKNOWN,
            'accountCreated' => $user?->created_at?->toFormattedDateString() ?? ConnectionHealth::UNKNOWN,
            'initials' => $user?->initials() ?? ConnectionHealth::UNKNOWN,
            // Rendered through the picked format so the preview is the setting
            // rather than a description of it.
            'datePreview' => now($this->timezone === '' ? 'UTC' : $this->timezone)->format($this->dateFormat ?: 'Y-m-d'),
            'timePreview' => now($this->timezone === '' ? 'UTC' : $this->timezone)->format('H:i'),
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
        ], [
            // Codes are for logs; a person reading a red line under a field
            // wants the sentence — `PostPublisher::publishTarget()` sets the
            // standard for the whole application.
            'name.required' => 'A name is needed: it is what appears on invoices and in the activity log.',
            'name.max' => 'That name is longer than 120 characters, which will not fit on an invoice.',
            'email.required' => 'An email address is needed: it is what you sign in with.',
            'email.email' => 'That is not an email address Kargah can send to. It needs an @ and a domain, like nima@example.com.',
            'email.unique' => 'Another account on this install already signs in with that address.',
            'bio.max' => 'The bio is longer than 2,000 characters, which is more than an invoice footer can hold.',
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

    /** Put the stored values back. The button used to do nothing at all. */
    public function discard(): void
    {
        $this->resetValidation();
        $this->readFromUser();

        $this->toastInfo('Changes discarded', 'The form is back to what is stored.');
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

            <div>
                <h2 class="text-lg font-semibold text-mono">Profile</h2>
                <p class="text-sm text-secondary-foreground mt-1">
                    Who you are on documents Kargah produces, and the clock and calendar it reads dates against.
                </p>
            </div>

            <div class="kt-card" id="identity">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Who you are</h3>
                </div>
                <div class="kt-card-content p-5 flex flex-col gap-5">

                    <div class="flex items-center gap-4">
                        <span class="inline-flex items-center justify-center size-16 rounded-full bg-primary/10 text-primary text-xl font-semibold shrink-0">
                            {{ $initials }}
                        </span>
                        <div class="flex flex-col gap-1">
                            <span class="text-sm font-medium text-mono">Your initials are your avatar</span>
                            {{-- This card used to carry Upload and Remove buttons with no
                                 wire:click behind either. Kargah has no avatar column and no
                                 image pipeline, so the buttons could not have worked; a
                                 control that does nothing is worse than the absence of one,
                                 because it costs somebody a minute to find out. --}}
                            <span class="text-xs text-muted-foreground">
                                Kargah stores no picture. Changing your name below changes these two letters
                                everywhere they appear.
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="flex flex-col gap-1">
                            <label class="kt-form-label font-normal text-mono" for="name">Name</label>
                            <input id="name" type="text" class="kt-input @error('name') border-destructive @enderror" wire:model="name">
                            <span class="text-xs text-muted-foreground mt-1">
                                Changes the name on your avatar chip, on invoices and in the activity log.
                            </span>
                            @error('name')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="kt-form-label font-normal text-mono" for="email">Email</label>
                            <input id="email" type="email" class="kt-input @error('email') border-destructive @enderror" wire:model="email">
                            <span class="text-xs text-muted-foreground mt-1">
                                Changes the address you sign in with and the one notification email is sent to.
                                Saving a new address marks it unverified again.
                            </span>
                            @error('email')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                        </div>
                    </div>

                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label font-normal text-mono" for="bio">Short bio</label>
                        <textarea id="bio" class="kt-textarea min-h-[90px] @error('bio') border-destructive @enderror"
                                  placeholder="Freelance developer, London. Invoices from Kargah." wire:model="bio"></textarea>
                        <span class="text-xs text-muted-foreground mt-1">
                            Changes the paragraph printed under your name on invoices and proposals.
                        </span>
                        @error('bio')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>

                </div>
            </div>

            <div class="kt-card" id="regional">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">How dates and times read</h3>
                </div>
                <div class="kt-card-content p-5 flex flex-col gap-5">

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div class="flex flex-col gap-1">
                            <label class="kt-form-label font-normal text-mono" for="timezone">Time zone</label>
                            <select id="timezone" class="kt-select" wire:model.live="timezone">
                                @foreach ($timezones as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <span class="text-xs text-muted-foreground mt-1">
                                Changes the clock every due date, invoice date and "last active" time is read against.
                            </span>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="kt-form-label font-normal text-mono" for="locale">Language</label>
                            <select id="locale" class="kt-select" wire:model="locale">
                                @foreach ($locales as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <span class="text-xs text-muted-foreground mt-1">
                                Changes the language of Kargah's own labels and buttons. It does not translate anything you typed.
                            </span>
                        </div>
                        <div class="flex flex-col gap-1">
                            <label class="kt-form-label font-normal text-mono" for="dateFormat">Date format</label>
                            <select id="dateFormat" class="kt-select" wire:model.live="dateFormat">
                                @foreach ($dateFormats as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <span class="text-xs text-muted-foreground mt-1">
                                Changes how every date on every page is written out.
                            </span>
                        </div>
                    </div>

                    <div class="rounded-lg bg-muted px-4 py-3 flex flex-wrap items-center gap-2">
                        <i class="ki-filled ki-calendar text-secondary-foreground"></i>
                        <span class="text-sm text-secondary-foreground">
                            With these two, today reads
                            <strong class="text-mono">{{ $datePreview }}</strong>
                            and the current time is
                            <strong class="text-mono">{{ $timePreview }}</strong>.
                        </span>
                    </div>

                </div>
            </div>

            <div class="kt-card">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">This account</h3>
                </div>
                <div class="kt-card-content p-5 grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs text-muted-foreground">Email confirmed</span>
                        <span class="text-sm text-mono">{{ $emailConfirmed }}</span>
                        <span class="text-xs text-muted-foreground">
                            Kargah has no confirmation email to send, so on a self-hosted install this stays unknown.
                        </span>
                    </div>
                    <div class="flex flex-col gap-0.5">
                        <span class="text-xs text-muted-foreground">Account created</span>
                        <span class="text-sm text-mono">{{ $accountCreated }}</span>
                        <span class="text-xs text-muted-foreground">Written once, when the account was made. Nothing on this page changes it.</span>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button class="kt-btn kt-btn-ghost" wire:click="discard" wire:loading.attr="disabled" wire:target="discard">
                    <span wire:loading.remove wire:target="discard">Discard</span>
                    <span wire:loading wire:target="discard" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Discarding…
                    </span>
                </button>
                <button class="kt-btn kt-btn-primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                    <span wire:loading.remove wire:target="save">Save changes</span>
                    <span wire:loading wire:target="save" class="inline-flex items-center gap-2">
                        <i class="ki-filled ki-loading animate-spin"></i> Saving…
                    </span>
                </button>
            </div>

        </div>
    </div>
</div>
