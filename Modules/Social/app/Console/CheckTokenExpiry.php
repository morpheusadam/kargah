<?php

namespace Modules\Social\Console;

use App\Models\User;
use Illuminate\Console\Command;
use Modules\Core\Contracts\Notifier;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * Tell whoever connected an account before its token expires, and again once
 * it has — the one gap `08-postiz-parity.md` names as already built and never
 * read. `social_accounts.token_expires_at` is a real column with a real
 * `datetime` cast; nothing consulted it before this command.
 *
 * Kargah pastes tokens rather than minting its own, so there is no refresh to
 * automate here — see `account-connect`'s own docblock and `DECISIONS.md`,
 * phase 7. The only thing a schedule can genuinely do is notice the clock
 * running out and say so before publishing starts failing silently.
 *
 * **The dedupe key carries the expiry it is warning about, not just the
 * account.** `token_expires_at` is recomputed every time a credential is
 * re-pasted (see `Networks::tokenLifetimeDays()` and `⚡account-connect`'s
 * `save()`), so folding its own timestamp into the key is what makes a fresh
 * paste eligible for a fresh warning rather than being silently swallowed by
 * a key that already fired against the token that got replaced. A naive key
 * of `social_account:{id}:token_expiring` would warn once, ever, no matter
 * how many times the credential changed underneath it — exactly the failure
 * mode the brief calls out.
 *
 * Two thresholds rather than a warning on every one of sixty days: seven days
 * out, so there is time to go and regenerate a LinkedIn token before a
 * weekend gets in the way, and one day out, because seven days is easy to
 * miss in a feed and the last day deserves to read as more urgent than the
 * first notice did. An already-expired token is a different event entirely
 * — `social.token_expired`, not a third threshold on the same one — because
 * "will stop working soon" and "publishing is failing right now" call for
 * different attention and, on the settings page, arguably different
 * defaults.
 *
 * Runs daily: `token_expires_at` moves in units of days for every network
 * that has one at all, so a tighter tick would only mean checking a clock
 * that has not moved.
 */
class CheckTokenExpiry extends Command
{
    protected $signature = 'social:check-token-expiry';

    protected $description = 'Warn before a connected account\'s token expires, and again once it has';

    /**
     * Days remaining at which a warning fires, checked in this order.
     *
     * Each is its own dedupe key, so a token that is already inside the
     * one-day window on its very first check fires both — two notices
     * arriving together the first time a run catches up, which is honest
     * rather than a reason to add ordering logic no daily cron needs.
     */
    public const WARN_AT_DAYS = [SocialAccount::TOKEN_EXPIRY_WARNING_DAYS, 1];

    public function handle(Notifier $notifier): int
    {
        $accounts = SocialAccount::query()
            ->active()
            ->whereNotNull('token_expires_at')
            ->inReadingOrder()
            ->get();

        if ($accounts->isEmpty()) {
            $this->components->info('No connected account carries a token expiry to watch.');

            return self::SUCCESS;
        }

        $warned = 0;
        $expired = 0;
        $skipped = 0;

        foreach ($accounts as $account) {
            // `active()` is a column check; `isConnected()` also asks whether
            // every credential the driver needs is actually present, which is
            // the "disconnected but the row is still here" case `disconnect()`
            // leaves behind — see `⚡accounts.blade.php`.
            if (! $account->isConnected()) {
                $skipped++;

                continue;
            }

            $recipients = $this->recipients($account);

            if ($recipients === []) {
                continue;
            }

            if ($account->token_expires_at->isPast()) {
                $expired += $notifier->notifyMany($recipients, 'social.token_expired', $this->title($account, 'has expired'), [
                    'subject' => $account,
                    'body' => $this->requirement($account),
                    'url' => route('social.accounts'),
                    'dedupe_key' => $this->key($account, 'expired'),
                ]);

                continue;
            }

            $daysLeft = (int) now()->diffInDays($account->token_expires_at);

            foreach (self::WARN_AT_DAYS as $threshold) {
                if ($daysLeft > $threshold) {
                    continue;
                }

                $sent = $notifier->notifyMany(
                    $recipients,
                    'social.token_expiring',
                    $this->title($account, $daysLeft <= 1 ? 'expires within a day' : 'expires in '.$daysLeft.' days'),
                    [
                        'subject' => $account,
                        'body' => $this->requirement($account),
                        'url' => route('social.accounts'),
                        'dedupe_key' => $this->key($account, 'expiring', $threshold),
                    ],
                );

                $warned += $sent;
            }
        }

        $this->components->info(
            'Checked '.$accounts->count().' '.str('account')->plural($accounts->count()).' with a token expiry: '
            .$warned.' warned, '.$expired.' expired'
            .($skipped > 0 ? ', '.$skipped.' skipped as not fully connected' : '').'.',
        );

        return self::SUCCESS;
    }

    /**
     * A key that changes when the token does.
     *
     * Folding `token_expires_at`'s own timestamp in is what lets a re-pasted
     * credential earn a fresh warning: the column moves, so the key does too,
     * and the row a stale key already wrote stops matching anything this run
     * asks for.
     */
    private function key(SocialAccount $account, string $state, ?int $threshold = null): string
    {
        $token = $account->token_expires_at->getTimestamp();

        $key = 'social_account:'.$account->id.':'.$state.':'.$token;

        return $threshold === null ? $key : $key.':'.$threshold.'d';
    }

    private function title(SocialAccount $account, string $tail): string
    {
        return $account->label().' ('.$account->handle.') '.$tail;
    }

    /**
     * The network's own note on how to get a fresh credential, so the
     * notification tells someone what to do rather than only that something
     * is wrong. `Networks::LINKEDIN['requirement']` already says the token
     * expires after sixty days and to paste a new one; nothing here writes
     * new copy about it.
     */
    private function requirement(SocialAccount $account): ?string
    {
        return Networks::get($account->network)['requirement'] ?? null;
    }

    /**
     * Who to tell.
     *
     * `created_by` is who pasted the credential and is nullable — a row the
     * seeder made, or one connected before this column existed. Falling back
     * to every user is deliberately generous rather than silent: Kargah is
     * built for one freelancer, so "every user" is realistically one person,
     * and a token nobody hears about failing is a worse outcome than one
     * extra recipient on an install that somehow has more than one.
     *
     * @return list<int>
     */
    private function recipients(SocialAccount $account): array
    {
        if ($account->created_by !== null) {
            return [(int) $account->created_by];
        }

        return User::query()->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }
}
