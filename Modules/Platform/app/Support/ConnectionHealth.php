<?php

namespace Modules\Platform\Support;

use Illuminate\Support\Facades\Schema;
use Modules\Platform\Models\ApplicationPassword;
use Modules\Platform\Models\AssistantProvider;
use Modules\Social\Console\CheckTokenExpiry;
use Modules\Social\Console\RefreshTokens;
use Modules\Social\Models\SocialAccount;
use Modules\Social\Support\Networks;

/**
 * The read side of "is the thing this setting depends on actually working?".
 *
 * Six settings screens across two modules each carry at least one switch whose
 * effect depends on something outside Kargah — a social token that expires, an
 * AI provider that can refuse a key, a mail transport that may be `log`, a
 * `sessions` table that only exists on one session driver. Before this class
 * every screen answered that question for itself or did not answer it at all,
 * and the notifications page in particular offered a switch for
 * `social.token_expiring` without ever saying whether a single account was
 * connected. Somebody turning that switch on had no way to tell whether it
 * would ever fire.
 *
 * Three rules hold everything below together, and each is why a method looks
 * the way it does rather than the obvious way:
 *
 * 🔴 **Every method returns scalars, never a model.** `SocialAccount`'s
 * credential column is cast `encrypted:array`, and an `encrypted` cast
 * *decrypts on read* — which is exactly why both `credentials` and
 * `credentials_encrypted` sit in that model's `$hidden`. A Livewire component
 * that puts the model into its `with()` payload serialises it into the page,
 * and `$hidden` guards `toArray()` rather than promising anything about every
 * path a component might take. So the settings screens never receive a
 * `SocialAccount` at all: they receive the handful of strings and booleans
 * chosen here by hand, and there is no credential among them. `SocialAccount`'s
 * own class docblock makes the same argument from the other end.
 *
 * ⚠️ **Thresholds are imported, never restated.** "Soon" is
 * `SocialAccount::TOKEN_EXPIRY_WARNING_DAYS` (7), which is the first entry of
 * `CheckTokenExpiry::WARN_AT_DAYS` — the scheduled command that actually sends
 * the warning. A badge reading "expiring soon" on a different number of days
 * from the cron job that emails about it is a page arguing with a schedule, and
 * whichever one a person believed would be wrong half the time.
 * `RefreshTokens::REFRESH_AFTER` is read for the same reason: on the two
 * networks that renew themselves, a warning only arrives after weeks of
 * automatic renewals have already failed, and that sentence is worth printing
 * instead of a bare warning identical to LinkedIn's.
 *
 * ⚠️ **A value only the backend can know renders as an em dash.** Never `0`,
 * never "unknown", never a guess — `docs/frontend-conventions.md` under
 * *Content*. A never-checked account has no last-checked time, so it gets
 * `UNKNOWN` and the template prints exactly what this class returns.
 *
 * The class is deliberately read-only. Nothing here writes, refreshes or
 * reaches the network: a settings page that phoned an API on every render would
 * make the slowest page in Kargah out of the one people open precisely when
 * something is already wrong.
 */
final class ConnectionHealth
{
    /**
     * What a value the backend cannot know renders as.
     *
     * An em dash, not `0` and not `TODO`. A constant rather than a literal in
     * eight templates so `SettingsPanelTest` can assert on the same character
     * the pages print.
     */
    public const UNKNOWN = '—';

    /*
     * Whole class strings. Tailwind's scanner reads source files as text and
     * cannot see `"kt-badge-{$state}"` — `Modules/Social/app/Support/Networks.php`
     * lines 16-18 say the same at greater length. Every tone below is written
     * out in full, which is what makes it survive a stylesheet rebuild.
     */
    private const TONES = [
        'healthy' => 'kt-badge kt-badge-sm kt-badge-success',
        'warning' => 'kt-badge kt-badge-sm kt-badge-warning',
        'broken' => 'kt-badge kt-badge-sm kt-badge-destructive',
        'idle' => 'kt-badge kt-badge-sm kt-badge-outline',
    ];

    /**
     * Rank of a state, worst first, for `worst()`.
     *
     * A summary line has room for one word and the word has to be the worst
     * one: five healthy accounts and one expired token is a problem, and a
     * badge reading "Connected" over the top of it is a lie of omission.
     */
    private const SEVERITY = ['broken' => 3, 'warning' => 2, 'idle' => 1, 'healthy' => 0];

    public static function tone(string $state): string
    {
        return self::TONES[$state] ?? self::TONES['idle'];
    }

    /* Social accounts ---------------------------------------------------------- */

