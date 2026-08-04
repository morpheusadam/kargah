<?php

namespace Modules\Social\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Social\Database\Factories\PostTargetFactory;

/**
 * One post, one account, one delivery.
 *
 * This row is where the module's whole design lives. Status is here rather than
 * on the post because a post going to two networks succeeds on one and fails on
 * the other far more often than it succeeds or fails as a whole, and a single
 * status column would force a retry to resend the one that worked.
 *
 * Two properties make a retry safe, and neither of them is a counter:
 *
 * - **The status is forward-only.** `published` is terminal. Nothing in this
 *   module moves a target out of it, so a retry that scans for outstanding work
 *   cannot find a success and cannot resend it.
 * - **The unique index on (post_id, social_account_id) means the row is the
 *   claim.** Claiming is a conditional `update` on the status, which the
 *   database applies atomically; two workers racing produce one affected row
 *   and one that got nothing. `attempts` is a diagnostic, not a lock — a worker
 *   killed after incrementing it and before sending would leave a count that
 *   lies, which is exactly why nothing decides anything by reading it.
 */
class PostTarget extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const PUBLISHING = 'publishing';

    public const PUBLISHED = 'published';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    /** The ceiling `attempts` can reach — the column is an unsigned tiny integer. */
    public const MAX_ATTEMPTS_RECORDED = 255;

    protected $fillable = [
        'post_id',
        'social_account_id',
        'body_override',
        'options',
        'status',
        'remote_id',
        'remote_url',
        'error',
        'attempts',
        'published_at',
        'last_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'options' => 'array',
            'published_at' => 'datetime',
            'last_attempt_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    /**
     * The copy this network actually receives.
     *
     * `body_override` exists because the same thought does not fit two networks
     * the same way, and rewriting the post to suit one of them would change
     * what the others published.
     */
    public function text(): string
    {
        return $this->body_override ?? ($this->post?->body ?? '');
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::FAILED;
    }

    /**
     * Targets a publish run may take.
     *
     * `published` is absent and that is the entire point of this scope — it is
     * the guarantee that a retry does not resend a success. `publishing` is
     * present only past the stale window, for the worker that was killed
     * holding a claim; see config('social.stale_claim_minutes').
     */
    public function scopeClaimable(Builder $query, ?int $staleMinutes = null): Builder
    {
        $stale = now()->subMinutes($staleMinutes ?? (int) config('social.stale_claim_minutes', 15));

        return $query->where(fn (Builder $q) => $q
            ->whereIn('status', [self::PENDING, self::FAILED])
            ->orWhere(fn (Builder $stalled) => $stalled
                ->where('status', self::PUBLISHING)
                ->where(fn (Builder $when) => $when
                    ->whereNull('last_attempt_at')
                    ->orWhere('last_attempt_at', '<', $stale))));
    }

    protected static function newFactory(): PostTargetFactory
    {
        return PostTargetFactory::new();
    }
}
