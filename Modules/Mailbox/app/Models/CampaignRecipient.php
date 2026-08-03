<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Mailbox\Database\Factories\CampaignRecipientFactory;
use Modules\Mailbox\Support\Tokens;

/**
 * One person, one campaign, one delivery.
 *
 * This row is where the module's whole safety lives, and the migration's
 * docblock says why: a 500-recipient campaign is dispatched in chunks from
 * cron, a worker can die at any point, and the only thing that survives a hard
 * kill is what the database already wrote.
 *
 * Two properties carry the guarantee, and neither is a counter:
 *
 * - **The unique index on (campaign_id, email) means the row is the claim.**
 *   There cannot be a second row for the same address, so 'sent twice' would
 *   have to mean the same row was taken twice.
 * - **The status only moves forward.** `pending` is the only status a claim can
 *   take, and it is taken by a conditional `UPDATE … WHERE status = 'pending'`
 *   whose affected-row count is checked. The database applies that atomically:
 *   two workers racing produce one row affected and one that got nothing.
 *
 * `attempts` is a diagnostic. A worker killed between the increment and the
 * send leaves a count that lies, which is exactly why nothing in this module
 * decides anything by reading it.
 *
 * A `claimed` row whose worker never came back is **not** re-sent. It is moved
 * to `failed` and named on the report, because the one thing that cannot be
 * known about it is whether the provider already accepted it — and sending a
 * campaign twice to the same person is a worse outcome than not sending it at
 * all. See `CampaignSender::releaseStaleClaims()`.
 */
class CampaignRecipient extends Model
{
    use HasFactory;

    public const PENDING = 'pending';

    public const CLAIMED = 'claimed';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SUPPRESSED = 'suppressed';

    public const BOUNCED = 'bounced';

    public const COMPLAINED = 'complained';

    /** The ceiling `attempts` can reach — the column is an unsigned tiny integer. */
    public const MAX_ATTEMPTS_RECORDED = 255;

    protected $fillable = [
        'campaign_id',
        'contact_id',
        'email',
        'name',
        'status',
        'delivery_provider_id',
        'message_id',
        'unsubscribe_token',
        'reply_token',
        'attempts',
        'error',
        'claimed_at',
        'sent_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::PENDING => 'Pending',
            self::CLAIMED => 'Sending',
            self::SENT => 'Sent',
            self::FAILED => 'Failed',
            self::SUPPRESSED => 'Suppressed',
            self::BOUNCED => 'Bounced',
            self::COMPLAINED => 'Complained',
        ];
    }

    /** Whole class strings — Tailwind's scanner reads source text, never a concatenation. */
    public static function badges(): array
    {
        return [
            self::PENDING => 'kt-badge-outline',
            self::CLAIMED => 'kt-badge-warning',
            self::SENT => 'kt-badge-success',
            self::FAILED => 'kt-badge-destructive',
            self::SUPPRESSED => 'kt-badge-outline',
            self::BOUNCED => 'kt-badge-destructive',
            self::COMPLAINED => 'kt-badge-destructive',
        ];
    }

    /**
     * The statuses that mean the message reached a provider.
     *
     * A bounce or a complaint is reported *after* delivery was attempted, so
     * both belong here — the report's 'carried by' split counts them, and so
     * does the guard that stops a webhook double-counting.
     *
     * @return list<string>
     */
    public static function deliveredStatuses(): array
    {
        return [self::SENT, self::BOUNCED, self::COMPLAINED];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class, 'delivery_provider_id');
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? ucfirst((string) $this->status);
    }

    public function badge(): string
    {
        return self::badges()[$this->status] ?? 'kt-badge-outline';
    }

    public function wasDelivered(): bool
    {
        return in_array($this->status, self::deliveredStatuses(), true);
    }

    /** How the recipient reads in a table when the contact carried no name. */
    public function label(): string
    {
        return $this->name ?: (string) $this->email;
    }

    /**
     * Give this row the two tokens its message will carry.
     *
     * Derived from the row id and signed, so re-running the same chunk mints
     * the identical pair rather than invalidating a link that has already been
     * posted to somebody. Saved only when they were not already there, which is
     * what keeps a second run from touching `updated_at`.
     */
    public function ensureTokens(): static
    {
        if ($this->unsubscribe_token !== null && $this->reply_token !== null) {
            return $this;
        }

        $this->forceFill([
            'unsubscribe_token' => $this->unsubscribe_token ?? Tokens::for(Tokens::UNSUBSCRIBE, (int) $this->id),
            'reply_token' => $this->reply_token ?? Tokens::for(Tokens::REPLY, (int) $this->id),
        ])->save();

        return $this;
    }

    /**
     * Rows a send may take.
     *
     * `pending` and nothing else. `claimed` is absent because a claim held by a
     * worker that never came back is not safe to repeat, and every other status
     * is terminal. This scope is the readable half of the guarantee; the
     * enforcing half is the conditional update in `CampaignSender::claim()`,
     * which is what actually stops two workers taking the same row.
     */
    public function scopeClaimable(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    /**
     * Claims nobody is coming back for.
     *
     * A worker killed mid-send leaves its row on `claimed` with no way to know
     * whether the provider accepted the message. Past this window the row is
     * assumed abandoned — see `CampaignSender::releaseStaleClaims()` for what
     * happens to it, which is not a retry.
     */
    public function scopeStaleClaims(Builder $query, ?int $staleMinutes = null): Builder
    {
        $stale = now()->subMinutes($staleMinutes ?? (int) config('mailbox.sending.stale_claim_minutes', 15));

        return $query->where('status', self::CLAIMED)
            ->where(fn (Builder $when) => $when
                ->whereNull('claimed_at')
                ->orWhere('claimed_at', '<', $stale));
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('email', 'like', '%'.$term.'%')
            ->orWhere('name', 'like', '%'.$term.'%'));
    }

    protected static function newFactory(): CampaignRecipientFactory
    {
        return CampaignRecipientFactory::new();
    }
}
