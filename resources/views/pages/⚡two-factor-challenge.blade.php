<?php

use App\Support\Totp;
use App\Support\TwoFactorChallenge;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;

/**
 * The second half of signing in: a code, before there is a session.
 *
 * The password form does not call `Auth::login()` when the account has a
 * confirmed second factor. It calls `Auth::validate()`, parks an id and an
 * expiry in the session — see `App\Support\TwoFactorChallenge` — and sends the
 * person here. Nothing behind the `auth` middleware is reachable in that state,
 * so a stolen password on its own reaches this page and stops.
 *
 * Either credential is accepted: a six-digit code from the authenticator app,
 * or one of the ten recovery codes issued at enrolment. The TOTP window is the
 * one `App\Support\Totp::verify()` already uses at enrolment — ±1 step, 30
 * seconds either side of now, and no wider. A recovery code is consumed by
 * `User::consumeRecoveryCode()`, which burns it against the stored hash and
 * saves; it works once and the count that is left is said out loud, because
 * "you have two left" is the warning that gets somebody to print new ones.
 *
 * **Rate limited, per account and IP.** Six digits is a million guesses and a
 * 30-second window is not a defence on its own; without a ceiling this would be
 * weaker than the password it is meant to strengthen. Five failures a minute,
 * the same shape `Modules\Platform`'s application-password middleware uses.
 *
 * ## Losing both the app and the codes
 *
 * There is no email reset here, and there is not going to be: Kargah is
 * self-hosted and single-user, and an emailed bypass would just be a second,
 * weaker password on the same account. The answer is the shell:
 *
 *     php artisan two-factor:disable admin@admin.com
 *
 * That clears the secret, the recovery codes and the confirmation, and the next
 * sign-in is password-only again — `App\Console\Commands\DisableTwoFactor`.
 * Whoever can run artisan can already read the database and the `APP_KEY`, so
 * this hands out no authority that person did not have; it just means a lost
 * phone costs a shell command rather than the account. Somebody with no shell
 * access and no recovery codes cannot get in, which is the honest consequence
 * of turning on a second factor and is why the recovery codes are shown once,
 * loudly, at enrolment.
 */
