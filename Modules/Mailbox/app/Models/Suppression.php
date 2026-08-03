<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Mailbox\Database\Factories\SuppressionFactory;

/**
 * An address Kargah will not send to again, on any provider.
 *
 * Shared on purpose, and the migration says why: a per-provider list would let
 * a dead address be retried through the next provider, which is precisely how a
 * sending reputation is destroyed. Every send asks this table first, and the
 * unique index on `email` is what makes a doubled webhook harmless — a second
 * report of the same bounce updates the reason rather than adding a row.
 *
 * There is no 'unsuppress' method here on purpose. Removing an address is done
 * from the suppression page, one row at a time, by a person who has read the
 * reason — never in bulk and never by code, because the one thing worse than
 * suppressing a good address is un-suppressing a bad one.
 */
class Suppression extends Model
{
    use HasFactory;

    public const HARD_BOUNCE = 'hard_bounce';

    public const COMPLAINT = 'complaint';

    public const UNSUBSCRIBE = 'unsubscribe';

    public const MANUAL = 'manual';

    public const INVALID = 'invalid';

    /**
     * How the reasons read on a page, in the order they matter.
     *
     * A map rather than `ucfirst(str_replace(...))` because 'Hard bounce' and
     * 'Complaint' are the words a person uses and 'Hard_bounce' is not.
     *
     * @return array<string, string>
     */
    public static function reasons(): array
    {
        return [
            self::HARD_BOUNCE => 'Hard bounce',
            self::COMPLAINT => 'Complaint',
            self::UNSUBSCRIBE => 'Unsubscribed',
            self::INVALID => 'Invalid address',
            self::MANUAL => 'Added by hand',
        ];
    }

    /** The badge each reason wears. Whole class strings — Tailwind's scanner reads source text. */
    public static function badges(): array
    {
        return [
            self::HARD_BOUNCE => 'kt-badge-destructive',
            self::COMPLAINT => 'kt-badge-destructive',
            self::UNSUBSCRIBE => 'kt-badge-outline',
            self::INVALID => 'kt-badge-warning',
            self::MANUAL => 'kt-badge-info',
        ];
    }

    protected $fillable = [
        'email',
        'reason',
        'source',
        'detail',
        'suppressed_at',
    ];

    protected function casts(): array
    {
        return [
            'suppressed_at' => 'datetime',
        ];
    }

    /**
     * Block an address, or update the reason if it is already blocked.
     *
     * The single door onto this table, and the reason webhooks are idempotent:
     * `updateOrCreate` on the unique column means a provider delivering the same
     * bounce twice writes one row both times. `suppressed_at` is only set on
     * insert, because the date that matters is when the address was *first*
     * blocked — overwriting it on every duplicate callback would make the list
     * look like it churns.
     */
    public static function block(string $email, string $reason, ?string $source = null, ?string $detail = null): self
    {
        $email = self::normalise($email);

        /** @var self $row */
        $row = self::query()->firstOrNew(['email' => $email]);

        $row->fill([
            'reason' => $reason,
            'source' => $source,
            'detail' => $detail,
        ]);

        $row->suppressed_at ??= now();

        $row->save();

        return $row;
    }

    /** Whether this address is blocked. Asked before every single send. */
    public static function blocks(string $email): bool
    {
        return self::query()->where('email', self::normalise($email))->exists();
    }

    /**
     * The blocked subset of a list of addresses, in one query.
     *
     * A chunk of fifty recipients would otherwise be fifty existence checks.
     *
     * @param  iterable<string>  $emails
     * @return array<string, self> Keyed by the normalised address.
     */
    public static function among(iterable $emails): array
    {
        $normalised = [];

        foreach ($emails as $email) {
            $normalised[] = self::normalise($email);
        }

        if ($normalised === []) {
            return [];
        }

        return self::query()
            ->whereIn('email', array_values(array_unique($normalised)))
            ->get()
            ->keyBy('email')
            ->all();
    }

    /**
     * The one spelling of an address this table stores.
     *
     * Case-folded because the unique index is the guarantee and
     * `Hello@Example.com` and `hello@example.com` are the same mailbox — on a
     * case-sensitive collation they would otherwise be two rows and the second
     * would not block the first. The local part is technically allowed to be
     * case sensitive; no mailbox provider in use treats it that way, and
     * respecting the standard here would mean sending to an address a person
     * asked to be left alone.
     */
    public static function normalise(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function reasonLabel(): string
    {
        return self::reasons()[$this->reason] ?? ucfirst(str_replace('_', ' ', (string) $this->reason));
    }

    public function badge(): string
    {
        return self::badges()[$this->reason] ?? 'kt-badge-outline';
    }

    public function scopeForReason(Builder $query, string $reason): Builder
    {
        return $query->where('reason', $reason);
    }

    /** Newest first, which is the only order a suppression list is ever read in. */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('suppressed_at')->orderByDesc('id');
    }

    protected static function newFactory(): SuppressionFactory
    {
        return SuppressionFactory::new();
    }
}
