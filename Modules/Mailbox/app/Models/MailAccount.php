<?php

namespace Modules\Mailbox\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Crypt;
use Modules\Mailbox\Database\Factories\MailAccountFactory;

/**
 * A mailbox Kargah reads.
 *
 * The IMAP password is the only secret this application stores on behalf of a
 * third party, and it is handled in three layers rather than one:
 *
 * - the column is cast `encrypted`, so the plaintext never reaches the disk;
 * - `password` is the only name anything outside this class uses, so no caller
 *   has to know which column the ciphertext lives in;
 * - both names are in `$hidden`, because the `encrypted` cast *decrypts on
 *   read* — without that line `toArray()` would hand out the plaintext, and a
 *   Livewire component that dumps a model into its payload would put it in the
 *   page source. `MailboxModelTest` asserts it.
 *
 * `sync_cursor` and `uid_validity` belong to the IMAP job rather than to the
 * account as a person would describe it; they live here because resuming a sync
 * is an attribute of the mailbox, not of the run that happened to be doing it.
 */
class MailAccount extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** Polled by `mailbox:sync-imap` over a socket this application opens. */
    public const KIND_IMAP = 'imap';

    /** Written by the Email Worker's POST; nothing is ever connected to. */
    public const KIND_INBOUND = 'inbound';

    /**
     * `imap_password_encrypted` is deliberately absent.
     *
     * A form posts `password`; nothing should be able to mass-assign a
     * ciphertext blob straight into the column and skip the mutator.
     */
    protected $fillable = [
        'name',
        'email',
        'kind',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'imap_validate_cert',
        'imap_username',
        'password',
        'default_folder',
        'sync_cursor',
        'uid_validity',
        'last_synced_at',
        'last_error',
        'is_active',
        'created_by',
    ];

    /** @var list<string> */
    protected $hidden = [
        'imap_password_encrypted',
        'password',
    ];

    protected function casts(): array
    {
        return [
            'imap_port' => 'integer',
            'imap_validate_cert' => 'boolean',
            'imap_password_encrypted' => 'encrypted',
            'sync_cursor' => 'integer',
            'uid_validity' => 'integer',
            'last_synced_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The password as everything else spells it.
     *
     * Reading gives plaintext because the `encrypted` cast decrypts on the way
     * out. Writing encrypts here rather than leaving it to the same cast, and
     * that asymmetry is not an oversight: whatever a mutator *returns* is
     * merged into the raw attribute array untouched, so the obvious
     * `return ['imap_password_encrypted' => $value]` skips the cast and stores
     * the IMAP password in clear. It did, until
     * `MailboxModelTest::test_the_password_is_ciphertext_on_disk` said so.
     *
     * The cast still earns its place: it covers a direct write to the column,
     * and it is what makes `$hidden` load-bearing rather than decorative.
     */
    protected function password(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->imap_password_encrypted,
            set: fn (?string $value): array => [
                'imap_password_encrypted' => $value === null ? null : Crypt::encryptString($value),
            ],
        );
    }

    public function emails(): HasMany
    {
        return $this->hasMany(Email::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Accounts the scheduled sync should pick up, least recently synced first.
     *
     * A mailbox that has never been synced sorts ahead of one that has, so a
     * newly added account is not starved by a busy one.
     *
     * Inbound accounts are excluded rather than merely failing later. They have
     * no `imap_host` to open, so a tick that picked one up would spend a
     * connection attempt to learn what `kind` already says, then write the
     * refusal to `last_error` — where the owner would read it as a mailbox that
     * has broken rather than one that was never polled in the first place.
     */
    public function scopeDueForSync(Builder $query): Builder
    {
        return $query->active()->polled()->orderByRaw('last_synced_at is null desc')->orderBy('last_synced_at');
    }

    /** Accounts Kargah connects out to, as opposed to ones that are posted in. */
    public function scopePolled(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_IMAP);
    }

    /** Whether messages reach this account by being pushed at it. */
    public function isInbound(): bool
    {
        return $this->kind === self::KIND_INBOUND;
    }

    /** Whether the last sync ended in an error rather than a cursor. */
    public function hasFailed(): bool
    {
        return $this->last_error !== null;
    }

    protected static function newFactory(): MailAccountFactory
    {
        return MailAccountFactory::new();
    }
}
