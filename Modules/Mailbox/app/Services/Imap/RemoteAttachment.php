<?php

namespace Modules\Mailbox\Services\Imap;

/**
 * What was attached, without the bytes.
 *
 * Phase 4 stores metadata only: Data owns disk and `AttachmentService` is the
 * only writer to it, so downloading the payload here would put a second writer
 * in the system for the sake of a paperclip icon. `partNumber` is kept because
 * it is what lets phase 6 go back and fetch the body of exactly this part
 * without re-parsing the message.
 */
final readonly class RemoteAttachment
{
    public function __construct(
        public string $filename,
        public ?string $mime = null,
        public ?int $sizeBytes = null,
        public ?string $contentId = null,
        public ?string $partNumber = null,
    ) {}
}
