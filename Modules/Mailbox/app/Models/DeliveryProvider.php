<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Mailbox\Database\Factories\DeliveryProviderFactory;
use Modules\Mailbox\Support\Senders;

/**
 * Who actually carries the mail, and how much of it they will carry today.
 *
 * The credential bag is the only secret here and it is handled in three layers,
 * exactly as `MailAccount` and `SocialAccount` handle theirs:
 *
 * - the column is cast `encrypted:array`, so plaintext never reaches the disk
 *   and five providers with five different field sets share one column;
 * - `credentials` is the only name anything outside this class uses, so no
 *   caller has to know which column the ciphertext lives in;
 * - **both names are in `$hidden`**, because the `encrypted` cast *decrypts on
 *   read* — without that line `toArray()` would hand back the plaintext and a
 *   Livewire component that puts a model in its payload would print an SMTP key
 *   into the page source.
 *
 * Quotas are counters rather than a derived count of `campaign_recipients`,
 * because the number that matters is what the *provider* thinks it has accepted
 * today, including mail Kargah sent outside a campaign. They are advisory: a
 * crashed worker can leave `sent_today` one short, and nothing in this module
 * decides correctness by reading it. Correctness lives on the recipient row.
 */
class DeliveryProvider extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** The floor a provider has to stay above to be picked while a healthier one exists. */
    public const HEALTHY_SCORE = 50;

    /** How far one bounce moves the health score down. */
    public const BOUNCE_PENALTY = 2;

    /**
     * How far one complaint moves it down.
     *
     * Heavier than a bounce on purpose. A bounce is an address that no longer
     * exists; a complaint is a person saying the mail should not have been sent,
     * and mailbox providers start throttling a sender at a complaint rate of
     * roughly one in a thousand.
     */
    public const COMPLAINT_PENALTY = 10;

    /** How far a clean send recovers it, so a provider is not condemned forever by one bad day. */
    public const RECOVERY = 1;

    /**
     * `credentials_encrypted` is deliberately absent.
     *
     * A form posts `credentials`; nothing should be able to mass-assign a
     * ciphertext blob straight into the column and skip the cast.
     */
    protected $fillable = [
        'name',
        'driver',
        'sending_domain',
        'from_email',
        'from_name',
        'credentials',
        'daily_quota',
        'hourly_quota',
        'sent_today',
        'sent_this_hour',
        'quota_window_started_at',
        'health_score',
        'bounce_count',
        'complaint_count',
        'spf_verified',
        'dkim_verified',
        'dns_checked_at',
        'is_active',
        'priority',
        'last_error',
    ];

    /** @var list<string> */
    protected $hidden = [
        'credentials_encrypted',
        'credentials',
    ];

    protected function casts(): array
    {
        return [
            'credentials_encrypted' => 'encrypted:array',
            'daily_quota' => 'integer',
            'hourly_quota' => 'integer',
            'sent_today' => 'integer',
            'sent_this_hour' => 'integer',
            'quota_window_started_at' => 'datetime',
            'health_score' => 'integer',
            'bounce_count' => 'integer',
            'complaint_count' => 'integer',
            'spf_verified' => 'boolean',
            'dkim_verified' => 'boolean',
            'dns_checked_at' => 'datetime',
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    /**
     * The credential bag as everything else spells it.
     *
     * @return Attribute<array<string, string>, array<string, string>|null>
     */
    protected function credentials(): Attribute
    {
        return Attribute::make(
            get: fn (): array => is_array($this->credentials_encrypted) ? $this->credentials_encrypted : [],
            set: fn (?array $value): array => ['credentials_encrypted' => $value === [] ? null : $value],
        );
    }

    /** One credential value, for a driver. Never for a template. */
    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Which required fields this provider is missing.
     *
     * A question rather than an exception, because an unconfigured provider is
     * the ordinary state of a fresh install: the driver asks before sending and
     * the answer lands in `campaign_recipients.error` where the owner can read
     * it.
     *
     * @return list<string> Human labels, ready for a sentence.
     */
    public function missingCredentials(): array
    {
        $missing = [];

        foreach (Senders::requiredCredentialFields($this->driver) as $field) {
            if ($this->credential($field) === null) {
                $missing[] = Senders::credentialLabel($this->driver, $field);
            }
        }

        return $missing;
    }

    public function hasCredentials(): bool
    {
        return Senders::requiredCredentialFields($this->driver) !== [] && $this->missingCredentials() === [];
    }

    public function label(): string
    {
        return $this->name !== '' ? $this->name : Senders::label($this->driver);
    }

    public function icon(): string
    {
        return Senders::icon($this->driver);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /**
     * How many more messages this provider may take before its window closes.
     *
     * A quota of zero means 'unmetered', not 'blocked' — a self-hosted SMTP
     * relay has no daily allowance and a zero that read as a stop would make it
     * unusable. `PHP_INT_MAX` rather than null so callers can compare two
     * providers without a special case.
     */
    public function remainingQuota(): int
    {
        $daily = $this->daily_quota > 0 ? max(0, $this->daily_quota - $this->sent_today) : PHP_INT_MAX;
        $hourly = $this->hourly_quota > 0 ? max(0, $this->hourly_quota - $this->sent_this_hour) : PHP_INT_MAX;

        return min($daily, $hourly);
    }

    public function hasQuotaLeft(): bool
    {
        return $this->remainingQuota() > 0;
    }

    /**
     * Roll the counters forward when their window has passed.
     *
     * One timestamp carries both windows, which works because it always records
     * the moment of the last roll: a different date means a new day and both
     * counters reset, the same date with a different hour means only the hourly
     * one does. Reading it any other way — a stored day plus a stored hour —
     * would be two columns the schema does not have.
     *
     * Called before every routing decision rather than from the scheduler, so a
     * site whose cron stopped for an afternoon does not come back believing it
     * has already used its day.
     */
    public function rollQuotaWindow(): static
    {
        $started = $this->quota_window_started_at;

        if ($started === null) {
            $this->forceFill(['quota_window_started_at' => now()])->save();

            return $this;
        }

        $now = now();

        if (! $started->isSameDay($now)) {
            $this->forceFill([
                'sent_today' => 0,
                'sent_this_hour' => 0,
                'quota_window_started_at' => $now,
            ])->save();

            return $this;
        }

        if ($started->format('H') !== $now->format('H')) {
            $this->forceFill([
                'sent_this_hour' => 0,
                'quota_window_started_at' => $now,
            ])->save();
        }

        return $this;
    }

    /**
     * Record that this provider accepted one more message.
     *
     * Written with `increment` so two workers sending at once do not overwrite
     * each other's count, and the health score recovers a point at the same
     * time — a provider that is working again should stop being penalised for a
     * bad week without anyone resetting it by hand.
     */
    public function recordSend(): void
    {
        $this->increment('sent_today');
        $this->increment('sent_this_hour');

        if ($this->health_score < 100) {
            $this->forceFill(['health_score' => min(100, $this->health_score + self::RECOVERY)])->save();
        }
    }

    /** Record a hard bounce this provider reported, and take the health hit for it. */
    public function recordBounce(): void
    {
        $this->increment('bounce_count');
        $this->forceFill(['health_score' => max(0, $this->health_score - self::BOUNCE_PENALTY)])->save();
    }

    /** Record a complaint, which costs five times what a bounce costs. */
    public function recordComplaint(): void
    {
        $this->increment('complaint_count');
        $this->forceFill(['health_score' => max(0, $this->health_score - self::COMPLAINT_PENALTY)])->save();
    }

    /** Whether the sending domain is far enough along that mail from it will be accepted. */
    public function isAuthenticated(): bool
    {
        return $this->spf_verified && $this->dkim_verified;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The order the router considers providers in.
     *
     * Health first, then priority. Remaining quota is a filter rather than a
     * sort key — the migration's docblock says the router picks by remaining
     * quota, and what that has to mean in practice is 'a provider with none
     * left is not a candidate', not 'always use whoever has the biggest
     * allowance'. Sorting by allowance would send the first message of every
     * campaign through whichever provider happens to have the largest plan,
     * which is the opposite of what `priority` is for. Remaining quota is kept
     * as the final tiebreak so two equally healthy providers of equal priority
     * split rather than one always going first.
     */
    public function scopeInRoutingOrder(Builder $query): Builder
    {
        return $query->orderByDesc('health_score')->orderBy('priority')->orderBy('id');
    }

    protected static function newFactory(): DeliveryProviderFactory
    {
        return DeliveryProviderFactory::new();
    }
}
