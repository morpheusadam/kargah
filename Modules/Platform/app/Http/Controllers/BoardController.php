<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Platform\Http\Resources\BoardResource;
use Modules\Platform\Support\ApiResponse;
use Modules\Project\Contracts\BoardReader;

/**
 * `GET /api/v1/boards`, `GET /api/v1/boards/{board}`,
 * `GET /api/v1/boards/{board}/lists`, `GET /api/v1/lists/{list}/cards`
 * — all `project:read`.
 *
 * Reads through `Modules\Project\Contracts\BoardReader` alone; nothing here
 * imports `Modules\Project\Models\Board`, `BoardList` or `Card`. A board is
 * addressed by **slug**, not by id, because that is the identifier the contract
 * takes and the one the board page already puts in a URL — a client that has
 * seen a board in the interface can use what it read there.
 *
 * Not cursor-paginated, and deliberately so. `BoardReader::boards()` and
 * `::listsForBoard()` return the whole set: a board picker with a cursor on it
 * is a picker nobody can use, and the collections are bounded by how many
 * boards a single-user workspace has rather than by how long it has been
 * running. `cardsForList()` is the same argument one level down — a list is a
 * column somebody scrolls, and paginating it would hand back a page of a column
 * that the interface itself draws whole.
 *
 * **Read only.** `07-platform.md` asks for cards to be writable, including
 * move. There is no contract to write one through: `CardService` is a concrete
 * class in Project with no interface in front of it, and creating or moving a
 * card from here would mean importing it — which is the one thing an edge
 * module may not do. See the report for what a `CardWriter` would need.
 */
class BoardController
{
    public function __construct(private readonly BoardReader $boards) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'include_archived' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::validationFailed($validator);
        }

        $includeArchived = $request->boolean('include_archived');

        return response()->json([
            'data' => BoardResource::collection($this->boards->boards($includeArchived)),
        ]);
    }

    /**
     * One board, its lists, and — unless asked otherwise — the cards on each.
     *
     * Three contract calls behind one request, the same shape the assistant's
     * `read_board` tool takes and for the same reason: "what is on this board"
     * is one question, and answering it in four round trips makes every client
     * reimplement the loop.
     *
     * Archived lists are included here where the assistant tool filters them
     * out. The contract returns them on purpose so a settings page can show
     * them, and an API is closer to a settings page than to a summary: the
     * caller has `is_archived` on every row and can decide.
     */
    public function show(Request $request, string $board): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'include_cards' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::validationFailed($validator);
        }

        $found = $this->boards->findBoard($board);

        if ($found === null) {
            return ApiResponse::notFound("There is no board with the slug \"{$board}\".");
        }

        $includeCards = ! $request->has('include_cards') || $request->boolean('include_cards');

        $lists = [];

        foreach ($this->boards->listsForBoard($board) as $list) {
            if ($includeCards) {
                $list['cards'] = $this->boards->cardsForList($list['id']);
            }

            $lists[] = $list;
        }

        return response()->json([
            'data' => new BoardResource($found + ['lists' => $lists]),
        ]);
    }

    public function lists(string $board): JsonResponse
    {
        if ($this->boards->findBoard($board) === null) {
            return ApiResponse::notFound("There is no board with the slug \"{$board}\".");
        }

        return response()->json([
            'data' => BoardResource::collection($this->boards->listsForBoard($board)),
        ]);
    }

    /**
     * The cards on one list, in the order the canvas draws them.
     *
     * An unknown list id answers `200` with an empty array rather than `404`.
     * The contract's `cardsForList()` cannot tell "no such list" from "an empty
     * list" — it returns `[]` for both — and manufacturing a 404 here would
     * mean asking Project for the list some other way, which means a second
     * contract method for the sole purpose of distinguishing two cases that
     * look identical to every caller anyway.
     */
    public function cards(int $list): JsonResponse
    {
        return response()->json([
            'data' => BoardResource::collection($this->boards->cardsForList($list)),
        ]);
    }
}
