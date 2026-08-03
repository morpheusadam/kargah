<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\Accounting\Contracts\InvoiceReader;
use Modules\Platform\Http\Resources\InvoiceResource;
use Modules\Platform\Support\ApiResponse;

/**
 * `GET /api/v1/invoices`, `GET /api/v1/invoices/{invoice}` — `accounting:read`.
 * `POST /api/v1/invoices/{invoice}/issue` — `accounting:write`.
 *
 * Issuing is a separate endpoint and a separate scope on purpose: read is
 * read, and issuing freezes an exchange rate onto a legal document. A
 * `PATCH /invoices/{id}` that happened to include `status: sent` would let a
 * read-scoped client trigger it by accident; a distinct verb and a distinct
 * scope cannot be reached that way.
 */
class InvoiceController
{
    private const STATUSES = ['draft', 'sent', 'paid', 'overdue'];

    public function __construct(private readonly InvoiceReader $invoices) {}

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'status' => ['sometimes', 'string', 'in:'.implode(',', self::STATUSES)],
            'q' => ['sometimes', 'string', 'max:200'],
            'cursor' => ['sometimes', 'string'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::validationFailed($validator);
        }

        $page = $this->invoices->paginate(
            $request->query('status'),
            trim((string) $request->query('q', '')),
            $request->query('cursor'),
            (int) $request->query('per_page', 20),
        );

        return ApiResponse::page($page, InvoiceResource::class);
    }

    public function show(int $invoice): JsonResponse
    {
        $found = $this->invoices->find($invoice);

        if ($found === null) {
            return ApiResponse::notFound("Invoice {$invoice} does not exist.");
        }

        return response()->json(['data' => new InvoiceResource($found)]);
    }

    public function issue(Request $request, int $invoice): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            // Not validated against Accounting's known currency list — Platform
            // does not own that list, and never rejecting a currency Accounting
            // itself would refuse to invent a rule Accounting has not stated.
            'reporting_currency' => ['sometimes', 'string', 'regex:/^[A-Za-z]{3,5}$/'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::validationFailed($validator);
        }

        $reportingCurrency = strtoupper((string) $request->input('reporting_currency', 'USD'));

        $result = $this->invoices->issue($invoice, $reportingCurrency);

        if ($result === null) {
            return ApiResponse::notFound("Invoice {$invoice} does not exist.");
        }

        return response()->json(['data' => new InvoiceResource($result)]);
    }
}
