<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Platform\Http\Resources\BoardResource;
use Modules\Platform\Support\ApiResponse;
use Modules\Project\Contracts\BoardReader;

/**
 * `GET /api/v1/cards`, `GET /api/v1/cards/{card}` — `project:read`.
 *
 * The cross-board views. `GET /api/v1/cards` is not "every card that exists":
 * there is no contract method for that, and there should not be — a workspace's
 * whole card table is not a question anybody asks. It is the two questions the
 * contract does answer across every board at once, behind a required `filter`:
 * what is due soon, and what is overdue.
 *
 * Both come back with their `count` alongside the rows, from
 * `countDueSoon()` / `countOverdue()` rather than from `count($data)`. The rows
 * are capped by `limit`; the count is the real total, and a client showing "12
 * overdue" while holding 20 of 47 rows would otherwise be quietly wrong.
 *
 * A card mirrored onto two lists counts once, because every one of those
 * contract methods goes through `CardPlacement::scopeOnCanvas()`. That is the
 * contract's promise, not this controller's, and it is the reason none of this
 * is assembled here out of `cardsForList()` calls.
 */
class CardController
{
    private const FILTERS = ['due-soon', 'overdue'];

    public function __construct(private readonly BoardReader $boards) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'filter' => ['required', 'string', 'in:'.implode(',', self::FILTERS)],
            // Zero is a real, useful value: "what is due today".
            'days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::validationFailed($validator);
        }

        $limit = (int) $request->query('limit', 20);

        if ($request->query('filter') === 'overdue') {
            return response()->json([
                'data' => BoardResource::collection($this->boards->cardsOverdue($limit)),
                'count' => $this->boards->countOverdue(),
            ]);
        }

        $days = (int) $request->query('days', 30);

        return response()->json([
            'data' => BoardResource::collection($this->boards->cardsDueSoon($days, $limit)),
            'count' => $this->boards->countDueSoon($days),
        ]);
    }

    public function show(int $card): JsonResponse
    {
        $found = $this->boards->findCard($card);

        if ($found === null) {
            return ApiResponse::notFound("Card {$card} does not exist.");
        }

        return response()->json(['data' => new BoardResource($found)]);
    }
}
