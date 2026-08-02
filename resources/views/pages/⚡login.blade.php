<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new
#[Layout('layouts::guest')]
#[Title('Sign in — Kargah')]
class extends Component
{
    #[Validate('required|string|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = false;

    public function login()
    {
        $this->validate();

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
};

?>

<div class="w-full max-w-[400px]">
    <form wire:submit="login" class="kt-card">
        <div class="kt-card-content flex flex-col gap-5 p-10">

            <div class="text-center mb-2">
                <span class="inline-flex items-center justify-center size-12 rounded-xl bg-primary text-primary-foreground font-bold text-xl mb-4">K</span>
                <h3 class="text-lg font-semibold text-mono">Sign in to Kargah</h3>
                <p class="text-sm text-secondary-foreground mt-1">Your freelance workspace</p>
            </div>

            <div class="flex flex-col gap-1">
                <label class="kt-form-label font-normal text-mono" for="email">Email</label>
                <input
                    id="email"
                    type="email"
                    autocomplete="username"
                    placeholder="you@example.com"
                    class="kt-input @error('email') border-destructive @enderror"
                    wire:model="email"
                >
                @error('email')
                    <span class="text-xs text-destructive mt-1">{{ $message }}</span>
                @enderror
            </div>

            <div class="flex flex-col gap-1">
                <label class="kt-form-label font-normal text-mono" for="password">Password</label>
                <div class="kt-input" data-kt-toggle-password="true">
                    <input
                        id="password"
                        type="password"
                        autocomplete="current-password"
                        placeholder="Enter password"
                        wire:model="password"
                    >
                    <button class="kt-btn kt-btn-icon" data-kt-toggle-password-trigger="true" type="button">
                        <i class="ki-filled ki-eye text-muted-foreground toggle-password-active:hidden"></i>
                        <i class="ki-filled ki-eye-slash text-muted-foreground hidden toggle-password-active:block"></i>
                    </button>
                </div>
                @error('password')
                    <span class="text-xs text-destructive mt-1">{{ $message }}</span>
                @enderror
            </div>

            <label class="kt-label items-center gap-2">
                <input class="kt-checkbox kt-checkbox-sm" type="checkbox" wire:model="remember">
                <span class="kt-checkbox-label">Remember me</span>
            </label>

            <button type="submit" class="kt-btn kt-btn-primary w-full justify-center" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="login">Sign in</span>
                <span wire:loading wire:target="login" class="inline-flex items-center gap-2">
                    <i class="ki-filled ki-loading animate-spin"></i> Signing in…
                </span>
            </button>

        </div>
    </form>
</div>
