<?php

use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Component;
use Modules\Core\Concerns\InteractsWithToasts;
use Modules\Platform\Support\ConnectionHealth;

/**
 * Password, two-factor authentication and active sessions — all real.
 *
 * **Two-factor enrolment lives here; enforcement lives at the login form.** A
 * secret is generated, shown for manual entry (no QR image — no QR library is
 * installed, and every authenticator app accepts a typed setup key), and is
 * not trusted until a real code from it has been checked — `hasTwoFactorEnabled()`
 * reads `two_factor_confirmed_at`, never merely "a secret exists". That same
 * method is what `pages::login` branches on: a confirmed factor turns a correct
 * password into a pending challenge rather than a session, and
 * `pages::two-factor-challenge` takes the code or one recovery code. So the
 * sentence this page prints — "sign-in asks for a code" — is now true, and
 * turning it off here really does make the next sign-in password-only.
 *
 * The lockout that made enforcement a separate change is answered by
 * `php artisan two-factor:disable <email>`, which is the only way back for
 * somebody who has lost both their app and their codes. See
 * `App\Console\Commands\DisableTwoFactor`.
 *
 * **Sessions are the real `sessions` table**, because `SESSION_DRIVER=database`
 * on this install. A page that invents devices when the table is genuinely
 * empty is worse than a page with no sessions panel at all — see
 * `project-guaid/DECISIONS.md`. Whether that table exists is now asked once, in
 * `Modules\Platform\Support\ConnectionHealth::sessionStore()`, so the panel and
 * the sentence explaining its absence cannot disagree.
 *
 * **API tokens are not here.** `/settings/application-passwords` is the real,
 * hashed, scoped, revocable credential store; a second, fake token list next
 * to it would tell an owner they hold a credential they do not.
 *
 * ⚠️ **Every destructive button on this page names its consequence in the
 * confirmation, not in the tooltip.** "Are you sure?" is a question nobody can
 * answer; "your recovery codes stop working" is. The four below are the four
 * actions on this page that cannot be undone by pressing the same button again.
 */
new
#[Title('Security — Kargah')]
class extends Component
{
    use InteractsWithToasts;

    /** The settings-nav search box. See `partials/settings-nav.blade.php`. */
    public string $settingsFilter = '';

    public string $currentPassword = '';

    public string $password = '';

    public string $password_confirmation = '';

    /** Whether the enrolment panel — secret, manual-entry key, code field — is open. */
    public bool $enrolling2fa = false;

    public string $totpCode = '';

    /**
     * Freshly generated recovery codes, shown once.
     *
     * Protected, the same reason `Modules\Platform`'s `$issuedSecret` is: a
     * public property is serialised into the page and posted back on every
     * round trip, so a value held in one would sit in the browser's memory
     * and the back button for as long as the tab stayed open. This exists
     * only for the request that generated it.
     *
     * @var list<string>|null
     */
    protected ?array $issuedRecoveryCodes = null;

    public function with(): array
    {
        $user = auth()->user();

        return [
            'twoFactorEnabled' => $user?->hasTwoFactorEnabled() ?? false,
            'provisioningUri' => $this->enrolling2fa && $user?->two_factor_secret !== null
                ? Totp::provisioningUri($user->two_factor_secret, $user->email)
                : null,
            'formattedSecret' => $this->enrolling2fa && $user?->two_factor_secret !== null
                ? Totp::formatForDisplay($user->two_factor_secret)
                : null,
            'issuedRecoveryCodes' => $this->issuedRecoveryCodes,
            'sessions' => $this->sessions($user),
            'sessionStore' => ConnectionHealth::sessionStore(),
            'unknown' => ConnectionHealth::UNKNOWN,
            'applicationPasswordsRoute' => Route::has('platform.application-passwords') ? route('platform.application-passwords') : null,
        ];
    }

    /* Sessions ---------------------------------------------------------------- */

    /**
     * Whether there is a real `sessions` table to read.
     *
     * Delegates to `ConnectionHealth` rather than asking `config()` and
     * `Schema` again: this page renders the answer as a health line at the top
     * of the panel, and a panel that listed rows while the line above it said
     * there were none would be two answers to one question.
     */
    private function sessionsAvailable(): bool
    {
        return ConnectionHealth::sessionStore()['state'] === 'healthy';
    }

