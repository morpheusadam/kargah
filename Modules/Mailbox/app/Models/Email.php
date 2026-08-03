<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Core\Concerns\Linkable;
use Modules\Core\Models\Customer;
use Modules\Mailbox\Database\Factories\EmailFactory;

/**
 * One received message.
 *
 * Two things about this model are load-bearing.
 *
 * `message_id` is unique at the database level, which is what makes the IMAP
 * job safe to re-run and therefore safe to run from cron. Nothing here may work
 * around that constraint — a re-sync that finds a message it already has should
 * fail its insert, not invent a second row.
 *
 * An email becomes a card through Core's `links` table, never through a foreign
 * key on either side. A message can end up pointing at a card, an invoice and a
 * customer's other correspondence, and each of those would otherwise be a
 * nullable column that Mailbox has to know about. `customer_id` is the one
 * exception, and it is a real column because resolving a sender to a customer
 * is a lookup the inbox does on every render.
 */
class Email extends Model
{
    use HasFactory;
    use Linkable;
    use SoftDeletes;

    protected $fillable = [
        'mail_account_id',
        'email_thread_id',
        'message_id',
        'in_reply_to',
        'uid',
        'subject',
        'from_name',
        'from_email',
        'to',
        'cc',
        'body_text',
        'body_html',
        'has_attachments',
        'customer_id',
        'is_read',
        'is_starred',
        'folder',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'uid' => 'integer',
            'to' => 'array',
            'cc' => 'array',
            'has_attachments' => 'boolean',
            'is_read' => 'boolean',
            'is_starred' => 'boolean',
            'received_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(EmailThread::class, 'email_thread_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }

    /**
     * The customer this message was resolved to, if the sender matched one.
     *
     * A `belongsTo` on Core's model rather than through `CustomerReader`: this
     * is the relation behind the foreign key the migration already declares,
     * and an eager load is the only way the inbox avoids a query per row. Code
     * that wants customer *data* still goes through the contract.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    public function scopeStarred(Builder $query): Builder
    {
        return $query->where('is_starred', true);
    }

    public function scopeInFolder(Builder $query, string $folder): Builder
    {
        return $query->where('folder', $folder);
    }

    /** Newest first, which is the only order an inbox is ever read in. */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('received_at')->orderByDesc('id');
    }

    public function scopeForCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    /**
     * The line of body text the inbox shows under the subject.
     *
     * `body_text` is preferred because it is what the sender's client already
     * flattened. Falling back to `body_html` means stripping tags, and stripping
     * them naively glues words together across block boundaries — hence the
     * substitution of a space for the closing tags that end a line, and the
     * removal of `<style>` and `<script>` bodies before anything else, so a
     * newsletter does not preview as its own CSS.
     */
    public function preview(int $length = 140): string
    {
        $text = (string) $this->body_text;

        if (trim($text) === '') {
            $html = (string) $this->body_html;
            $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
            $html = preg_replace('#<(br|/p|/div|/li|/tr|/h[1-6])\b[^>]*>#i', ' ', $html) ?? $html;

            $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Quoted-printable soft breaks and the sender's own wrapping both turn
        // into single spaces; a preview has no lines.
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        return Str::limit($text, $length);
    }

    /** How the sender reads when the message carried no display name. */
    public function senderLabel(): string
    {
        return $this->from_name ?: (string) $this->from_email;
    }

    /**
     * `tap($this)->…->save()` returns the boolean from `save()`, not the model.
     * `tap()` only hands the value back when the work happens inside its
     * callback — chaining off it evaluates the chain instead. Declared
     * `: static`, the earlier form threw a TypeError on every call.
     */
    public function markRead(bool $read = true): static
    {
        return tap($this, fn (self $email) => $email->forceFill(['is_read' => $read])->save());
    }

    public function markStarred(bool $starred = true): static
    {
        return tap($this, fn (self $email) => $email->forceFill(['is_starred' => $starred])->save());
    }

    protected static function newFactory(): EmailFactory
    {
        return EmailFactory::new();
    }
}
