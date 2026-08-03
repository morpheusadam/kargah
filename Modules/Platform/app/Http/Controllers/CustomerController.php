<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Contracts\CustomerReader;
use Modules\Platform\Http\Resources\CustomerResource;
use Modules\Platform\Support\ApiResponse;

/**
 * `GET /api/v1/customers`, `GET /api/v1/customers/{customer}` — `core:read`.
 *
 * Reads through `Modules\Core\Contracts\CustomerReader` alone; nothing here
 * imports `Modules\Core\Models\Customer`. There is no `/api/v1/companies`:
 * Core exposes no `CompanyReader`, and `07-platform.md` names companies as
 * part of this surface without one existing to read them through — see the
 * report rather than a route that reaches into `Modules\Core\Models\Company`.
 *
 * Not cursor-paginated. `CustomerReader::search()` and `::forCompany()` return
 * a bounded `Collection`, not a paginator — there is no cursor to carry,
 * because the contract was built for a select box, not a listing endpoint.
 * See the report for what a `paginate()` method here would need.
 */
class CustomerController
{
    public function __construct(private readonly CustomerReader $customers) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'q' => ['sometimes', 'string', 'max:200'],
            'company_id' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::validationFailed($validator);
        }

        $companyId = $request->query('company_id');
        $limit = (int) $request->query('limit', 20);

        $results = $companyId !== null
            ? $this->customers->forCompany((int) $companyId)
            : $this->customers->search(trim((string) $request->query('q', '')), $limit);

        return response()->json(['data' => CustomerResource::collection($results)]);
    }

    public function show(int $customer): JsonResponse
    {
        $found = $this->customers->find($customer);

        if ($found === null) {
            return ApiResponse::notFound("Customer {$customer} does not exist.");
        }

        return response()->json(['data' => new CustomerResource($found)]);
    }
}
