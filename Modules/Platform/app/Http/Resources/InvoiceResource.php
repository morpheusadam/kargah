<?php

namespace Modules\Platform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An invoice, already shaped by `Modules\Accounting\Contracts\InvoiceReader`.
 *
 * The contract does the shaping — money as `{amount, currency, formatted}`,
 * lines flattened, customer and company reduced to `{id, name}` — because that
 * is Accounting's own domain, not Platform's. This resource exists so the
 * response goes through the same `JsonResource` machinery every other endpoint
 * uses, not because there is anything left to transform.
 */
class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
