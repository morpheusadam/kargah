<?php

namespace Modules\Mailbox\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Mailbox\Database\Factories\EmailAttachmentFactory;

/**
 * What was attached to a message — the metadata, not the bytes.
 *
 * Data owns storage, so the file itself arrives in phase 6 through
 * `AttachmentService` and `attachment_id` stays null until then. Keeping the
 * metadata separately is what lets the inbox show a paperclip, a filename and a
 * size without claiming to have a file it cannot hand over: `hasFile()` is the
 * question a download button must ask.
 */
class EmailAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_id',
        'filename',
        'mime',
        'size_bytes',
        'content_id',
        'part_number',
        'attachment_id',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'attachment_id' => 'integer',
        ];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    /** True once the bytes have actually been stored by Data. */
    public function hasFile(): bool
    {
        return $this->attachment_id !== null;
    }

    /**
     * An inline image referenced by the HTML body rather than a real
     * attachment. The inbox lists the second kind and hides the first.
     */
    public function isInline(): bool
    {
        return $this->content_id !== null;
    }

    /** The size as a person reads it. Binary units, one decimal from MB up. */
    public function formattedSize(): ?string
    {
        if ($this->size_bytes === null) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $size = (float) $this->size_bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return $unit <= 1
            ? round($size).' '.$units[$unit]
            : number_format($size, 1).' '.$units[$unit];
    }

    protected static function newFactory(): EmailAttachmentFactory
    {
        return EmailAttachmentFactory::new();
    }
}
