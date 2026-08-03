<?php

namespace Modules\Platform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An expense, already shaped by `Modules\Accounting\Contracts\ExpenseReader`.
 *
 * See `InvoiceResource` — the contract shapes the money and the relations;
 * this is the pass-through that puts the response through `JsonResource`.
 */
class ExpenseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
