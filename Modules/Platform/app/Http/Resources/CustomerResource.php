<?php

namespace Modules\Platform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer, as `Modules\Core\Contracts\CustomerReader` hands one back.
 *
 * Every other contract this API reads from — `CardReader`, `EmailReader`,
 * `AttachmentService`, and now `InvoiceReader`/`ExpenseReader` — returns plain
 * arrays. `CustomerReader::find()` and `::search()` return the Eloquent
 * `Customer` model itself, which is the one place in this API where "read the
 * contracts, not the models" did not fully hold; see the report. This resource
 * is the containment for that: it names every field it takes off the model
 * rather than calling `toArray()` on it, so a column Core adds tomorrow does
 * not appear in a response nobody decided to expose.
 */
class CustomerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role,
            'company_id' => $this->company_id,
            'archived' => $this->archived_at !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
