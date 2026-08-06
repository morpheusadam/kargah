<?php

namespace Modules\Social\Console;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Services\Publishers\PublishFailed;
use Modules\Social\Services\Publishing;
use Modules\Social\Support\Networks;

/**
 * Renew the credentials that will renew themselves, before anybody notices.
 *
 * `social:check-token-expiry` was written on the premise that Kargah pastes
 * tokens rather than minting them, so the only thing a schedule could do was
 * notice the clock running out and say so. That is true of eleven of the
 * fourteen networks and it is **not** true of Instagram and Threads: both mint a
 * sixty-day token, both publish a `refresh_access_token` edge that trades a
 * living one for a fresh sixty days, and neither asks for a permission Kargah
 * does not already hold. Left alone, the owner's Instagram connection — the only
 * one that has ever been connected for real — dies on a date nobody wrote down,
 * and the first symptom is a red target row on a post they wanted to go out.
 * `RefreshesToken` lists why the other twelve drivers have nothing to implement.
 *
 * 🔴 **Halfway through the token's declared life, not seven days before the
 * end.** A refresh is not free of preconditions — Meta refuses one on a token
 * that has already expired, and no amount of asking afterwards brings the
 * account back; somebody has to authorise the app again by hand. So the window
 * is sized for the failure that matters, which is not "the request was refused"
 * but "nothing ran". Sixty days becomes thirty days of daily attempts, and any
 * single one of those thirty succeeding is enough. A seven-day trigger would
 * hold the whole connection on a week in which cron must not be broken, the
 * server must not be moved and nobody must be on holiday.
 *
 * **Running it twice changes nothing the second time**, without needing a lock
 * or a marker to say so: the first run pushes the expiry sixty days out, and the
 * second finds it far outside the window and skips it. That falls out of the
 * window rule rather than being bolted onto it, which is what makes it safe on
 * cron where a doubled run is normal.
 *
 * **A failure is recorded and not shouted about.** It goes to
 * `social_accounts.last_error`, where the accounts page shows it, and the run
 * exits non-zero so cron's mail says something happened — but no notification is
 * raised, because there is already one waiting: thirty days of failed refreshes
 * end at the seven-day mark where `social:check-token-expiry` warns, and that
 * warning is the one that tells a person what to do about it. Two notifications
 * for one problem, three weeks apart, would train somebody to ignore the first.
 *
 * Runs daily, at 08:05, ten minutes ahead of the expiry check. The order is
 * deliberate: on the morning a token is both refreshable and inside the warning
 * window — which can only happen after a month of failures, or on an install
 * whose cron has just been repaired — the refresh gets its turn first, so the
 * warning that follows is about a token that genuinely could not be saved.
 */
class RefreshTokens extends Command
{
    protected $signature = 'social:refresh-tokens {--force : Refresh every eligible account now, whatever its clock says}';

    protected $description = 'Renew the tokens on the networks whose API will extend them';

    /**
     * The fraction of a token's declared life that must be spent before Kargah
     * asks for a new one.
     *
     * A half rather than a number of days, because the rule has to hold for a
     * network whose token lives for a week as well as for the two that live for
     * sixty days. What it buys is the same either way: as much room to retry as
     * the credential has already used up.
     */
    public const REFRESH_AFTER = 0.5;

    public function handle(Publishing $publishing): int
    {
        $accounts = SocialAccount::query()
            ->active()
            ->whereNotNull('token_expires_at')
            ->inReadingOrder()
            ->get();

        $refreshed = 0;
        $failed = [];
        $considered = 0;

        foreach ($accounts as $account) {
            $refresher = $publishing->refresherFor($account->network);

            if ($refresher === null || ! $account->isConnected()) {
                continue;
            }

            $considered++;

            if (! $this->isDue($account)) {
                continue;
            }

            try {
                $fresh = $refresher->refreshToken($account);
            } catch (PublishFailed $e) {
                $failed[] = $account->handle;

                $account->forceFill(['last_checked_at' => now(), 'last_error' => $e->getMessage()])->save();

                $this->components->warn($e->getMessage());

                continue;
            }

            $this->store($account, $fresh->accessToken, $fresh->expiresAt);

            $refreshed++;

            $this->components->info(
                Networks::label($account->network).' '.$account->handle.': renewed until '
                .$account->token_expires_at->toDayDateTimeString().'.',
            );
        }

        if ($considered === 0) {
            $this->components->info('No connected account is on a network that renews its own token.');

            return self::SUCCESS;
        }

        $summary = 'Renewed '.$refreshed.' of '.$considered.' '.str('account')->plural($considered).' that can be.';

        if ($failed !== []) {
            // Non-zero so cron's mail carries it. Whatever was renewed is
            // already committed; the exit code reports the gap rather than
            // undoing the rest — same contract as `social:sync-notifications`.
            $this->components->warn(
                $summary.' '.implode(' and ', $failed)
                .' could not be renewed and will be tried again tomorrow.',
            );

            return self::FAILURE;
        }

        $this->components->info($summary);

        return self::SUCCESS;
    }

    /**
     * Whether this account has spent enough of its token's life to ask for more.
     *
     * `--force` exists for the one case the clock cannot serve: proving the
     * whole path works against a real credential on the day it is built, rather
     * than finding out thirty days later on a server nobody is watching. It
     * skips the window and nothing else — an expired token is still refused by
     * Meta, and it is still refused here first.
     *
     * A network with no `token_lifetime_days` is left alone rather than refreshed
     * eagerly. It cannot happen today: both drivers that implement
     * `RefreshesToken` declare sixty days. If a third ever arrives without one,
     * doing nothing is the answer that cannot lose a working credential.
     */
    public function isDue(SocialAccount $account): bool
    {
        // An expired token cannot be extended, by Meta's rule and not Kargah's.
        // Asking anyway would spend a request to be told so, and would overwrite
        // `last_error` with a refusal less useful than whatever is already there.
        if ($account->tokenExpired()) {
            return false;
        }

        if ($this->option('force')) {
            return true;
        }

        $lifetime = Networks::tokenLifetimeDays($account->network);

        if ($lifetime === null) {
            return false;
        }

        return now()->diffInDays($account->token_expires_at) <= $lifetime * self::REFRESH_AFTER;
    }

    /**
     * Put the replacement where the driver will read it next time.
     *
     * 🔴 **The credential bag is merged, never replaced.** `access_token` is one
     * field of two on both of these networks — `ig_user_id` and
     * `threads_user_id` are the others, and neither is re-issued by a refresh.
     * Assigning a one-key array here would encrypt away the id the publisher
     * needs and leave an account that verifies as connected and cannot publish.
     *
     * `last_error` is cleared on success. Today that cannot erase somebody
     * else's message — neither of these two networks implements
     * `IngestsNotifications`, so `social:sync-notifications` never writes to
     * their rows — but a network that did both would have two writers on one
     * cell, and the second one to run would be the one believed.
     */
    private function store(SocialAccount $account, string $token, CarbonInterface $expiresAt): void
    {
        $account->forceFill([
            'credentials' => [...$account->credentials, 'access_token' => $token],
            'token_expires_at' => $expiresAt,
            'last_checked_at' => now(),
            'last_error' => null,
        ])->save();
    }
}
