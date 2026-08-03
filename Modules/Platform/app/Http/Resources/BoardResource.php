<?php

namespace Modules\Platform\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A board, a list or a card, already shaped by
 * `Modules\Project\Contracts\BoardReader`.
 *
 * One resource for all three rather than three near-identical pass-throughs.
 * The contract publishes `BoardArray`, `BoardListArray` and `CardSummaryArray`
 * in its own docblock and Platform adds nothing to any of them; what this class
 * buys is that every board endpoint goes out through the same `JsonResource`
 * machinery as the rest of the API rather than as a bare `response()->json()`.
 */
class BoardResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
