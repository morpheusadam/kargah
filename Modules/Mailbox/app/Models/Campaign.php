<?php

namespace Modules\Mailbox\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Concerns\Linkable;
use Modules\Mailbox\Database\Factories\CampaignFactory;

/**
 * One bulk send.
 *
 * The counters here are a summary and `campaign_recipients` is the truth. That
 * ordering is deliberate and it is what the migration's docblock is about: a
 * counter is what a killed worker leaves lying, so every figure this model
 * reports is recomputed from the rows rather than tracked as work happens.
 * `syncCounters()` is therefore idempotent — running it twice writes the same
 * numbers — and a campaign whose rows were fixed by hand heals on the next
 * tick instead of staying wrong forever.
 *
 * The status only moves forward through a send: `sending` never returns to
 * `scheduled`, and `sent` is terminal. Pausing is the one exception and it is
 * a person's decision rather than the system's.
 */
class Campaign extends Model
{
    use HasFactory;
    use Linkable;
    use SoftDeletes;

    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const PAUSED = 'paused';

    public const FAILED = 'failed';

    /**
     * The token a body must carry before the pre-flight will let it go out.
     *
     * A placeholder rather than a rendered link, because the URL is signed per
     * recipient and cannot exist while the campaign is being written. The
     * message builder substitutes it; the pre-flight refuses a body without it.
     */
    public const UNSUBSCRIBE_PLACEHOLDER = '{{unsubscribe_url}}';

