<?php

namespace Modules\Platform\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Platform\Support\Scopes;

/**
 * One named credential a program can authenticate with.
 *
 * **The plaintext is not an attribute of this model and never was.** It exists
 * for the length of the request that created it, is returned from
 * `ApplicationPasswordIssuer::issue()`, and is written to exactly one place:
 * the page, once. `token_hash` is the only trace, and it is one-way.
 *
 * `token_hash` is deliberately absent from `$fillable`, so no mass assignment
 * anywhere can set it — the issuer writes it with `forceFill`, which is the one
 * creation path in the application. That is also why there is no factory: a
 * second way to make one of these is a second way to make one wrongly.
 *
 * The secret format is four groups of six lower-case alphanumerics —
 * `k7m2xq-4bnv8t-zr93wd-6ehjs1`. Chosen so a person can retype it off a screen
 * and a shell can quote it without escaping anything, which is the whole point
 * of Basic auth over a bearer token. 24 characters out of an alphabet of 36 is
 * a little over 124 bits, drawn from `random_int` — the CSPRNG, never `rand`.
 */
class ApplicationPassword extends Model
{
    /** Lower case and digits only: no shell metacharacters, nothing to escape. */
    public const ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public const GROUPS = 4;

    public const GROUP_LENGTH = 6;

    /**
     * `token_hash` is not here, and must not be. It is written by the issuer
     * through `forceFill` and by nothing else.
     */
    protected $fillable = [
        'user_id',
        'name',
        'prefix',
        'scopes',
        'expires_at',
    ];

    /**
     * The hash never leaves the server, not even hashed.
     *
     * Hiding it is not about bcrypt being weak. It is about the number of
     * places a value ends up once it is in an array: a JSON response, a queued
     * job payload, an exception report. The fewer of those, the fewer to audit.
     */
    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /* Generation ------------------------------------------------------------ */

    public static function generateSecret(): string
    {
        $alphabet = self::ALPHABET;
        $last = strlen($alphabet) - 1;
        $groups = [];

        for ($group = 0; $group < self::GROUPS; $group++) {
            $chunk = '';

            for ($i = 0; $i < self::GROUP_LENGTH; $i++) {
                // random_int, not rand: this is a credential, so the generator
                // has to be the cryptographic one.
                $chunk .= $alphabet[random_int(0, $last)];
            }

            $groups[] = $chunk;
        }

        return implode('-', $groups);
    }

    /**
     * The stored, non-secret half of a secret: its first group.
     *
     * Six characters out of twenty-four narrows a lookup to a handful of rows
     * without being worth guessing on its own.
     */
    public static function prefixOf(string $secret): string
    {
        return substr(trim($secret), 0, self::GROUP_LENGTH);
    }

    /* Relations and state --------------------------------------------------- */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Revoked or expired are the same answer to a request: no. */
    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes ?? [], true);
    }

    /** @return list<string> */
    public function grantedScopes(): array
    {
        return Scopes::sanitise($this->scopes ?? []);
    }

    /** The one-word state the settings page prints. */
    public function state(): string
    {
        return match (true) {
            $this->isRevoked() => 'revoked',
            $this->isExpired() => 'expired',
            default => 'active',
        };
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query
            ->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
