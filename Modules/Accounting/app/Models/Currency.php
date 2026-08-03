<?php

namespace Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Accounting\Database\Factories\CurrencyFactory;
use Modules\Accounting\Support\Currencies;
use Modules\Accounting\Support\Money;

/**
 * A currency this install deals in.
 *
 * The primary key is the code itself. 'USD' is already the stable identifier
 * every other table stores in its `currency` column, and an auto-increment id
 * beside it would add a join to answer a question the string already answers —
 * plus a second place for the two to disagree.
 *
 * `minor_unit` is a *display* scale, not a storage one. Every money column in
 * the module is `decimal(20,6)` whatever the currency, so a lira and a tether
 * stay comparable in raw SQL. See the migration.
 *
 * The table is not the authority on whether the money layer can handle a code:
 * `Currencies` is. A row here that `Currencies` has never heard of would break
 * at the first conversion, which is what `isSupported()` is for.
 */
class Currency extends Model
{
    use HasFactory;

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code', 'name', 'symbol', 'minor_unit', 'is_crypto', 'is_active', 'position',
    ];

    protected function casts(): array
    {
        return [
            // `position` here is a display order — an unsignedTinyInteger, not
            // the decimal(20,10) ordering key `invoice_lines` uses.
            'minor_unit' => 'integer',
            'position' => 'integer',
            'is_crypto' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('position');
    }

    public function scopeCrypto(Builder $query): Builder
    {
        return $query->where('is_crypto', true);
    }

    public function scopeFiat(Builder $query): Builder
    {
        return $query->where('is_crypto', false);
    }

    /** True when the money layer can actually do arithmetic in this code. */
    public function isSupported(): bool
    {
        return Currencies::isKnown($this->code);
    }

    /** A stored decimal string, as this currency displays it. */
    public function format(string|int|null $amount): string
    {
        return Money::format($amount, $this->code);
    }

    protected static function newFactory(): CurrencyFactory
    {
        return CurrencyFactory::new();
    }
}
