<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Core\Contracts\CustomerReader;
use Modules\Mailbox\Contracts\EmailReader;
use Modules\Platform\Http\Resources\EmailResource;
use Modules\Platform\Support\ApiResponse;

/**
 * `GET /api/v1/customers/{customer}/emails` — `mailbox:read`.
 *
 * The only email endpoint this API has. `Modules\Mailbox\Contracts\EmailReader`
 * exposes exactly one read — a customer's messages, capped at a limit, newest
 * first — and no way to fetch a single message, list the inbox generally, or
 * send. There is no `/api/v1/emails` and no send endpoint because there is
 * nothing in the contract to build either on; see the report for what
 * `EmailReader` would need to grow.
 */
class EmailController
{
    public function __construct(
        private readonly CustomerReader $customers,
        private readonly EmailReader $emails,
    ) {}

    public function index(Request $request, int $customer): JsonResponse
    {
        if ($this->customers->find($customer) === null) {
            return ApiResponse::notFound("Customer {$customer} does not exist.");
        }

        $validator = Validator::make($request->query(), [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::validationFailed($validator);
        }

        $limit = (int) $request->query('limit', 20);

        return response()->json([
            'data' => EmailResource::collection($this->emails->forCustomer($customer, $limit)),
            'total' => $this->emails->countForCustomer($customer),
        ]);
    }
}