new
#[Layout('layouts::guest')]
#[Title('Two-factor authentication — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** Failed codes per minute, per account and IP, before the door closes. */
    private const MAX_FAILURES = 5;

    private const DECAY = 60;

    public string $code = '';

    /** Which of the two credentials the form is currently asking for. */
    public bool $usingRecoveryCode = false;

    public function mount()
    {
        if (! TwoFactorChallenge::isPending()) {
            return redirect()->route('login');
        }
    }

    public function with(): array
    {
        return [
            'challengedEmail' => TwoFactorChallenge::user()?->email,
        ];
    }

    /**
     * Keyed on the account and the address, not on the code.
     *
     * The account id comes from the session and is only there because a correct
     * password put it there, so this cannot be used to enumerate anything — the
     * limiter never sees a request from somebody who has not already got the
     * password right.
     */
    private function throttleKey(int $userId): string
    {
        return 'two-factor-challenge:'.$userId.'|'.request()->ip();
    }

    public function useRecoveryCode(): void
    {
        $this->usingRecoveryCode = true;
        $this->code = '';
        $this->resetValidation();
    }

    public function useAuthenticatorApp(): void
    {
        $this->usingRecoveryCode = false;
        $this->code = '';
        $this->resetValidation();
    }

    /** Abandon the half-finished sign-in and go back to the password form. */
    public function cancel()
    {
        TwoFactorChallenge::forget();

        return redirect()->route('login');
    }

    public function verify()
    {
        // Re-read the challenge on every action rather than trusting the page
        // that rendered. Livewire's update requests do not carry the route's
        // own middleware, so this — not `RequireTwoFactorChallenge` — is what
        // stops a tab left open past the expiry from verifying anything.
        $user = TwoFactorChallenge::user();

        if ($user === null) {
            TwoFactorChallenge::forget();

            $this->flashToast('error', 'That took too long', 'Sign in again to get a fresh prompt.');

            return redirect()->route('login');
        }

        $this->validate(
            ['code' => 'required|string'],
            [],
            ['code' => $this->usingRecoveryCode ? 'recovery code' : 'code'],
        );

        $limiterKey = $this->throttleKey($user->id);

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_FAILURES)) {
            throw ValidationException::withMessages([
                'code' => __('Too many attempts. Try again in :seconds seconds.', [
                    'seconds' => RateLimiter::availableIn($limiterKey),
                ]),
            ]);
        }

        if (! $user->hasTwoFactorEnabled()) {
            // Turned off from another session between the password and the
            // code. There is now no second factor to prove, and no honest way
            // to accept one, so the sign-in restarts rather than guessing.
            TwoFactorChallenge::forget();

            $this->flashToast('info', 'Two-factor is no longer on', 'Sign in with your password.');

            return redirect()->route('login');
        }

        $passed = $this->usingRecoveryCode
            ? $user->consumeRecoveryCode($this->code)
            : Totp::verify($user->two_factor_secret, $this->code);

        if (! $passed) {
            RateLimiter::hit($limiterKey, self::DECAY);

            $this->code = '';

            throw ValidationException::withMessages([
                'code' => $this->usingRecoveryCode
                    ? __('That recovery code is not one of yours, or it has already been used.')
                    : __('That code is not correct, or it has already expired. Use the next one your app shows.'),
            ]);
        }

        RateLimiter::clear($limiterKey);

        $remaining = null;

        if ($this->usingRecoveryCode) {
            $remaining = count($user->two_factor_recovery_codes);

            activity('security')
                ->performedOn($user)
                ->causedBy($user)
                ->event('security.two-factor-recovery-code-used')
                ->withProperties(['remaining' => $remaining])
                ->log('signed in with a recovery code');
        }

        $remember = TwoFactorChallenge::remember();

        // Clear the half-authenticated state *before* the real one is created,
        // so no request ever holds both.
        TwoFactorChallenge::forget();

        Auth::login($user, $remember);

        // The same regeneration the password-only path does, for the same
        // reason: the id that carried the challenge must not carry the session.
        session()->regenerate();

        $this->code = '';

        if ($remaining === null) {
            $this->flashToast('success', 'Welcome back', 'Signed in to Kargah.');
        } else {
            $this->flashToast(
                $remaining === 0 ? 'warning' : 'success',
                'Recovery code used',
                $remaining === 0
                    ? 'That was your last one. Generate new recovery codes on the security page now.'
                    : $remaining.' '.($remaining === 1 ? 'code is' : 'codes are').' left. Each one works once.',
            );
        }

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

        <div class="kargah-glow-frame">
            <div class="kargah-card p-8 sm:p-10">

                <div class="mb-7">
                    <h1 class="text-[22px] font-semibold text-mono leading-tight">Two-factor authentication</h1>
                    <p class="text-sm text-secondary-foreground mt-1.5">
                        @if ($usingRecoveryCode)
                            Enter one of the recovery codes you saved when you turned this on.
                        @else
                            Enter the six-digit code your authenticator app is showing for
                            {{ $challengedEmail ?? 'this account' }}.
                        @endif
                    </p>
                </div>

                <form wire:submit="verify" class="flex flex-col gap-5">

                    @error('code')
                        <div class="flex items-start gap-2.5 rounded-lg border border-destructive/30 bg-destructive/[0.07] px-3.5 py-3">
                            <i class="ki-filled ki-information-2 text-destructive text-base shrink-0 mt-0.5"></i>
                            <span class="text-sm text-destructive">{{ $message }}</span>
                        </div>
                    @enderror

                    <div class="flex flex-col gap-1.5">
                        <label class="text-sm font-medium text-mono" for="code">
                            {{ $usingRecoveryCode ? 'Recovery code' : 'Authentication code' }}
                        </label>
                        <div class="kargah-field @error('code') kargah-field-invalid @enderror">
                            <i class="ki-filled {{ $usingRecoveryCode ? 'ki-key' : 'ki-shield-tick' }}"></i>
                            @if ($usingRecoveryCode)
                                <input id="code"
                                       type="text"
                                       autocomplete="one-time-code"
                                       maxlength="11"
                                       placeholder="abcde-fghij"
                                       wire:model="code"
                                       autofocus>
                            @else
                                <input id="code"
                                       type="text"
                                       autocomplete="one-time-code"
                                       inputmode="numeric"
                                       maxlength="6"
                                       placeholder="000000"
                                       wire:model="code"
                                       autofocus>
                            @endif
                        </div>
                    </div>

                    <button type="submit"
                            class="kargah-submit"
                            wire:loading.attr="disabled"
                            wire:target="verify">
                        <span wire:loading.remove wire:target="verify">Verify</span>
                        <span wire:loading wire:target="verify" class="inline-flex items-center gap-2">
                            <i class="ki-filled ki-loading animate-spin"></i> Checking…
                        </span>
                    </button>

                    <div class="flex items-center justify-between gap-3 text-xs">
                        @if ($usingRecoveryCode)
                            <button type="button" class="text-secondary-foreground hover:text-primary transition-colors"
                                    wire:click="useAuthenticatorApp">
                                Use your authenticator app instead
                            </button>
                        @else
                            <button type="button" class="text-secondary-foreground hover:text-primary transition-colors"
                                    wire:click="useRecoveryCode">
                                Use a recovery code instead
                            </button>
                        @endif

                        <button type="button" class="text-secondary-foreground hover:text-primary transition-colors"
                                wire:click="cancel">
                            Back to sign in
                        </button>
                    </div>

                </form>
            </div>
        </div>

        <p class="text-xs text-muted-foreground text-center mt-7 leading-relaxed">
            Lost both your app and your codes? Two-factor can only be cleared from the server's
            command line — there is no reset link by design.
        </p>
    </div>

</div>
