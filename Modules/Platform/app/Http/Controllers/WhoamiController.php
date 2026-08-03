<?php

namespace Modules\Platform\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Platform\Http\Middleware\AuthenticateApplicationPassword;

/**
 * `GET /api/v1/whoami` — what this credential is and what it may do.
 *
 * The first endpoint of the API and, for now, the only one. It exists so a
 * client can find out its own powers instead of discovering them by being
 * refused, and it doubles as the check a person runs after issuing a credential
 * to confirm the thing works at all:
 *
 *     curl -u nima@example.com:k7m2xq-4bnv8t-zr93wd-6ehjs1 https://…/api/v1/whoami
 *
 * Nothing here is a secret. The prefix is the identifiable half of the
 * credential and is already printed on the settings page; the hash is not in
 * this response and could not be turned back into anything if it were.
 */
class WhoamiController
{
    public function __invoke(Request $request): JsonResponse
    {
        $credential = AuthenticateApplicationPassword::credential($request);
        $user = $request->user();

        // Both are guaranteed by the middleware; the guard is here so a routing
        // mistake produces a 401 rather than a 500 on a null.
        if ($credential === null || $user === null) {
            return response()->json(
                ['message' => 'Unauthenticated. Use HTTP Basic auth with an application password.'],
                401,
                ['WWW-Authenticate' => 'Basic realm="Kargah"'],
            );
        }

        return response()->json([
            'user' => [
                'name' => $user->name,
                'email' => $user->email,
            ],
            'token' => [
                'name' => $credential->name,
                'prefix' => $credential->prefix,
                'created_at' => $credential->created_at?->toIso8601String(),
                'expires_at' => $credential->expires_at?->toIso8601String(),
                'last_used_at' => $credential->last_used_at?->toIso8601String(),
            ],
            // Also at the top level, because "what may I do" is the question
            // this endpoint exists to answer and a client should not have to
            // walk into an object to find it.
            'scopes' => $credential->grantedScopes(),
        ]);
    }
}