    protected $fillable = [
        'name',
        'subject',
        'preheader',
        'body_html',
        'body_text',
        'delivery_provider_id',
        'status',
        'scheduled_for',
        'started_at',
        'finished_at',
        'recipient_count',
        'sent_count',
        'failed_count',
        'bounced_count',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'recipient_count' => 'integer',
            'sent_count' => 'integer',
            'failed_count' => 'integer',
            'bounced_count' => 'integer',
        ];
    }

    /** @return array<string, string> */
    public static function statuses(): array
    {
        return [
            self::DRAFT => 'Draft',
            self::SCHEDULED => 'Scheduled',
            self::SENDING => 'Sending',
            self::SENT => 'Sent',
            self::PAUSED => 'Paused',
            self::FAILED => 'Failed',
        ];
    }

    /** Whole class strings — Tailwind's scanner reads source text, never a concatenation. */
    public static function badges(): array
    {
        return [
            self::DRAFT => 'kt-badge-outline',
            self::SCHEDULED => 'kt-badge-info',
            self::SENDING => 'kt-badge-warning',
            self::SENT => 'kt-badge-success',
            self::PAUSED => 'kt-badge-warning',
            self::FAILED => 'kt-badge-destructive',
        ];
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(DeliveryProvider::class, 'delivery_provider_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function statusLabel(): string
    {
        return self::statuses()[$this->status] ?? ucfirst((string) $this->status);
    }

    public function badge(): string
    {
        return self::badges()[$this->status] ?? 'kt-badge-outline';
    }

    public function isSending(): bool
    {
        return $this->status === self::SENDING;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::SCHEDULED, self::PAUSED, self::FAILED], true);
    }

    /** Whether the body carries the placeholder the message builder turns into a one-click link. */
    public function hasUnsubscribeLink(): bool
    {
        return str_contains((string) $this->body_html, self::UNSUBSCRIBE_PLACEHOLDER)
            || str_contains((string) $this->body_text, self::UNSUBSCRIBE_PLACEHOLDER);
    }

    /**
     * Campaigns the scheduler should be pushing along.
     *
     * `sending` is included with no time condition because a campaign already
     * under way needs a chunk every tick until it runs out of recipients.
     * `scheduled` joins it once its moment has passed. Neither `paused` nor
     * `sent` can be picked up, which is what makes those two states mean
     * something.
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('status', self::SENDING)
            ->orWhere(fn (Builder $ready) => $ready
                ->where('status', self::SCHEDULED)
                ->whereNotNull('scheduled_for')
                ->where('scheduled_for', '<=', now())))
            ->orderBy('scheduled_for')
            ->orderBy('id');
    }

    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeInReadingOrder(Builder $query): Builder
    {
        return $query->orderByDesc('id');
    }

    /**
     * Recompute every counter from the recipient rows.
     *
     * Idempotent by construction: it derives rather than increments, so calling
     * it after every chunk — and again after a webhook — produces the same
     * numbers, and a worker killed between a send and its counter update costs
     * nothing but a stale figure until the next tick.
     *
     * The campaign is only saved when the recomputation actually changed
     * something. That is what lets a re-run of the same chunk leave
     * `updated_at` alone, which is the difference a test can see between 'did
     * nothing' and 'did the same thing again'.
     */
    public function syncCounters(): static
    {
        $counts = $this->recipients()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = (int) $counts->sum();
        $sent = (int) $counts->get(CampaignRecipient::SENT, 0);
        $bounced = (int) $counts->get(CampaignRecipient::BOUNCED, 0);
        $complained = (int) $counts->get(CampaignRecipient::COMPLAINED, 0);
        $failed = (int) $counts->get(CampaignRecipient::FAILED, 0);
        $suppressed = (int) $counts->get(CampaignRecipient::SUPPRESSED, 0);

        $outstanding = $total - $sent - $bounced - $complained - $failed - $suppressed;

        $this->fill([
            'recipient_count' => $total,
            // A bounced or complained recipient was sent to; the provider only
            // told us afterwards. Counting them out of `sent_count` would make
            // the report say fewer messages left than actually did.
            'sent_count' => $sent + $bounced + $complained,
            'failed_count' => $failed,
            'bounced_count' => $bounced + $complained,
        ]);

        if ($this->status === self::SENDING && $outstanding === 0 && $total > 0) {
            $this->fill(['status' => self::SENT, 'finished_at' => $this->finished_at ?? now()]);
        }

        if ($this->isDirty()) {
            $this->save();
        }

        return $this;
    }

    /**
     * Who carried how much of this campaign, and how it went for them.
     *
     * This is the acceptance criterion made visible: when one provider's quota
     * runs out mid-campaign the rest goes through the next one, and the only
     * place that shows is `campaign_recipients.delivery_provider_id`. Grouping
     * on it is what turns 'the send finished' into 'Brevo took ten of these and
     * Mailgun took twenty'.
     *
     * Counted in one grouped query rather than by loading the rows, because a
     * 500-recipient campaign report should not be five hundred models.
     *
     * @return list<array{
     *     id: int|null, name: string, carried: int, delivered: int,
     *     bounced: int, complained: int, failed: int, share: int
     * }>
     */
    public function providerBreakdown(): array
    {
        $rows = $this->recipients()
            ->selectRaw('delivery_provider_id, status, count(*) as total')
            ->whereNotNull('delivery_provider_id')
            ->groupBy('delivery_provider_id', 'status')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $names = DeliveryProvider::query()
            ->withTrashed()
            ->whereIn('id', $rows->pluck('delivery_provider_id')->unique())
            ->pluck('name', 'id');

        $carriedTotal = (int) $rows->sum('total');
        $out = [];

        foreach ($rows->groupBy('delivery_provider_id') as $providerId => $group) {
            $carried = (int) $group->sum('total');
            $count = fn (string $status): int => (int) ($group->firstWhere('status', $status)->total ?? 0);

            $out[] = [
                'id' => $providerId === null ? null : (int) $providerId,
                // A provider deleted after a campaign still has to be named on
                // the report, so this reads the trashed row rather than the
                // relation, and falls back to something rather than blank.
                'name' => $names[$providerId] ?? 'Removed provider',
                'carried' => $carried,
                'delivered' => $count(CampaignRecipient::SENT),
                'bounced' => $count(CampaignRecipient::BOUNCED),
                'complained' => $count(CampaignRecipient::COMPLAINED),
                'failed' => $count(CampaignRecipient::FAILED),
                'share' => $carriedTotal === 0 ? 0 : (int) round($carried / $carriedTotal * 100),
            ];
        }

        usort($out, fn (array $a, array $b): int => $b['carried'] <=> $a['carried']);

        return $out;
    }

    /** How many recipients are still waiting for their turn. */
    public function outstandingCount(): int
    {
        return $this->recipients()->whereIn('status', [
            CampaignRecipient::PENDING,
            CampaignRecipient::CLAIMED,
        ])->count();
    }

    protected static function newFactory(): CampaignFactory
    {
        return CampaignFactory::new();
    }
}
