<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
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

    /** Five failures from one address per minute, then a cooling-off period. */
    private function throttleKey(): string
    {
        return 'login:'.mb_strtolower($this->email).'|'.request()->ip();
    }

    public function login()
    {
        $this->validate();

        if (RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            throw ValidationException::withMessages([
                'email' => __('Too many attempts. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($this->throttleKey()),
                ]),
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('Those details do not match any account.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
};

?>

<div class="w-full max-w-[380px]">

    <div class="mb-8">
        <h2 class="text-2xl font-semibold text-mono">Sign in</h2>
        <p class="text-sm text-secondary-foreground mt-1.5">Welcome back. Pick up where you left off.</p>
    </div>

    <form wire:submit="login" class="flex flex-col gap-5">

        @error('email')
            <div class="flex items-start gap-2.5 rounded-lg border border-destructive/30 bg-destructive/5 px-3.5 py-3">
                <i class="ki-filled ki-information-2 text-destructive text-base shrink-0 mt-0.5"></i>
                <span class="text-sm text-destructive">{{ $message }}</span>
            </div>
        @enderror

        <div class="flex flex-col gap-1.5">
            <label class="text-sm font-medium text-mono" for="email">Email</label>
            <input id="email"
                   type="email"
                   autocomplete="username"
                   inputmode="email"
                   placeholder="you@example.com"
                   class="kt-input @error('email') border-destructive @enderror"
                   wire:model="email"
                   autofocus>
        </div>

        <div class="flex flex-col gap-1.5">
            <div class="flex items-center justify-between">
                <label class="text-sm font-medium text-mono" for="password">Password</label>
                <a href="#" class="text-xs text-secondary-foreground hover:text-primary transition-colors">Forgot?</a>
            </div>
            <div class="kt-input @error('password') border-destructive @enderror" data-kt-toggle-password="true">
                <input id="password"
                       type="password"
                       autocomplete="current-password"
                       placeholder="••••••••••••"
                       wire:model="password">
                <button class="kt-btn kt-btn-icon kt-btn-ghost size-7" data-kt-toggle-password-trigger="true" type="button" aria-label="Show password">
                    <i class="ki-filled ki-eye text-muted-foreground toggle-password-active:hidden"></i>
                    <i class="ki-filled ki-eye-slash text-muted-foreground hidden toggle-password-active:block"></i>
                </button>
            </div>
            @error('password')
                <span class="text-xs text-destructive">{{ $message }}</span>
            @enderror
        </div>

        <label class="flex items-center gap-2.5 cursor-pointer select-none">
            <input class="kt-checkbox kt-checkbox-sm" type="checkbox" wire:model="remember">
            <span class="text-sm text-secondary-foreground">Keep me signed in</span>
        </label>

        <button type="submit"
                class="kt-btn kt-btn-primary w-full justify-center h-11"
                wire:loading.attr="disabled"
                wire:target="login">
            <span wire:loading.remove wire:target="login" class="flex items-center gap-2">
                Sign in
                <i class="ki-filled ki-black-right text-xs"></i>
            </span>
            <span wire:loading wire:target="login" class="flex items-center gap-2">
                <i class="ki-filled ki-loading animate-spin"></i> Signing in…
            </span>
        </button>

    </form>

    <p class="text-xs text-muted-foreground text-center mt-8 leading-relaxed">
        This is a private workspace. Every session is logged,<br class="hidden sm:inline">
        and repeated failures are rate limited.
    </p>
</div>
