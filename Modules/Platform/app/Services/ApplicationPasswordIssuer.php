<?php

namespace Modules\Platform\Services;

use App\Models\User;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use Modules\Platform\Models\ApplicationPassword;
use Modules\Platform\Support\Scopes;

/**
 * Issuing and revoking a credential, and the record that either happened.
 *
 * The only place an application password is created. A settings page, an
 * artisan command and a future API all come through here, so the secret is
 * drawn from one generator and hashed by one line — and the activity entry is
 * written next to the write it describes, which is what makes the two
 * impossible to separate by accident.
 *
 * `issue()` returns the plaintext, and that is the only time it exists. It is
 * not written to a column, a session, a cache entry or a log line. The activity
 * properties record the name, the prefix and the scopes; recording the secret
 * would put it in an append-only table, which is the one place it could never
 * be withdrawn from.
 */
class ApplicationPasswordIssuer
{
    /**
     * Mint a credential and hand back its secret, once.
     *
     * @param  array<int|string, mixed>  $scopes
     * @return array{credential: ApplicationPassword, secret: string}
     */
    public function issue(
        User $user,
        string $name,
        array $scopes,
        ?DateTimeInterface $expiresAt = null,
        ?Authenticatable $causer = null,
    ): array {
        $scopes = Scopes::sanitise($scopes);
        $secret = ApplicationPassword::generateSecret();

        $credential = new ApplicationPassword;

        // forceFill, because `token_hash` is deliberately unfillable. Hash::make
        // is the same driver that hashes the user's own password — one hashing
        // policy for the application, not two.
        $credential->forceFill([
            'user_id' => $user->getKey(),
            'name' => trim($name),
            'token_hash' => Hash::make($secret),
            'prefix' => ApplicationPassword::prefixOf($secret),
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
        ])->save();

        activity('application-password')
            ->performedOn($credential)
            ->causedBy($causer ?? auth()->user() ?? $user)
            ->event('application-password.created')
            ->withProperties([
                // The prefix and the scopes, never the secret. This table is
                // append-only: anything written here can never be withdrawn.
                'name' => $credential->name,
                'prefix' => $credential->prefix,
                'scopes' => $scopes,
                'expires_at' => $credential->expires_at?->toIso8601String(),
            ])
            ->log('issued the application password '.$credential->name);

        return ['credential' => $credential, 'secret' => $secret];
    }

    /**
     * Revoke one credential, and only if it was not already revoked.
     *
     * Idempotent by the same rule every job in this project follows: a second
     * run changes nothing. The guard is a conditional UPDATE rather than an
     * `if` on the model, so two processes racing each other produce one
     * revocation and one activity entry, not two — an `if` read from a stale
     * instance would let both through.
     *
     * Returns whether this call is the one that revoked it.
     */
    public function revoke(ApplicationPassword $credential, ?Authenticatable $causer = null): bool
    {
        $now = now();

        $changed = ApplicationPassword::query()
            ->whereKey($credential->getKey())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => $now, 'updated_at' => $now]);

        if ($changed === 0) {
            // Already revoked, by an earlier click or another process. No write,
            // no second log line, and nothing for the caller to announce.
            return false;
        }

        $credential->refresh();

        activity('application-password')
            ->performedOn($credential)
            ->causedBy($causer ?? auth()->user())
            ->event('application-password.revoked')
            ->withProperties([
                'name' => $credential->name,
                'prefix' => $credential->prefix,
                'last_used_at' => $credential->last_used_at?->toIso8601String(),
            ])
            ->log('revoked the application password '.$credential->name);

        return true;
    }
}
