<?php

namespace Modules\Platform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One whole message, as `Modules\Mailbox\Contracts\EmailReader::find()` hands
 * it back. `EmailResource` next door is the *listing* shape — a preview, no
 * body — and the two are separate classes because they are separate decisions:
 * a page of twenty bodies is not a list.
 *
 * The fields are restated rather than passed through, for the same reason
 * `EmailResource` restates them: a key Mailbox adds to its internal shape
 * tomorrow does not silently become part of this API's contract.
 *
 * `body` is plain text and `has_html` is a boolean. Mailbox's own contract
 * refuses to ship `body_html` across a module boundary because the consumer
 * would then own sanitising it, and an API client is no better placed to do
 * that than a Blade template was — worse, in fact, since it is not in this
 * repository.
 */
class EmailMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'thread_id' => $this->resource['thread_id'],
            'subject' => $this->resource['subject'],
            'from_name' => $this->resource['from_name'],
            'from_email' => $this->resource['from_email'],
            'to' => $this->resource['to'],
            'cc' => $this->resource['cc'],
            'body' => $this->resource['body'],
            'has_html' => $this->resource['has_html'],
            'received_at' => $this->resource['received_at'],
            'is_read' => $this->resource['is_read'],
            'is_starred' => $this->resource['is_starred'],
            'has_attachments' => $this->resource['has_attachments'],
            'attachment_count' => $this->resource['attachment_count'],
            'folder' => $this->resource['folder'],
            'customer' => $this->resource['customer'],
        ];
    }
}
