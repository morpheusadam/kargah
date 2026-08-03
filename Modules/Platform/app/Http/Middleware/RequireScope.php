<?php

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every endpoint declares the scope it needs.
 *
 *     Route::get('v1/whoami', …)->middleware(['app-password', 'scope:core:read']);
 *
 * Separate from the authentication middleware on purpose. Authentication asks
 * *who*; this asks *what they may do*, and the two answers have different
 * status codes — 401 means "I do not know you", 403 means "I know you and the
 * answer is no". Collapsing them would make a client retry with the same
 * credential for ever.
 *
 * The refusal names the scope it wanted. A client that cannot be told what it
 * is missing has to guess, and a guessing client is one that asks for
 * everything next time.
 */
class RequireScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $credential = AuthenticateApplicationPassword::credential($request);

        if ($credential === null) {
            // Reached without going through the authenticator first. That is a
            // routing mistake, not a client mistake, but it must not be a way
            // past the check.
            return response()->json(
                ['message' => 'Unauthenticated. Use HTTP Basic auth with an application password.'],
                401,
                ['WWW-Authenticate' => 'Basic realm="Kargah"'],
            );
        }

        foreach ($scopes as $scope) {
            if (! $credential->hasScope($scope)) {
                return response()->json([
                    'message' => 'This application password does not carry the '.$scope.' scope.',
                    'required' => array_values($scopes),
                    'granted' => $credential->grantedScopes(),
                ], 403);
            }
        }

        return $next($request);
    }
}