    /**
     * Whether the Social module is installed and migrated on this install.
     *
     * Both halves are needed. `class_exists` answers "is the module enabled",
     * because a module switched off in `modules_statuses.json` is simply not
     * autoloaded; the table check answers "has it been migrated", which is
     * false on a fresh clone between `composer install` and `artisan migrate`.
     * Asking neither is how a settings page 500s on an install that is merely
     * incomplete.
     */
    public static function socialAvailable(): bool
    {
        return class_exists(SocialAccount::class) && Schema::hasTable('social_accounts');
    }

    /**
     * How many days before a credential dies counts as "soon" on this install,
     * or null when nothing has an opinion.
     *
     * ⚠️ The number is owned by `Modules\Social` — `TOKEN_EXPIRY_WARNING_DAYS`
     * is what `CheckTokenExpiry` actually warns at — and Platform borrows it
     * rather than declaring a second one. When Social is switched off there is
     * no installed answer, and inventing one here is exactly the drift the
     * borrowing is meant to prevent, so callers get null and say nothing rather
     * than guess. That costs the application-passwords page its near-expiry
     * badge on a Social-less install, which is the honest trade.
     */
    public static function warningWindowDays(): ?int
    {
        return class_exists(SocialAccount::class) ? SocialAccount::TOKEN_EXPIRY_WARNING_DAYS : null;
    }

    /**
     * One account's health, as scalars a template can print.
     *
     * The `state` words follow the same precedence `⚡accounts.blade.php`
     * already badges an account with, so the settings page and the Social page
     * cannot disagree about one row: switched off first (nothing else matters
     * when nothing is sent), then credentials, then the token clock, then an
     * error the last run recorded.
     *
     * @return array{
     *     id: int, label: string, handle: string, network: string,
     *     state: string, tone: string, headline: string, detail: string,
     *     connected: string, checked: string, expires: string, error: string|null
     * }
     */
    public static function forSocialAccount(SocialAccount $account): array
    {
        $label = $account->label();

        // `hasCredentials()` reaches through the `encrypted:array` cast, which
        // throws `DecryptException` rather than returning null when the
        // ciphertext cannot be read — after an APP_KEY rotation, on a row
        // restored from another install, and in `NoSecretsInHtmlTest`, which
        // deliberately overwrites the raw column with a canary. A settings page
        // is exactly where somebody goes when that has happened, so it answers
        // the question instead of becoming a 500 on the way to the answer.
        try {
            $hasCredentials = $account->hasCredentials();
            $unreadable = false;
        } catch (\Throwable $e) {
            $hasCredentials = false;
            $unreadable = true;
        }

        [$state, $headline, $detail] = match (true) {
            ! $account->is_active => ['idle', 'Switched off', 'Nothing is sent to this account, so no post and no token warning about it can appear.'],
            $unreadable => ['broken', 'Credentials unreadable', 'The stored credential cannot be decrypted with this install\'s key, so nothing can be published. Paste a fresh one on the accounts page.'],
            ! $hasCredentials => ['broken', 'No credentials', 'A post aimed here records the reason instead of going out.'],
            $account->tokenExpired() => ['broken', 'Token expired', 'Publishing to '.$label.' is failing now. '.self::renewalNote($account)],
            $account->last_error !== null => ['warning', 'Last run failed', $account->last_error],
            $account->tokenExpiringSoon() => ['warning', 'Token expiring', 'Publishing to '.$label.' stops when it does. '.self::renewalNote($account)],
            default => ['healthy', 'Connected', 'Posts and token warnings for '.$label.' will reach you.'],
        };

        return [
            'id' => (int) $account->id,
            'label' => $label,
            'handle' => (string) $account->handle,
            'network' => (string) $account->network,
            'state' => $state,
            'tone' => self::tone($state),
            'headline' => $headline,
            'detail' => $detail,
            // Three timestamps, each an em dash when the column is null. A row
            // seeded before `connected_at` existed genuinely has no connection
            // date, and printing "never" would claim a fact nothing holds.
            'connected' => $account->connected_at?->diffForHumans() ?? self::UNKNOWN,
            'checked' => $account->last_checked_at?->diffForHumans() ?? self::UNKNOWN,
            'expires' => $account->token_expires_at?->diffForHumans() ?? self::UNKNOWN,
            'error' => $account->last_error,
        ];
    }

    /**
     * The sentence that follows "this token is dying": what will fix it.
     *
     * Two networks renew themselves and twelve do not, and the difference
     * changes the advice completely. `Networks::tokenLifetimeDays()` is what
     * `RefreshTokens::isDue()` multiplies by `REFRESH_AFTER` to decide when to
     * start trying, so a warning about one of those two means every daily
     * attempt across half the token's life has already failed — more urgent
     * than the same words about LinkedIn, not less. `RefreshTokens`' class
     * docblock argues it at length; this reuses the constant rather than
     * writing "thirty days" as a literal that goes stale the day a network
     * changes its lifetime.
     */
    private static function renewalNote(SocialAccount $account): string
    {
        $lifetime = Networks::tokenLifetimeDays($account->network);

        if ($lifetime === null) {
            return 'Paste a fresh credential on the accounts page.';
        }

        $window = (int) round($lifetime * RefreshTokens::REFRESH_AFTER);

        return 'Kargah has been trying to renew it automatically every day for the last '.$window
            .' days and every attempt failed, so it now needs a fresh credential pasted by hand.';
    }

