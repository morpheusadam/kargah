<?php

namespace Modules\Data\Models;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Modules\Core\Models\Company;
use Modules\Data\Database\Factories\CredentialFactory;
use Modules\Data\Support\Totp;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * One vault entry.
 *
 * Three rules hold here, and each of them is a test in `VaultTest`.
 *
 * **The plaintext is never an attribute.** `secret`, `totp` and `notes` are
 * virtual: reading one decrypts on the spot, writing one encrypts on the spot,
 * and neither value is ever stored on the model where `toArray()`, a Livewire
 * payload, a log line or a `dd()` could pick it up. The ciphertext columns are
 * in `$hidden` as a second belt on the same trousers.
 *
 * **A reveal is a deliberate round trip.** Nothing here decrypts as a side
 * effect of rendering a list. `Modules\Data\Services\Vault::reveal()` is the
 * only sanctioned reader, and it writes the activity entry as it goes.
 *
 * **The activity log never carries the secret.** `getActivitylogOptions()` lists
 * the columns it may record, and no encrypted column is among them — otherwise
 * rotating a password would write the old one into an append-only table.
 */
class Credential extends Model
{
    use HasFactory;
    use LogsActivity;
    use SoftDeletes;

    /**
     * `secret`, `totp` and `notes` are the virtual names; the `_encrypted`
     * columns they write to are deliberately absent, so a mass assignment can
     * never smuggle raw ciphertext — or worse, plaintext — past the mutators.
     */
    protected $fillable = [
        'name',
        'username',
        'secret',
        'totp',
        'notes',
        'url',
        'category_id',
        'company_id',
        'last_revealed_at',
        'rotated_at',
        'created_by',
    ];

    /**
     * The ciphertext columns never leave the server, not even encrypted.
     *
     * Hiding them is not about the cipher being weak. It is about the number of
     * places a value can end up once it is in an array: a JSON response, a
     * queued job payload, an exception report. The fewer of those it reaches,
     * the fewer have to be audited.
     */
    protected $hidden = [
        'secret_encrypted',
        'totp_encrypted',
        'notes_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'last_revealed_at' => 'datetime',
            'rotated_at' => 'datetime',
        ];
    }

    /* Virtual plaintext ----------------------------------------------------- */

    protected function secret(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->decrypt('secret_encrypted'),
            set: fn (?string $value): array => ['secret_encrypted' => $this->encrypt($value)],
        )->withoutObjectCaching();
    }

    protected function totp(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->decrypt('totp_encrypted'),
            set: fn (?string $value): array => ['totp_encrypted' => $this->encrypt($value)],
        )->withoutObjectCaching();
    }

    protected function notes(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->decrypt('notes_encrypted'),
            set: fn (?string $value): array => ['notes_encrypted' => $this->encrypt($value)],
        )->withoutObjectCaching();
    }

    /**
     * Decrypt one column, or give back nothing.
     *
     * A row written under a rotated `APP_KEY` cannot be read, and throwing here
     * would take down a page that only wanted to know whether a TOTP seed
     * exists. The caller that actually wants the value — `Vault::reveal()` —
     * turns the null into a message the owner can act on.
     */
    private function decrypt(string $column): ?string
    {
        $stored = $this->attributes[$column] ?? null;

        if ($stored === null || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    private function encrypt(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : Crypt::encryptString($value);
    }

    /* Questions answerable without decrypting ------------------------------- */

    public function hasTotp(): bool
    {
        return ($this->attributes['totp_encrypted'] ?? null) !== null;
    }

    public function hasNotes(): bool
    {
        return ($this->attributes['notes_encrypted'] ?? null) !== null;
    }

    /** The mask the list renders. Fixed width, so it leaks not even a length. */
    public function mask(): string
    {
        return str_repeat('•', 12);
    }

    /* Relations and scopes -------------------------------------------------- */

    public function category(): BelongsTo
    {
        return $this->belongsTo(CredentialCategory::class, 'category_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        if (trim($term) === '') {
            return $query;
        }

        $like = '%'.trim($term).'%';

        // Only the columns a person can already see on the page. Searching an
        // encrypted column is impossible anyway, and pretending otherwise would
        // invite someone to add a plaintext index later.
        return $query->where(fn (Builder $q) => $q
            ->where('name', 'like', $like)
            ->orWhere('username', 'like', $like)
            ->orWhere('url', 'like', $like));
    }

    /**
     * The current six-digit code, if this entry carries a seed.
     *
     * Derived on the server every time. Sending the seed to the browser so it
     * could tick a countdown itself would put the second factor in the page,
     * which is the exact thing the vault exists to avoid.
     */
    public function totpCode(): ?string
    {
        $seed = $this->totp;

        return $seed === null ? null : Totp::code($seed);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            // No encrypted column appears here, on purpose: the activity log is
            // append-only, so a secret written into it could never be withdrawn.
            ->logOnly(['name', 'username', 'url', 'category_id', 'company_id', 'rotated_at'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('credential');
    }

    protected static function newFactory(): CredentialFactory
    {
        return CredentialFactory::new();
    }
}
