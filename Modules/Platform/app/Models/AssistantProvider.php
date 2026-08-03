<?php

namespace Modules\Platform\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Modules\Platform\Database\Factories\AssistantProviderFactory;
use Modules\Platform\Support\AssistantDrivers;

/**
 * One AI provider Kargah is configured to ask, and how to reach it.
 *
 * The key is handled exactly the way `Modules\Mailbox\Models\DeliveryProvider`
 * handles its credential bag, and for the same reason —
 * `project-guaid/DECISIONS.md`'s entry on the mutator idiom that stores an
 * "encrypted" column in clear text:
 *
 * - the column is cast `encrypted`, so `$this->api_key_encrypted` decrypts on
 *   the way *out*, automatically;
 * - **writing is not left to that cast.** `Attribute::make(set: …)` merges
 *   its return value straight into the model's raw attributes and never
 *   passes through `setAttribute()`, so the cast's own encryption never
 *   runs on a value set this way — left to the cast, the plaintext would
 *   reach the database. `apiKey()`'s setter calls `Crypt::encryptString()`
 *   itself, which is what actually protects it;
 * - **both `api_key_encrypted` and `api_key` are in `$hidden`**, because
 *   `toArray()`/`toJson()` would otherwise decrypt and print the key the
 *   moment any code — a Livewire component's payload, an exception report —
 *   serialises this model.
 *
 * `api_key_encrypted` is deliberately absent from `$fillable`, for the same
 * reason `DeliveryProvider::$credentials_encrypted` is: a form posts
 * `api_key`, and nothing should be able to mass-assign a ciphertext blob
 * straight into the column and skip the cast entirely.
 */
class AssistantProvider extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'driver',
        'model',
        'api_key',
        'base_url',
        'is_active',
    ];

    /**
     * `is_default`, the test-result columns and the raw encrypted column are
     * all deliberately absent. `is_default` is set only through
     * `makeDefault()`, so the demotion of every other row always happens in
     * the same call as the promotion; the test-result columns are written
     * only by `markTestResult()`, which is the one place a "test this
     * connection" result is recorded.
     */
    protected $hidden = [
        'api_key_encrypted',
        'api_key',
    ];

    protected function casts(): array
    {
        return [
            'api_key_encrypted' => 'encrypted',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_test_ok' => 'boolean',
        ];
    }

    /**
     * The key as everything else spells it.
     *
     * Reading gives the plaintext because the `encrypted` cast decrypts on
     * the way out. Writing encrypts *here*, not in the cast — see the class
     * docblock and `project-guaid/DECISIONS.md`.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function apiKey(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => is_string($this->api_key_encrypted) && $this->api_key_encrypted !== ''
                ? $this->api_key_encrypted
                : null,
            set: fn (?string $value): array => [
                'api_key_encrypted' => $value === null || $value === ''
                    ? null
                    : Crypt::encryptString($value),
            ],
        );
    }

    public function hasApiKey(): bool
    {
        return $this->api_key !== null;
    }

    public function requiresApiKey(): bool
    {
        return AssistantDrivers::requiresKey($this->driver);
    }

    public function requiresBaseUrl(): bool
    {
        return AssistantDrivers::requiresBaseUrl($this->driver);
    }

    /** The model to actually send, falling back to the driver's own default when the row leaves it blank. */
    public function effectiveModel(): string
    {
        return $this->model !== null && $this->model !== ''
            ? $this->model
            : AssistantDrivers::defaultModel($this->driver);
    }

    public function label(): string
    {
        return $this->name !== '' ? $this->name : AssistantDrivers::label($this->driver);
    }

    public function icon(): string
    {
        return AssistantDrivers::icon($this->driver);
    }

    public function tone(): string
    {
        return AssistantDrivers::tone($this->driver);
    }

    /**
     * Make this provider the default, demoting whichever one held it.
     *
     * Both writes happen here so the invariant — exactly one default, or none
     * at all — can never be observed half-applied. Idempotent: calling this
     * twice in a row demotes nothing the second time (no other row is left
     * `is_default`) and skips the save on this row (it is already default),
     * so the second call is a no-op rather than a second activity-worthy
     * write. The `!=` guard on the demotion query also means a solitary
     * provider becoming its own default never issues a pointless `UPDATE`
     * against itself.
     */
    public function makeDefault(): void
    {
        static::query()
            ->where('id', '!=', $this->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);

        if (! $this->is_default) {
            $this->forceFill(['is_default' => true])->save();
        }
    }

    /** Record the outcome of a "test this connection" click, so it survives a refresh. */
    public function markTestResult(bool $ok, ?string $error): void
    {
        $this->forceFill([
            'last_tested_at' => now(),
            'last_test_ok' => $ok,
            'last_test_error' => $ok ? null : $error,
        ])->save();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Deleting the default provider must not leave a trashed row claiming to
     * be it.
     *
     * Enforced here rather than only in the settings page, so the invariant
     * holds for every caller — the page today, and a future CLI or API
     * action that deletes a row directly without going through it. Runs on
     * both a hard delete and the ordinary soft delete this model uses,
     * because Eloquent fires `deleting` for both.
     *
     * The row being deleted is cleared with a direct query rather than
     * through `$this`, because a soft delete only ever writes `deleted_at` —
     * leaving `is_default` untouched on the trashed row would make
     * `withTrashed()` show two providers claiming to be the default the
     * moment a new one is promoted below.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $provider): void {
            if (! $provider->is_default) {
                return;
            }

            static::query()->whereKey($provider->getKey())->update(['is_default' => false]);

            static::query()->active()->whereKeyNot($provider->getKey())->orderBy('id')->first()?->makeDefault();
        });
    }

    protected static function newFactory(): AssistantProviderFactory
    {
        return AssistantProviderFactory::new();
    }
}
