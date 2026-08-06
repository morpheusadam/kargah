<?php

namespace Modules\Social\Services\Publishers;

use Carbon\CarbonInterface;

/**
 * A replacement credential, and the moment the network says it dies.
 *
 * **The expiry comes from the network rather than from the catalogue**, and that
 * is the whole reason this is a pair rather than a bare string.
 * `Networks::tokenLifetimeDays()` is Kargah's *estimate*, used at connect time
 * because a pasted token says nothing about itself; a refresh answers with
 * `expires_in` in seconds, which is the real number. Storing the estimate over
 * an answer we were given would be choosing the guess.
 *
 * There is no `refreshedAt`: `social_accounts.last_checked_at` already records
 * when the account was last spoken to, and a second timestamp saying almost the
 * same thing is the sort of column that drifts out of agreement with the first.
 */
final class RefreshedToken
{
    public function __construct(
        public readonly string $accessToken,
        public readonly CarbonInterface $expiresAt,
    ) {
        if (trim($accessToken) === '') {
            throw new \InvalidArgumentException('a refreshed token needs an access token');
        }
    }
}
