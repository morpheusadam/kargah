<?php

namespace Modules\Platform\Support;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\JsonResponse;

/**
 * One error shape for every failure the API can produce.
 *
 * `{"message": "…", "code": "machine_readable_token", …}` — always both, so a
 * 422 from validation and a 404 from a missing row differ only in `code` and
 * `errors`, never in the skeleton a client has to branch on.
 *
 * **Every layer answers in this shape, including the middleware.**
 * `AuthenticateApplicationPassword` and `RequireScope` refuse before a
 * controller ever runs, and they were briefly the exception — their 401, 403
 * and 429 bodies carried `message` and no `code`. A client should branch on one
 * envelope, not on which layer happened to refuse it, so they route through
 * here too: `unauthenticated`, `insufficient_scope`, `rate_limited`.
 * `ApiTest::test_every_failure_answers_in_the_same_envelope` walks all five
 * statuses and asserts it, so the exception cannot come back quietly.
 *
 * A 403 never says which specific row was refused — `RequireScope` refuses
 * before a controller runs, so it cannot know a row exists at all, and that is
 * the correct amount to reveal: "you lack this scope", never "record 12 exists
 * but you may not see it".
 */
final class ApiResponse
{
    /** @param  array<string, mixed>  $extra */
    public static function error(int $status, string $code, string $message, array $extra = []): JsonResponse
    {
        return response()->json(array_merge(['message' => $message, 'code' => $code], $extra), $status);
    }

    public static function notFound(string $message): JsonResponse
    {
        return self::error(404, 'not_found', $message);
    }

    /** A 422 reads exactly like every other failure — `message` and `code` — plus the field errors. */
    public static function validationFailed(Validator $validator): JsonResponse
    {
        return self::error(
            422,
            'validation_failed',
            'The given data was invalid.',
            ['errors' => $validator->errors()->toArray()],
        );
    }

    /** @param  array{items: list<array<string, mixed>>, next_cursor: ?string, prev_cursor: ?string, per_page: int}  $page */
    public static function page(array $page, ?string $resourceClass = null): JsonResponse
    {
        $items = $resourceClass === null
            ? $page['items']
            : $resourceClass::collection($page['items']);

        return response()->json([
            'data' => $items,
            // In the payload, not only in a header — a client parsing the body
            // has everything it needs to ask for the next page.
            'cursor' => [
                'next' => $page['next_cursor'],
                'prev' => $page['prev_cursor'],
                'per_page' => $page['per_page'],
            ],
        ]);
    }
}
