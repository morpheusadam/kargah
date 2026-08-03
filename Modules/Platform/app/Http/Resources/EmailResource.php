<?php

namespace Modules\Platform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An email in a *listing* — as `Modules\Mailbox\Contracts\EmailReader`'s
 * `forCustomer()` and `paginate()` both hand one back. A preview, never a body:
 * `EmailMessageResource` is the shape for one whole message, and a page of
 * twenty bodies is not a list.
 *
 * Already an array with a stable shape, so this only re-states the fields
 * rather than passing the array through untouched: a key Mailbox adds to its
 * internal shape tomorrow does not silently become part of this API's contract
 * with its own clients.
 */
class EmailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'subject' => $this->resource['subject'],
            'from_name' => $this->resource['from_name'],
            'from_email' => $this->resource['from_email'],
            'preview' => $this->resource['preview'],
            'received_at' => $this->resource['received_at'],
            'is_read' => $this->resource['is_read'],
            'is_starred' => $this->resource['is_starred'],
            'has_attachments' => $this->resource['has_attachments'],
            'folder' => $this->resource['folder'],
        ];
    }
}
