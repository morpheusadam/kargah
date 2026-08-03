<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Accounting\Contracts\ExpenseReader;
use Modules\Platform\Http\Resources\ExpenseResource;
use Modules\Platform\Support\ApiResponse;

/** `GET /api/v1/expenses`, `GET /api/v1/expenses/{expense}` — `accounting:read`. Read only; there is no issue-style action for an expense. */
class ExpenseController
{
    public function __construct(private readonly ExpenseReader $expenses) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'q' => ['sometimes', 'string', 'max:200'],
            'billable' => ['sometimes', 'boolean'],
            'cursor' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::validationFailed($validator);
        }

        $billable = $request->has('billable') ? $request->boolean('billable') : null;

        $page = $this->expenses->paginate(
            trim((string) $request->query('q', '')),
            $billable,
            $request->query('cursor'),
            (int) $request->query('per_page', 20),
        );

        return ApiResponse::page($page, ExpenseResource::class);
    }

    public function show(int $expense): JsonResponse
    {
        $found = $this->expenses->find($expense);

        if ($found === null) {
            return ApiResponse::notFound("Expense {$expense} does not exist.");
        }

        return response()->json(['data' => new ExpenseResource($found)]);
    }
}
