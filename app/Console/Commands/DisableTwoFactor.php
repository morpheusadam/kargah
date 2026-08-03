<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * The way back in when the authenticator app and the recovery codes are both
 * gone.
 *
 *     php artisan two-factor:disable admin@kargah.local
 *
 * Turning on a second factor means a password on its own no longer opens the
 * account. That is the point, and it is also the risk: lose the phone, lose the
 * printed codes, and the sign-in form has nothing left it can honestly accept.
 * Kargah offers no emailed reset link — a self-hosted single-user app that
 * mails itself a bypass has simply added a second, weaker password on the same
 * mailbox — so the escape hatch is this command, and it is deliberately one a
 * browser cannot reach.
 *
 * It grants nothing new. Anyone who can run artisan already has the database
 * file and the `APP_KEY` beside it, and could read or rewrite the same three
 * columns by hand; this only means a lost phone costs a shell command rather
 * than the account. The corollary is the honest one and is said on the
 * challenge page: somebody with no shell access and no recovery codes does not
 * get in. That is why the codes are shown once, loudly, at enrolment.
 *
 * The write is a conditional UPDATE, the same shape `/settings/security` uses,
 * so running it twice produces one change and one log entry. It clears an
 * unconfirmed, half-finished enrolment too — that secret is dead weight and
 * would otherwise be reused by the next `startTwoFactorEnrollment()`.
 */
class DisableTwoFactor extends Command
{
    protected $signature = 'two-factor:disable
                            {email : The email address of the account to unlock}';

    protected $description = 'Turn off two-factor authentication for one account, for somebody who has lost both their authenticator app and their recovery codes';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        $user = User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->first();

        if ($user === null) {
            $this->components->error('No account has the address '.$email.'.');

            return self::FAILURE;
        }

        $changed = User::query()
            ->whereKey($user->id)
            ->where(fn ($query) => $query
                ->whereNotNull('two_factor_confirmed_at')
                ->orWhereNotNull('two_factor_secret_encrypted'))
            ->update([
                'two_factor_secret_encrypted' => null,
                'two_factor_recovery_codes_encrypted' => null,
                'two_factor_confirmed_at' => null,
                'updated_at' => now(),
            ]);

        if ($changed === 0) {
            $this->components->info('Two-factor authentication was already off for '.$user->email.'.');

            return self::SUCCESS;
        }

        // No causer: nobody was authenticated, a shell was. Recording the owner
        // as the causer would put a person's name against an act they may not
        // have performed, which is exactly the entry an audit trail exists to
        // tell apart. The event name differs from the settings page's for the
        // same reason.
        activity('security')
            ->performedOn($user)
            ->event('security.two-factor-disabled-from-console')
            ->log('turned off two-factor authentication from the command line');

        $this->components->info('Two-factor authentication is off for '.$user->email.'.');
        $this->components->warn('That account now signs in with a password alone. Set it up again from /settings/security.');

        return self::SUCCESS;
    }
}
