<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The running count of consecutive soft bounces for one address.
 *
 * A soft bounce is temporary by definition, so none of them suppresses. What
 * this table adds is the ability to notice that "temporary" has stopped being
 * true: past a threshold the address is blocked as invalid, which is the same
 * conclusion a person would reach after watching the same mailbox refuse five
 * campaigns in a row.
 *
 * Two rules keep it honest, and both are the difference between this helping
 * and this quietly eating a good list:
 *
 * 1. **A delivery clears the count.** Not decrements — clears. The mailbox
 *    accepted a message, so whatever was wrong with it is over, and starting
 *    the next run of bad luck from three would suppress on the first refusal.
 * 2. **The suppression is written by the caller, not here.** This model counts;
 *    `WebhookProcessor` decides. Blocking an address is the one action in
 *    Mailbox that is not undone by code, and it should be reachable by reading
 *    the class that handles callbacks rather than by discovering it inside a
 *    counter.
 */
class SoftBounceTally extends Model
{
    protected $fillable = [
        'email',
        'count',
        'last_bounced_at',
    ];

    protected function casts(): array
    {
        return [
            'count' => 'integer',
            'last_bounced_at' => 'datetime',
        ];
    }

    /**
     * Record one soft bounce, and say how many in a row that makes.
     *
     * The increment is done in the database rather than read-modify-write:
     * two callbacks for the same address arriving together would otherwise both
     * read the same number and both write one more than it, and the count would
     * drift below the truth for exactly the addresses that bounce most.
     */
    public static function record(string $email): int
    {
        $email = Suppression::normalise($email);

        if ($email === '') {
            return 0;
        }

        $row = self::query()->firstOrCreate(['email' => $email], [
            'count' => 0,
            'last_bounced_at' => null,
        ]);

        self::query()->whereKey($row->getKey())->update([
            'count' => DB::raw('"count" + 1'),
            'last_bounced_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) self::query()->whereKey($row->getKey())->value('count');
    }

    /**
     * Forget an address's run of soft bounces, because something got through.
     *
     * Deleted rather than zeroed: a row whose count is nought says nothing a
     * missing row does not, and leaving one per address ever mailed makes this
     * table grow with the list rather than with the problem.
     */
    public static function clear(string $email): void
    {
        $email = Suppression::normalise($email);

        if ($email === '') {
            return;
        }

        self::query()->where('email', $email)->delete();
    }

    /** How many in a row this address has refused, or zero if it has a clean record. */
    public static function countFor(string $email): int
    {
        return (int) self::query()->where('email', Suppression::normalise($email))->value('count');
    }
}
