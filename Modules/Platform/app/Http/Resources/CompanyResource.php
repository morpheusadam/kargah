<?php

namespace Modules\Platform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A company, already shaped by `Modules\Core\Contracts\CompanyReader`.
 *
 * A pass-through, like `InvoiceResource` and unlike `CustomerResource`. The
 * difference is which side owns the shape: `CustomerReader` hands back an
 * Eloquent model, so something has to decide which columns become JSON and that
 * decision lives here. `CompanyReader` hands back an array whose keys are
 * written out in its own docblock — restating them here would be a second copy
 * of the same list, and a second copy is a second place to forget.
 */
class CompanyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
