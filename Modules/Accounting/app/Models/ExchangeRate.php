<?php

namespace Modules\Accounting\Models;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\Accounting\Database\Factories\ExchangeRateFactory;
use Modules\Accounting\Support\Currencies;

/**
 * One rate, on one date, from one source.
 *
 * There are no timestamps. The table carries `fetched_at` instead and nothing
 * else, because a row is never updated in place: a correction is a new row for
 * a new `as_of`, and re-running the fetch job for a day it already holds writes
 * the same row again rather than a second one. An `updated_at` would imply
 * history gets rewritten here, and it does not.
 *
 * `rate` is `decimal:6`, so it reads back as a string. A rate that arrived as a
 * float would put the error into every invoice converted with it.
 */
class ExchangeRate extends Model
{
    use HasFactory;

    /** The table has `fetched_at` and no created/updated pair. */
    public $timestamps = false;

    protected $fillable = [
        'base_currency', 'quote_currency', 'rate', 'rate_type', 'source', 'as_of', 'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'fetched_at' => 'datetime',
        ];
    }

    /**
     * A date, and stored as one.
     *
     * `as_of` is deliberately not a `date` cast. Eloquent writes every date
     * cast through the connection's format, `Y-m-d H:i:s`, so a DATE column
     * ends up holding '2026-06-24 00:00:00' — and the business key that makes
     * `ExchangeRates::record()` safe to run from cron matches on '2026-06-24'
     * and misses. Re-running the fetch job would then insert a duplicate and
     * hit the unique index, which is the one thing that key exists to prevent.
     *
     * So the mutator writes a bare date and the accessor hands back a Carbon,
     * which is what the column says and what every caller already expects.
     */
    protected function asOf(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?Carbon => $value === null ? null : Carbon::parse($value)->startOfDay(),
            set: fn (Carbon|\DateTimeInterface|string|null $value): ?string => match (true) {
                $value === null => null,
                $value instanceof \DateTimeInterface => Carbon::instance($value)->toDateString(),
                default => Carbon::parse($value)->toDateString(),
            },
        );
    }

    public function scopePair(Builder $query, string $base, string $quote): Builder
    {
        return $query
            ->where('base_currency', strtoupper($base))
            ->where('quote_currency', strtoupper($quote));
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('rate_type', $type);
    }

    /** Newest business date first — the order a lookup wants. */
    public function scopeLatestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('as_of')->orderByDesc('id');
    }

    /**
     * The same rate read the other way round, as a decimal string.
     *
     * The division states its scale and its rounding out loud. An unstated
     * division is how a rate quietly acquires an error nobody can trace back.
     */
    public function inverted(): string
    {
        return (string) BigDecimal::one()
            ->dividedBy(BigDecimal::of((string) $this->rate), Currencies::STORAGE_SCALE, RoundingMode::HalfUp);
    }

    /** How the pair reads on screen: 'USD/TRY'. */
    public function pair(): string
    {
        return $this->base_currency.'/'.$this->quote_currency;
    }

    /** True when this came from the Turkish central bank rather than a market feed. */
    public function isOfficial(): bool
    {
        return str_starts_with((string) $this->rate_type, 'tcmb_');
    }

    protected static function newFactory(): ExchangeRateFactory
    {
        return ExchangeRateFactory::new();
    }
}
