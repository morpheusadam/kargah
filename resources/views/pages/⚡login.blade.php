<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

new
#[Layout('layouts::guest')]
#[Title('Sign in — Kargah')]
class extends Component
{
    use InteractsWithToasts;

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
            $seconds = RateLimiter::availableIn($this->throttleKey());

            $this->toastError('Too many attempts', "Try again in {$seconds} seconds.");

            throw ValidationException::withMessages([
                'email' => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => $seconds]),
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

        $this->flashToast('success', 'Welcome back', 'Signed in to Kargah.');

        return redirect()->intended(route('dashboard'));
    }
};

?>

<div class="kargah-auth min-h-screen w-full flex items-center justify-center p-6 relative overflow-hidden">

    {{-- Ambient light. Purely decorative, hidden from assistive technology. --}}
    <div class="kargah-aurora" aria-hidden="true">
        <span class="kargah-blob kargah-blob-a"></span>
        <span class="kargah-blob kargah-blob-b"></span>
        <span class="kargah-blob kargah-blob-c"></span>
    </div>
    <div class="kargah-grid" aria-hidden="true"></div>

    <div class="relative w-full max-w-[420px]">

        <div class="flex items-center justify-center gap-2.5 mb-8">
            <span class="kargah-mark inline-flex items-center justify-center size-10 rounded-xl font-bold text-[15px]">K</span>
            <span class="text-lg font-semibold text-mono tracking-tight">Kargah</span>
        </div>

        {{-- The glow ring lives on the wrapper so it can rotate behind the card. --}}
        <div class="kargah-glow-frame">
            <div class="kargah-card p-8 sm:p-10">

                <div class="mb-7">
                    <h1 class="text-[22px] font-semibold text-mono leading-tight">Sign in</h1>
                    <p class="text-sm text-secondary-foreground mt-1.5">Your whole practice, behind one login.</p>
                </div>

                <form wire:submit="login" class="flex flex-col gap-5">

                    @error('email')
                        <div class="flex items-start gap-2.5 rounded-lg border border-destructive/30 bg-destructive/[0.07] px-3.5 py-3">
                            <i class="ki-filled ki-information-2 text-destructive text-base shrink-0 mt-0.5"></i>
                            <span class="text-sm text-destructive">{{ $message }}</span>
                        </div>
                    @enderror

                    <div class="flex flex-col gap-1.5">
                        <label class="text-sm font-medium text-mono" for="email">Email</label>
                        <div class="kargah-field @error('email') kargah-field-invalid @enderror">
                            <i class="ki-filled ki-sms"></i>
                            <input id="email"
                                   type="email"
                                   autocomplete="username"
                                   inputmode="email"
                                   placeholder="you@example.com"
                                   wire:model="email"
                                   autofocus>
                        </div>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <div class="flex items-center justify-between">
                            <label class="text-sm font-medium text-mono" for="password">Password</label>
                            <a href="#" class="text-xs text-secondary-foreground hover:text-primary transition-colors">Forgot?</a>
                        </div>
                        <div class="kargah-field @error('password') kargah-field-invalid @enderror" data-kt-toggle-password="true">
                            <i class="ki-filled ki-lock"></i>
                            <input id="password"
                                   type="password"
                                   autocomplete="current-password"
                                   placeholder="••••••••••••"
                                   wire:model="password">
                            <button type="button"
                                    class="kargah-field-action"
                                    data-kt-toggle-password-trigger="true"
                                    aria-label="Show password">
                                <i class="ki-filled ki-eye toggle-password-active:hidden"></i>
                                <i class="ki-filled ki-eye-slash hidden toggle-password-active:block"></i>
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
                            class="kargah-submit"
                            wire:loading.attr="disabled"
                            wire:target="login">
                        <span wire:loading.remove wire:target="login">Sign in</span>
                        <span wire:loading wire:target="login" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Signing in…
                        </span>
                    </button>

                </form>
            </div>
        </div>

        <p class="text-xs text-muted-foreground text-center mt-7 leading-relaxed">
            A private workspace. Sessions are logged and repeated failures are rate limited.
        </p>
    </div>

    <button type="button"
            class="kt-btn kt-btn-icon kt-btn-ghost size-9 absolute top-6 end-6 z-10"
            data-kt-toggle="html"
            data-kt-toggle-class="dark"
            title="Switch theme"
            aria-label="Switch theme">
        <i class="ki-filled ki-moon text-base hidden dark:inline"></i>
        <i class="ki-filled ki-sun text-base dark:hidden"></i>
    </button>

</div>