    /**
     * @return list<array{id: string, device: string, ip: ?string, last: string, current: bool}>
     */
    private function sessions(?User $user): array
    {
        if ($user === null || ! $this->sessionsAvailable()) {
            return [];
        }

        $currentId = session()->getId();

        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'device' => $this->describeUserAgent($row->user_agent),
                'ip' => $row->ip_address,
                'last' => $row->id === $currentId ? 'Active now' : now()->setTimestamp((int) $row->last_activity)->diffForHumans(),
                'current' => $row->id === $currentId,
            ])
            ->all();
    }

    /** A rough, dependency-free "OS · Browser" label. Good enough to tell two rows apart. */
    private function describeUserAgent(?string $userAgent): string
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return 'Unknown device';
        }

        $os = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Macintosh') || str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => 'Unknown OS',
        };

        // Order matters: Edge and Chrome both carry a "Safari" token, and
        // Chrome's user agent also carries "Edg" briefly during a rebrand
        // check on some builds, so the more specific match has to come first.
        $browser = match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') || str_contains($userAgent, 'Opera') => 'Opera',
            str_contains($userAgent, 'Firefox') => 'Firefox',
            str_contains($userAgent, 'Chrome') => 'Chrome',
            str_contains($userAgent, 'Safari') => 'Safari',
            default => 'Unknown browser',
        };

        return $os.' · '.$browser;
    }

    public function signOutSession(string $sessionId): void
    {
        $user = auth()->user();

        if ($user === null || $sessionId === session()->getId()) {
            // Signing out the current session through this button would leave
            // the page claiming success while the request that ran it is
            // itself the thing it just destroyed. Use "log out" for that.
            $this->toastError('Cannot sign that one out here', 'Use "Log out" to end your own session.');

            return;
        }

        $deleted = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('id', $sessionId)
            ->delete();

        if ($deleted === 0) {
            $this->toastWarning('Already gone', 'That session had already ended.');

            return;
        }

        activity('security')
            ->performedOn($user)
            ->causedBy($user)
            ->event('security.session-revoked')
            ->log('signed out a session');

        $this->toastSuccess('Session signed out', 'That device will need to sign in again.');
    }

    public function signOutOtherSessions(): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $deleted = DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('id', '!=', session()->getId())
            ->delete();

        if ($deleted === 0) {
            $this->toastWarning('Nothing to sign out', 'This is the only active session.');

            return;
        }

        activity('security')
            ->performedOn($user)
            ->causedBy($user)
            ->event('security.sessions-revoked-others')
            ->withProperties(['count' => $deleted])
            ->log('signed out every other session');

        $this->toastSuccess('Signed out everywhere else', $deleted.' '.($deleted === 1 ? 'session' : 'sessions').' ended.');
    }

    /* Password ------------------------------------------------------------------ */

    public function updatePassword(): void
    {
        $user = auth()->user();

        if ($user === null) {
            $this->toastError('You are not signed in', 'Sign in again and retry.');

            return;
        }

        $this->validate([
            'currentPassword' => 'required|string',
            'password' => ['required', 'string', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()],
        ], [
            // Laravel's own password messages are a list of rule names. These
            // say what was wrong with what was typed — the standard the rest
            // of Kargah's errors are held to.
            'currentPassword.required' => 'Type your current password as well, so nobody who finds this page open can change it.',
            'password.required' => 'Type the new password you want.',
            'password.confirmed' => 'The two new passwords do not match. Retype the confirmation.',
        ]);

        if (! Hash::check($this->currentPassword, $user->password)) {
            $this->addError('currentPassword', 'That is not your current password.');

            return;
        }

        // The plaintext the moment it exists, and no longer: it is spent on
        // the very next two lines and never assigned anywhere else.
        $newPassword = $this->password;

        $user->forceFill(['password' => Hash::make($newPassword)])->save();

        activity('security')
            ->performedOn($user)
            ->causedBy($user)
            ->event('security.password-changed')
            ->log('changed the account password');

        // Auth::logoutOtherDevices() rotates the remember-me token and forces
        // a fresh password hash the AuthenticateSession middleware compares
        // against — but that middleware is not registered in this install
        // (see the report), so the actual invalidation for a `database`
        // session store is the row deletion right after it. Both run: the
        // first is the idiomatic Laravel signal, the second is what actually
        // ends the other sessions here today.
        Auth::guard()->logoutOtherDevices($newPassword);

        DB::table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)
            ->where('id', '!=', session()->getId())
            ->delete();

        $this->currentPassword = '';
        $this->password = '';
        $this->password_confirmation = '';

        $this->toastSuccess('Password changed', 'Every other session was signed out.');
    }

    /* Two-factor authentication --------------------------------------------------- */

    /**
     * Step one: generate a secret and show it for manual entry. Not trusted
     * yet — `two_factor_confirmed_at` stays null, so `hasTwoFactorEnabled()`
     * still reads false, until `confirmTwoFactor()` proves the owner's app
     * actually has it.
     */
    public function startTwoFactorEnrollment(): void
    {
        $user = auth()->user();

        if ($user === null || $user->hasTwoFactorEnabled()) {
            return;
        }

        // Reuse a secret already pending from an earlier, unfinished attempt
        // rather than mint a new one on every click — refreshing the page
        // mid-setup must not invalidate the code the owner is about to type.
        if ($user->two_factor_secret === null) {
            $user->two_factor_secret = Totp::generateSecret();
            $user->save();
        }

        $this->resetValidation();
        $this->totpCode = '';
        $this->enrolling2fa = true;
    }

    /** Closing the panel without confirming. Still off — visible in the panel closing. */
    public function cancelTwoFactorEnrollment(): void
    {
        $user = auth()->user();

        if ($user !== null && ! $user->hasTwoFactorEnabled()) {
            $user->two_factor_secret = null;
            $user->save();
        }

        $this->enrolling2fa = false;
        $this->totpCode = '';
        $this->resetValidation();
    }

    /** Step two: a real code from the owner's app, or the secret is never trusted. */
    public function confirmTwoFactor(): void
    {
        $user = auth()->user();

        if ($user === null || $user->two_factor_secret === null) {
            $this->toastError('Nothing to confirm', 'Start setup again.');

            return;
        }

        $this->validate(
            ['totpCode' => 'required|string'],
            ['totpCode.required' => 'Type the six-digit code your authenticator app is showing right now.'],
        );

        $limiterKey = 'security:2fa-verify:'.$user->id;

        if (RateLimiter::tooManyAttempts($limiterKey, 5)) {
            $this->addError('totpCode', 'Too many attempts. Try again in '.RateLimiter::availableIn($limiterKey).' seconds.');

            return;
        }

        RateLimiter::hit($limiterKey, 60);

        if (! Totp::verify($user->two_factor_secret, $this->totpCode)) {
            $this->addError('totpCode', 'That code is not correct, or it has already expired. Use the next one your app shows.');

            return;
        }

        RateLimiter::clear($limiterKey);

        $codes = User::generateRecoveryCodes();

        $user->two_factor_recovery_codes = $codes['hashed'];
        $user->two_factor_confirmed_at = now();
        $user->save();

        activity('security')
            ->performedOn($user)
            ->causedBy($user)
            ->event('security.two-factor-enabled')
            ->log('turned on two-factor authentication');

        $this->issuedRecoveryCodes = $codes['plaintext'];
        $this->enrolling2fa = false;
        $this->totpCode = '';

        $this->toastSuccess('Two-factor authentication is on', 'Save the recovery codes below — this is the only time they are shown.');
    }

    /**
     * Dismiss the one-time recovery-code reveal.
     *
     * Empty body on purpose, the same idiom as
     * `Modules\Platform`'s `dismissSecret()`: the codes only ever existed for
     * the request that generated them, and this round trip re-renders
     * without them. Nothing has to remember to clear it.
     */
    public function dismissRecoveryCodes(): void {}

    public function disableTwoFactor(): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        // A conditional UPDATE, not an `if` on the model: two tabs racing to
        // disable the same account must produce one write and one activity
        // entry, not two. Raw column names, because both are being set to
        // null — there is nothing to encrypt.
        $changed = User::query()
            ->whereKey($user->id)
            ->whereNotNull('two_factor_confirmed_at')
            ->update([
                'two_factor_secret_encrypted' => null,
                'two_factor_recovery_codes_encrypted' => null,
                'two_factor_confirmed_at' => null,
                'updated_at' => now(),
            ]);

        if ($changed === 0) {
            $this->toastWarning('Already off', 'Two-factor authentication was already off.');

            return;
        }

        activity('security')
            ->performedOn($user)
            ->causedBy($user)
            ->event('security.two-factor-disabled')
            ->log('turned off two-factor authentication');

        $this->toastSuccess('Two-factor authentication is off', 'Sign-in no longer asks for a code, and your recovery codes have been destroyed.');
    }

    public function regenerateRecoveryCodes(): void
    {
        $user = auth()->user();

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            $this->toastError('Two-factor is off', 'Turn it on before generating recovery codes.');

            return;
        }

        $codes = User::generateRecoveryCodes();

        $user->two_factor_recovery_codes = $codes['hashed'];
        $user->save();

        activity('security')
            ->performedOn($user)
            ->causedBy($user)
            ->event('security.two-factor-recovery-codes-regenerated')
            ->log('regenerated their two-factor recovery codes');

        $this->issuedRecoveryCodes = $codes['plaintext'];

        $this->toastSuccess('New recovery codes generated', 'The old codes no longer work.');
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
                <h2 class="text-lg font-semibold text-mono">Security</h2>
                <p class="text-sm text-secondary-foreground mt-1">
                    What it takes to sign in as you, and which devices are already signed in.
                </p>
            </div>

            <div class="kt-card" id="password">
                <div class="kt-card-header"><h3 class="kt-card-title">Signing in</h3></div>
                <div class="kt-card-content p-5 flex flex-col gap-4 max-w-[560px]">
                    <p class="text-sm text-secondary-foreground">
                        Changing your password takes effect at once and signs every other device out, so anything
                        already signed in elsewhere lands back on the sign-in page.
                    </p>
                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label font-normal text-mono" for="current-password">Current password</label>
                        <input id="current-password" type="password" autocomplete="current-password"
                               class="kt-input @error('currentPassword') border-destructive @enderror"
                               wire:model="currentPassword">
                        <span class="text-xs text-muted-foreground mt-1">Proves it is you, not somebody who found this page open.</span>
                        @error('currentPassword')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label font-normal text-mono" for="new-password">New password</label>
                        <input id="new-password" type="password" autocomplete="new-password"
                               class="kt-input @error('password') border-destructive @enderror"
                               wire:model="password">
                        <span class="text-xs text-muted-foreground mt-1">At least 12 characters, with letters in both cases and a number.</span>
                        @error('password')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="kt-form-label font-normal text-mono" for="confirm-password">Confirm new password</label>
                        <input id="confirm-password" type="password" autocomplete="new-password" class="kt-input" wire:model="password_confirmation">
                    </div>
                    <div>
                        <button class="kt-btn kt-btn-primary" wire:click="updatePassword" wire:loading.attr="disabled" wire:target="updatePassword"
                                wire:confirm="Change your password? Every other signed-in device is signed out immediately and will need the new password.">
                            <span wire:loading.remove wire:target="updatePassword">Update password</span>
                            <span wire:loading wire:target="updatePassword" class="inline-flex items-center gap-2">
                                <i class="ki-filled ki-loading animate-spin"></i> Updating…
                            </span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="kt-card" id="two-factor">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">A second factor at sign-in</h3>
                    @if ($twoFactorEnabled)
                        <span class="kt-badge kt-badge-sm kt-badge-success">On</span>
                    @endif
                </div>
                <div class="kt-card-content p-5 flex flex-col gap-5">

                    {{-- The one-time reveal for freshly generated recovery codes, shared
                         by both "just confirmed" and "just regenerated". --}}
                    @if ($issuedRecoveryCodes)
                        <div class="rounded-lg border border-success/40 bg-success/5 p-4 flex flex-col gap-3">
                            <h4 class="text-sm font-semibold text-mono flex items-center gap-2">
                                <i class="ki-filled ki-check-circle text-success"></i> Recovery codes
                            </h4>
                            <p class="text-sm text-secondary-foreground">
                                Each code signs you in once, if you lose access to your authenticator app. Kargah
                                stores only a hash of each one, so this is the only time they are shown — save them
                                somewhere safe now.
                            </p>
                            <div class="grid grid-cols-2 md:grid-cols-5 gap-2">
                                @foreach ($issuedRecoveryCodes as $code)
                                    <code class="text-xs px-2 py-1.5 rounded bg-muted text-mono text-center tracking-wide">{{ $code }}</code>
                                @endforeach
                            </div>
                            <div>
                                <button type="button" class="kt-btn kt-btn-sm kt-btn-ghost" wire:click="dismissRecoveryCodes">
                                    Done, I have saved these
                                </button>
                            </div>
                        </div>
                    @endif

                    @if ($twoFactorEnabled)
                        <div class="flex flex-col gap-3">
                            <p class="text-sm text-secondary-foreground">
                                On. Sign-in asks for a six-digit code from your authenticator app, or for one
                                of your recovery codes.
                            </p>
                            <p class="text-sm text-secondary-foreground">
                                Keep those codes somewhere you can reach without this device. If you lose both
                                the app and the codes, there is no reset link — two-factor can only be cleared
                                from the server's command line, with
                                <code class="text-xs px-1.5 py-0.5 rounded bg-muted text-mono">php artisan two-factor:disable {{ auth()->user()?->email }}</code>.
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="kt-btn kt-btn-sm kt-btn-outline gap-2" wire:click="regenerateRecoveryCodes"
                                        wire:confirm="Generate new recovery codes? Every code you have already written down or printed stops working the moment you confirm, and the new set is shown only once.">
                                    <i class="ki-filled ki-arrows-circle text-sm"></i> New recovery codes
                                </button>
                                <button type="button" class="kt-btn kt-btn-sm kt-btn-outline text-destructive gap-2" wire:click="disableTwoFactor"
                                        wire:confirm="Turn off two-factor authentication? From the next sign-in, your password alone gets somebody into this account, and your recovery codes are destroyed — turning it back on means enrolling your authenticator app again.">
                                    <i class="ki-filled ki-shield-cross text-sm"></i> Turn off
                                </button>
                            </div>
                        </div>
                    @elseif ($enrolling2fa)
                        <div class="flex flex-col md:flex-row gap-6">
                            <div class="flex flex-col gap-2 shrink-0">
                                <span class="text-xs font-medium text-mono uppercase tracking-wide">Manual entry key</span>
                                <code class="text-sm px-3 py-2 rounded bg-muted text-mono tracking-wide break-all max-w-[280px]">{{ $formattedSecret }}</code>
                            </div>
                            <div class="flex flex-col gap-3 min-w-0 flex-1">
                                <p class="text-sm text-secondary-foreground">
                                    Kargah has no QR scanner built in — add an account in your authenticator app and
                                    enter the key on the left by hand, or paste this URI if your app accepts one:
                                </p>
                                <code class="text-xs px-3 py-2 rounded bg-muted text-secondary-foreground break-all">{{ $provisioningUri }}</code>
                                <p class="text-sm text-secondary-foreground">Then enter the six-digit code it shows to confirm.</p>
                                <div class="flex flex-col gap-1 max-w-[200px]">
                                    <input type="text" inputmode="numeric" maxlength="6" placeholder="000000" aria-label="Six-digit code"
                                           class="kt-input tracking-widest text-center @error('totpCode') border-destructive @enderror"
                                           wire:model="totpCode" wire:keydown.enter="confirmTwoFactor">
                                    @error('totpCode')<span class="text-xs text-destructive mt-1">{{ $message }}</span>@enderror
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" class="kt-btn kt-btn-primary" wire:click="confirmTwoFactor" wire:loading.attr="disabled" wire:target="confirmTwoFactor">
                                        <span wire:loading.remove wire:target="confirmTwoFactor">Confirm and enable</span>
                                        <span wire:loading wire:target="confirmTwoFactor" class="inline-flex items-center gap-2">
                                            <i class="ki-filled ki-loading animate-spin"></i> Checking…
                                        </span>
                                    </button>
                                    <button type="button" class="kt-btn kt-btn-ghost" wire:click="cancelTwoFactorEnrollment">Cancel</button>
                                </div>
                            </div>
                        </div>
                    @else
                        <div class="flex items-center justify-between gap-3 flex-wrap">
                            <p class="text-sm text-secondary-foreground">
                                Off. Turning this on makes every sign-in ask for a six-digit code from an
                                authenticator app after the password, on this device and every other.
                            </p>
                            <button type="button" class="kt-btn kt-btn-primary gap-2" wire:click="startTwoFactorEnrollment">
                                <i class="ki-filled ki-shield-tick"></i> Set up two-factor authentication
                            </button>
                        </div>
                    @endif
                </div>
            </div>

            <div class="kt-card" id="sessions">
                <div class="kt-card-header">
                    <h3 class="kt-card-title">Where you are signed in</h3>
                    <div class="flex items-center gap-2">
                        <span class="{{ $sessionStore['tone'] }}">{{ $sessionStore['headline'] }}</span>
                        @if ($sessionStore['state'] === 'healthy' && count($sessions) > 1)
                            <button type="button" class="kt-btn kt-btn-sm kt-btn-outline text-destructive"
                                    wire:click="signOutOtherSessions"
                                    wire:confirm="Sign out every other session? Every other signed-in device — a phone, a second browser, another computer — lands back on the sign-in page and loses anything half-typed.">
                                Sign out everywhere else
                            </button>
                        @endif
                    </div>
                </div>

                <div class="kt-card-content px-5 pt-4 {{ $sessionStore['state'] === 'healthy' ? 'pb-0' : 'pb-4' }}">
                    <p class="text-sm text-secondary-foreground">{{ $sessionStore['detail'] }}</p>
                </div>

                @if ($sessionStore['state'] === 'healthy')
                    <div class="kt-card-table">
                        <div class="kt-scrollable-x-auto">
                            <table class="kt-table align-middle text-sm">
                                <thead>
                                    <tr>
                                        <th class="min-w-[200px]">Device</th>
                                        <th class="w-[160px]">IP address</th>
                                        <th class="w-[140px]">Last active</th>
                                        <th class="w-[110px] text-end"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($sessions as $s)
                                        <tr wire:key="session-{{ $s['id'] }}">
                                            <td>
                                                <div class="font-medium text-mono">{{ $s['device'] }}</div>
                                            </td>
                                            <td class="text-secondary-foreground">{{ $s['ip'] ?? $unknown }}</td>
                                            <td>
                                                @if ($s['current'])
                                                    <span class="kt-badge kt-badge-sm kt-badge-success">{{ $s['last'] }}</span>
                                                @else
                                                    <span class="text-secondary-foreground">{{ $s['last'] }}</span>
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                @unless ($s['current'])
                                                    <button type="button" class="kt-btn kt-btn-sm kt-btn-ghost text-destructive"
                                                            wire:click="signOutSession('{{ $s['id'] }}')"
                                                            wire:loading.attr="disabled" wire:target="signOutSession('{{ $s['id'] }}')"
                                                            wire:confirm="Sign out {{ $s['device'] }}? That device lands back on the sign-in page and loses anything half-typed on it.">
                                                        Sign out
                                                    </button>
                                                @endunless
                                            </td>
                                        </tr>
                                    @empty
                                        <tr>
                                            <td colspan="4">
                                                <div class="flex flex-col items-center py-14 text-center gap-3">
                                                    <i class="ki-filled ki-security-user text-4xl text-muted-foreground"></i>
                                                    <p class="text-sm text-secondary-foreground">
                                                        No session is recorded against this account yet — not even this one, which
                                                        means the session has not been written to the database on this request.
                                                    </p>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>

            <div class="kt-card bg-info/5 border-info/30">
                <div class="kt-card-content flex items-start gap-3 p-4">
                    <i class="ki-filled ki-key text-info text-lg mt-0.5 shrink-0"></i>
                    <div class="text-sm text-secondary-foreground">
                        <strong class="text-mono">Looking for API tokens?</strong>
                        Credentials for scripts and the API live on their own page, hashed and individually revocable.
                        @if ($applicationPasswordsRoute)
                            <a href="{{ $applicationPasswordsRoute }}" class="text-primary hover:underline">Manage application passwords</a>.
                        @else
                            That page is unavailable — the Platform module is not enabled on this install.
                        @endif
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
