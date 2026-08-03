<?php

namespace App\Support;

use App\Models\User;

/**
 * The half-authenticated state between a correct password and a correct code.
 *
 * Holding this is **not** being signed in. It is three primitives in the
 * session — an id, an expiry, and whether "keep me signed in" was ticked —
 * and nothing that reads it will let a request past `auth`. `Auth::login()`
 * is not called until `pages::two-factor-challenge` has checked a real code,
 * so every route behind the `auth` middleware refuses a person who has only
 * got this far.
 *
 * No user model goes in here on purpose. A serialised Eloquent model in the
 * session is a snapshot that can disagree with the row it came from — a
 * second factor that was turned off a minute ago would still read as on — and
 * it is a much larger thing to hand to whatever the session driver is. An id
 * costs one query at the moment it is needed and cannot go stale.
 *
 * It expires. Five minutes is long enough to open an authenticator app, find
 * the account and retype six digits, and short enough that an abandoned
 * browser on a shared machine is not still one code away from a session an
 * hour later.
 */
final class TwoFactorChallenge
{
    /** How long a correct password stays worth anything without a code. */
    public const LIFETIME_SECONDS = 300;

    private const ID = 'auth.two_factor.user_id';

    private const EXPIRES_AT = 'auth.two_factor.expires_at';

    private const REMEMBER = 'auth.two_factor.remember';

    /** Park the account the password matched, and start the clock. */
    public static function begin(User $user, bool $remember = false): void
    {
        session()->put([
            self::ID => $user->getKey(),
            self::EXPIRES_AT => now()->getTimestamp() + self::LIFETIME_SECONDS,
            self::REMEMBER => $remember,
        ]);
    }

    /**
     * The id being challenged, or null when there is no challenge or it has
     * run out. An expired challenge is cleared here rather than left to rot,
     * so the next reader sees "nothing pending" and not "something stale".
     */
    public static function userId(): ?int
    {
        $id = session(self::ID);
        $expiresAt = session(self::EXPIRES_AT);

        if ($id === null || $expiresAt === null) {
            return null;
        }

        if (now()->getTimestamp() >= (int) $expiresAt) {
            self::forget();

            return null;
        }

        return (int) $id;
    }

    /**
     * The account being challenged, read fresh from the database every time.
     *
     * Null when the challenge is absent, expired, or points at a row that is
     * no longer there.
     */
    public static function user(): ?User
    {
        $id = self::userId();

        return $id === null ? null : User::find($id);
    }

    public static function isPending(): bool
    {
        return self::userId() !== null;
    }

    /** Whether "keep me signed in" was ticked on the password form. */
    public static function remember(): bool
    {
        return (bool) session(self::REMEMBER, false);
    }

    /** Seconds left before the challenge lapses. Zero once it has. */
    public static function secondsRemaining(): int
    {
        $expiresAt = session(self::EXPIRES_AT);

        if ($expiresAt === null) {
            return 0;
        }

        return max(0, (int) $expiresAt - now()->getTimestamp());
    }

    public static function forget(): void
    {
        session()->forget([self::ID, self::EXPIRES_AT, self::REMEMBER]);
    }
}
