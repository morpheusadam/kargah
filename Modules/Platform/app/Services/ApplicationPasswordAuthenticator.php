<?php

namespace Modules\Platform\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Modules\Platform\Models\ApplicationPassword;

/**
 * Turning an email address and a presented secret into a credential, or into
 * nothing.
 *
 * Deliberately not a middleware and deliberately not tied to a request: an
 * artisan command (`kargah:ask`) has to authenticate exactly the same way an
 * HTTP request does, and two implementations of "is this secret valid" is how a
 * project ends up with one of them wrong.
 *
 * Two rules hold here:
 *
 * **Never look a row up by its hash.** `where('token_hash', $hash)` would make
 * the database answer "does this hash exist" for anyone who can ask, and it
 * only works at all with an unsalted digest — which is the wrong kind of hash
 * for a credential. The lookup is `user_id` + `prefix`, which narrows to a
 * handful of rows, and every one of them gets a real `Hash::check`.
 *
 * **A miss costs the same as a hit.** No user, no candidate row and a wrong
 * secret all spend one hash verification, so a stopwatch cannot tell an unknown
 * email address from a known one.
 */
class ApplicationPasswordAuthenticator
{
    /**
     * The value hashed when there is nothing real to check against. Its content
     * is irrelevant; the time it takes is the point.
     */
    private const DECOY = 'kargah-application-password-timing-decoy';

    public function attempt(string $email, string $secret): ?ApplicationPassword
    {
        $email = trim($email);

        if ($email === '' || $secret === '') {
            return null;
        }

        $user = User::query()->where('email', $email)->first();

        $candidates = $user === null
            ? collect()
            : ApplicationPassword::query()
                ->where('user_id', $user->getKey())
                ->where('prefix', ApplicationPassword::prefixOf($secret))
                ->get();

        $matched = null;

        foreach ($candidates as $candidate) {
            // No early break. Two credentials can share a prefix, and stopping
            // at the first match would make the answer arrive sooner for one
            // ordering of the table than another.
            if (Hash::check($secret, (string) $candidate->getRawOriginal('token_hash'))) {
                $matched = $candidate;
            }
        }

        if ($matched === null) {
            // Spend what a real check would have spent. Without this, "no such
            // account" returns measurably faster than "wrong secret", which is
            // an account enumeration oracle sitting on the one endpoint in
            // Kargah reachable without a session.
            Hash::make(self::DECOY);

            return null;
        }

        // Revoked and expired are the same answer as wrong: no. Checked after
        // the hash rather than in the query, so a revoked credential does not
        // return faster than a wrong one either.
        return $matched->isUsable() ? $matched : null;
    }

    /**
     * Record that a credential was used, and from where.
     *
     * Quietly and without touching `updated_at`: a credential being used is not
     * a credential changing, and letting a read move `updated_at` would make
     * "when was this last edited" unanswerable. `last_used_at` is the column
     * that answers "when was this last used", and it is the one that moves.
     */
    public function recordUse(ApplicationPassword $credential, ?string $ip): void
    {
        ApplicationPassword::withoutTimestamps(fn () => $credential->forceFill([
            'last_used_at' => now(),
            'last_used_ip' => $ip === null ? null : mb_substr($ip, 0, 45),
        ])->saveQuietly());
    }
}