    /**
     * Every social account's health, plus the one word that describes the lot.
     *
     * Returns null — not an empty array — when the module is off, so a template
     * can tell "no accounts" from "no Social module" and print a different
     * sentence for each. Those are not the same situation and a settings page
     * should not pretend they are.
     *
     * @return array{accounts: list<array<string, mixed>>, state: string, tone: string, summary: string}|null
     */
    public static function socialSummary(): ?array
    {
        if (! self::socialAvailable()) {
            return null;
        }

        $accounts = SocialAccount::query()
            ->inReadingOrder()
            ->get()
            ->map(fn (SocialAccount $account): array => self::forSocialAccount($account))
            ->all();

        if ($accounts === []) {
            return [
                'accounts' => [],
                'state' => 'idle',
                'tone' => self::tone('idle'),
                'summary' => 'No account is connected, so nothing below about posts or tokens can fire yet.',
            ];
        }

        $state = self::worst(array_column($accounts, 'state'));
        $unhealthy = count(array_filter($accounts, fn (array $a): bool => $a['state'] !== 'healthy'));
        $total = count($accounts);

        return [
            'accounts' => $accounts,
            'state' => $state,
            'tone' => self::tone($state),
            'summary' => $unhealthy === 0
                ? $total.' '.str('account')->plural($total).' connected and working.'
                : $unhealthy.' of '.$total.' '.str('account')->plural($total).' '
                    .($unhealthy === 1 ? 'needs' : 'need').' attention before Kargah can post to '
                    .($unhealthy === 1 ? 'it' : 'them').'.',
        ];
    }

    /**
     * How much warning the scheduled check gives, as a sentence.
     *
     * Read off `CheckTokenExpiry::WARN_AT_DAYS` rather than typed out, so the
     * notifications page describes the schedule that is actually installed. It
     * warns seven days out and again the day before; if a third threshold is
     * ever added, this sentence grows a number without anybody editing it.
     * Null when Social is switched off, because then no such schedule exists to
     * describe.
     */
    public static function tokenWarningSchedule(): ?string
    {
        if (! class_exists(CheckTokenExpiry::class)) {
            return null;
        }

        $parts = array_map(
            fn (int $days): string => $days === 1 ? 'the day before' : $days.' days before',
            CheckTokenExpiry::WARN_AT_DAYS,
        );

        return 'Kargah warns '.implode(' and again ', $parts).' a token expires, then once more after it has.';
    }

    /* Email delivery ------------------------------------------------------------ */

    /**
     * Whether the "email" column on the notifications page can reach anybody.
     *
     * `log` and `array` are not misconfigurations — they are the right mailers
     * for development and for the test suite — but somebody ticking "email me
     * when an invoice goes overdue" on an install running either will never
     * receive one, and nothing else on that page would say so. The switch saves
     * perfectly well; it simply has nowhere to deliver to, and that is worth a
     * line above the table rather than a support question a year later.
     *
     * @return array{state: string, tone: string, headline: string, detail: string, mailer: string}
     */
    public static function mailDelivery(): array
    {
        $mailer = (string) config('mail.default');

        $silent = [
            'log' => 'written to the log file instead of sent',
            'array' => 'kept in memory and thrown away',
        ];

        if (array_key_exists($mailer, $silent)) {
            return [
                'state' => 'warning',
                'tone' => self::tone('warning'),
                'headline' => 'Email goes nowhere',
                'detail' => 'This install\'s mailer is "'.$mailer.'", so every message is '.$silent[$mailer]
                    .'. The email switches below still save, but nothing arrives until MAIL_MAILER names a real transport.',
                'mailer' => $mailer,
            ];
        }

        return [
            'state' => 'healthy',
            'tone' => self::tone('healthy'),
            'headline' => 'Email is deliverable',
            'detail' => 'Messages go out through "'.$mailer.'".',
            'mailer' => $mailer,
        ];
    }

    /* Sessions ------------------------------------------------------------------- */

