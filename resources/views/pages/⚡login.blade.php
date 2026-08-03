<?php

use App\Models\User;
use App\Support\TwoFactorChallenge;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * The password half of signing in.
 *
 * A correct password is not a session when the account has a **confirmed**
 * second factor. That is why this calls `Auth::validate()` rather than
 * `Auth::attempt()`: `validate()` checks the credentials and hands back the
 * matched row without touching the session, so the two-factor branch can be
 * taken before anybody is logged in. `pages::two-factor-challenge` finishes
 * the job, and `App\Support\TwoFactorChallenge` is the only thing that
 * survives in between — an id and an expiry, no session, no user model.
 *
 * Enrolment that was started and never confirmed does not count:
 * `hasTwoFactorEnabled()` reads `two_factor_confirmed_at`, so an abandoned
 * setup cannot lock the owner behind a code their app never received.
 */
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

        if (! Auth::validate(['email' => $this->email, 'password' => $this->password])) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => __('Those details do not match any account.'),
            ]);
        }

        /** @var User $user The row `validate()` just matched. */
        $user = Auth::getLastAttempted();

        RateLimiter::clear($this->throttleKey());

        // The plaintext is spent. It is cleared either way, but it matters most
        // on the branch that redirects: a public Livewire property is posted
        // back on every round trip, and the challenge page is several of them.
        $this->password = '';

        if ($user->hasTwoFactorEnabled()) {
            TwoFactorChallenge::begin($user, $this->remember);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $this->remember);
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

        <div class="flex items-center justify-center mb-8">
            <x-brand-mark :size="14" :glow="true" name-class="text-lg font-semibold text-mono tracking-tight" />
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

    {{-- No theme toggle here on purpose: the signed-out screens have one theme. --}}

</div>
