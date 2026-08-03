<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Contracts\CompanyReader;
use Modules\Platform\Http\Resources\CompanyResource;
use Modules\Platform\Support\ApiResponse;

/**
 * `GET /api/v1/companies`, `GET /api/v1/companies/{company}` — `core:read`.
 *
 * `07-platform.md` named companies as part of this surface and there was no
 * contract to read them through, so the endpoint did not exist rather than
 * reaching into `Modules\Core\Models\Company`. `Modules\Core\Contracts\CompanyReader`
 * is that contract, written this session, and it returns arrays — not the
 * Eloquent model its sibling `CustomerReader` returns.
 *
 * Cursor-paginated, which `/api/v1/customers` is not. That is not an
 * inconsistency anybody chose: `CustomerReader::search()` returns a bounded
 * `Collection` because it was built for a select box, and there is no cursor to
 * carry. A contract written for a listing endpoint carries one.
 */
class CompanyController
{
    public function __construct(private readonly CompanyReader $companies) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'q' => ['sometimes', 'string', 'max:200'],
            'archived' => ['sometimes', 'boolean'],
            'cursor' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::validationFailed($validator);
        }

        // Absent means "both", which is not the same as `false`. `has()` rather
        // than `boolean()` alone, or an unfiltered listing would silently
        // become the active-only one.
        $archived = $request->has('archived') ? $request->boolean('archived') : null;

        $page = $this->companies->paginate(
            trim((string) $request->query('q', '')),
            $archived,
            $request->query('cursor'),
            (int) $request->query('per_page', 20),
        );

        return ApiResponse::page($page, CompanyResource::class);
    }

    public function show(int $company): JsonResponse
    {
        $found = $this->companies->find($company);

        if ($found === null) {
            return ApiResponse::notFound("Company {$company} does not exist.");
        }

        return response()->json(['data' => new CompanyResource($found)]);
    }
}
