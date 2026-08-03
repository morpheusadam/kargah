<?php

namespace Modules\Social\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Modules\Core\Models\Company;
use Modules\Social\Database\Factories\SocialAccountFactory;
use Modules\Social\Support\Networks;

/**
 * One network Kargah is allowed to post to.
 *
 * The credential is the only thing here that matters to get right, and it is
 * handled in three layers rather than one:
 *
 * - the column is cast `encrypted:array`, so plaintext never reaches the disk
 *   and a network's differing field names (a bot token and a chat id, an
 *   instance and an access token) fit one column without a schema per network;
 * - `credentials` is the only name anything outside this class uses, so no
 *   caller has to know which column the ciphertext lives in;
 * - **both names are in `$hidden`**, because the `encrypted` cast *decrypts on
 *   read* — without that line `toArray()` would hand back the plaintext, and a
 *   Livewire component that puts a model in its payload would print it into the
 *   page source. `SocialModuleTest` asserts it, on a rendered page rather than
 *   on an array, because the array is not where it would hurt.
 *
 * There is deliberately no accessor that returns one credential value by name
 * to a template. Drivers read `credentials()`; nothing that renders does.
 */
class SocialAccount extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * `credentials_encrypted` is deliberately absent.
     *
     * A form posts `credentials`; nothing should be able to mass-assign a
     * ciphertext blob straight into the column and skip the cast.
     */
    protected $fillable = [
        'network',
        'handle',
        'display_name',
        'avatar_url',
        'credentials',
        'token_expires_at',
        'company_id',
        'is_active',
        'connected_at',
        'last_checked_at',
        'last_error',
        'created_by',
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
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The credential bag as everything else spells it.
     *
     * Reading gives the decoded array because the `encrypted:array` cast
     * decrypts on the way out. Writing encrypts *here* rather than leaving it
     * to the same cast, and that asymmetry is deliberate: an attribute mutator
     * that returns `['credentials_encrypted' => $value]` merges straight into
     * the model's raw attributes and never reaches `setAttribute`, so the cast
     * has no chance to run. Left to the cast, the plaintext would go to disk —
     * and on an array column it would not even do that, it would fail at the
     * bind with 'array to string conversion'. `json_encode` first, because that
     * is exactly what the read side undoes.
     *
     * @return Attribute<array<string, string>, array<string, string>|null>
     */
    protected function credentials(): Attribute
    {
        return Attribute::make(
            get: fn (): array => is_array($this->credentials_encrypted) ? $this->credentials_encrypted : [],
            set: fn (?array $value): array => [
                'credentials_encrypted' => $value === null || $value === []
                    ? null
                    : Crypt::encryptString((string) json_encode($value)),
            ],
        );
    }

    /** One credential value, for a driver. Never for a template. */
    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Whether every field this network's driver needs is present.
     *
     * Credentials are absent on a fresh install and on this developer's
     * machine, which is normal rather than exceptional — so this is a question
     * the publisher asks before trying, not an error it discovers afterwards.
     */
    public function hasCredentials(): bool
    {
        $fields = Networks::credentialFields($this->network);

        if ($fields === []) {
            return false;
        }

        foreach ($fields as $field) {
            if ($this->credential($field) === null) {
                return false;
            }
        }

        return true;
    }

    public function isConnected(): bool
    {
        return $this->is_active && $this->hasCredentials();
    }

    public function label(): string
    {
        return Networks::label($this->network);
    }

    public function icon(): string
    {
        return Networks::icon($this->network);
    }

    public function characterLimit(): int
    {
        return Networks::limit($this->network);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(PostTarget::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(SocialNotification::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOnNetwork(Builder $query, string $network): Builder
    {
        return $query->where('network', $network);
    }

    /**
     * The order every page lists accounts in.
     *
     * By network first so the same network's accounts sit together, then by
     * handle, so adding an account never reshuffles the page.
     */
    public function scopeInReadingOrder(Builder $query): Builder
    {
        return $query->orderBy('network')->orderBy('handle');
    }

    protected static function newFactory(): SocialAccountFactory
    {
        return SocialAccountFactory::new();
    }
}