    /**
     * Whether there is a real `sessions` table behind the security page's
     * device list.
     *
     * The same two-part question `⚡security.blade.php` has always asked, moved
     * here so the page and this class agree on one answer — and so the sentence
     * explaining an empty list is written once rather than twice.
     *
     * @return array{state: string, tone: string, headline: string, detail: string, driver: string}
     */
    public static function sessionStore(): array
    {
        $driver = (string) config('session.driver');
        $table = (string) config('session.table', 'sessions');

        if ($driver === 'database' && Schema::hasTable($table)) {
            return [
                'state' => 'healthy',
                'tone' => self::tone('healthy'),
                'headline' => 'Devices are recorded',
                'detail' => 'Sessions are stored in the database, so every signed-in device is listed below and can be signed out on its own.',
                'driver' => $driver,
            ];
        }

        return [
            'state' => 'idle',
            'tone' => self::tone('idle'),
            'headline' => 'Devices are not recorded',
            'detail' => 'This install stores sessions in '.$driver.', not the database, so Kargah holds no per-device record to list or to sign out.',
            'driver' => $driver,
        ];
    }

    /* Assistant providers -------------------------------------------------------- */

    /**
     * One AI provider's health.
     *
     * ⚠️ **`$provider` carries an encrypted `api_key` behind a decrypting cast,
     * exactly like `SocialAccount`'s credentials.** Only the boolean "a key is
     * set" leaves this method; the key is neither returned nor interpolated
     * into any sentence built here.
     *
     * "Untested" is its own state rather than a failure. A provider nobody has
     * pressed Test on has an unknown connection, and calling that broken would
     * train somebody to ignore the badge that means broken.
     *
     * @return array{state: string, tone: string, headline: string, detail: string, tested: string}
     */
    public static function forAssistantProvider(AssistantProvider $provider): array
    {
        $name = $provider->label();

        [$state, $headline, $detail] = match (true) {
            ! $provider->is_active => ['idle', 'Disabled', $name.' is switched off, so the assistant never asks it anything.'],
            $provider->requiresApiKey() && ! $provider->hasApiKey() => ['broken', 'No key', $name.' needs an API key before it can answer. Edit it and paste one.'],
            $provider->last_test_ok === false => ['broken', 'Last test failed', (string) ($provider->last_test_error ?: 'The last test failed and recorded no reason.')],
            $provider->last_test_ok === true => ['healthy', 'Answering', $name.' replied the last time it was tested.'],
            default => ['warning', 'Untested', 'Nobody has tested '.$name.' yet, so whether its key works is unknown until Test is pressed.'],
        };

        return [
            'state' => $state,
            'tone' => self::tone($state),
            'headline' => $headline,
            'detail' => $detail,
            // "Never tested" is a fact the backend holds, so it says so. The em
            // dash is reserved for what it genuinely cannot know.
            'tested' => $provider->last_tested_at?->diffForHumans() ?? 'Never tested',
        ];
    }

    /* Application passwords ------------------------------------------------------ */

    /**
     * One credential's health, including the near-expiry warning the list had
     * no way to give.
     *
     * ⚠️ **The window is `warningWindowDays()`, borrowed from Social, not a
     * number typed here.** It is the same question — "is a credential about to
     * stop working without anybody noticing?" — and Kargah holding two
     * different answers to it makes both untrustworthy. No scheduled command
     * warns about application passwords today, which is exactly why the page
     * must: this badge is the only warning there is.
     *
     * @return array{state: string, tone: string, headline: string, detail: string}
     */
    public static function forApplicationPassword(ApplicationPassword $credential): array
    {
        if ($credential->isRevoked()) {
            return self::pack('idle', 'Revoked', 'Anything still sending this gets a 401. Revoking cannot be undone — issue a new credential instead.');
        }

        if ($credential->isExpired()) {
            return self::pack('broken', 'Expired', 'This stopped working on its expiry date, so any script still sending it is being refused.');
        }

        $window = self::warningWindowDays();

        if ($window !== null
            && $credential->expires_at !== null
            && now()->diffInDays($credential->expires_at) <= $window) {
            return self::pack(
                'warning',
                'Expiring',
                'This stops working '.$credential->expires_at->diffForHumans()
                    .', and every script still using it starts getting a 401 that day.',
            );
        }

        if ($credential->last_used_at === null) {
            return self::pack('idle', 'Never used', 'Nothing has authenticated with this yet. If you did not hand it to a script, revoke it.');
        }

        return self::pack('healthy', 'In use', 'Last used '.$credential->last_used_at->diffForHumans().'.');
    }

    /* Helpers -------------------------------------------------------------------- */

    /** @return array{state: string, tone: string, headline: string, detail: string} */
    private static function pack(string $state, string $headline, string $detail): array
    {
        return ['state' => $state, 'tone' => self::tone($state), 'headline' => $headline, 'detail' => $detail];
    }

    /**
     * The worst state in a list, for a summary badge that has room for one word.
     *
     * @param  list<string>  $states
     */
    public static function worst(array $states): string
    {
        $worst = 'healthy';

        foreach ($states as $state) {
            if ((self::SEVERITY[$state] ?? 0) > (self::SEVERITY[$worst] ?? 0)) {
                $worst = $state;
            }
        }

        return $worst;
    }
}
