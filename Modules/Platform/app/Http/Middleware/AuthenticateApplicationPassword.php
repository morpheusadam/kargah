<?php

namespace Modules\Platform\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Platform\Models\ApplicationPassword;
use Modules\Platform\Services\ApplicationPasswordAuthenticator;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP Basic auth against an application password.
 *
 *     curl -u nima@example.com:k7m2xq-4bnv8t-zr93wd-6ehjs1 https://…/api/v1/whoami
 *
 * Basic rather than a bearer token, and not Laravel Sanctum, because the
 * interface is the feature: one line in a shell, no header assembly, no token
 * exchange. `curl -u` working is the whole point.
 *
 * This is the only thing in Kargah reachable without a session, so three things
 * happen here that happen nowhere else:
 *
 * - **It is rate limited**, twice. A per-IP ceiling on requests, and a much
 *   tighter ceiling on *failures* per IP and address — the second is the one
 *   that matters, because it is what turns a guessing loop into a wait.
 * - **A failure is logged at `warning`**, with the address tried and the IP.
 *   Never the presented secret: a log file is a place secrets go to be
 *   forgotten about, and a mistyped real password is exactly what lands here.
 * - **A failure answers `401` with `WWW-Authenticate`**, so a client knows what
 *   kind of credential to offer rather than guessing.
 *
 * The limiter runs on the configured cache store, which is `database` in this
 * deployment — there is no Redis, and atomic locks and counters work on the
 * database driver without one.
 */
class AuthenticateApplicationPassword
{
    /** Where the resolved credential is parked for RequireScope and the controller. */
    public const ATTRIBUTE = 'platform.application_password';

    /** Failures per minute, per IP and address, before the door closes. */
    private const MAX_FAILURES = 10;

    /** Requests per minute per IP, valid or not. */
    private const MAX_REQUESTS = 60;

    private const DECAY = 60;

    public function __construct(private readonly ApplicationPasswordAuthenticator $authenticator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $email = (string) $request->getUser();
        $ip = (string) $request->ip();

        $requestKey = 'platform:app-password:ip:'.sha1($ip);
        $failureKey = 'platform:app-password:fail:'.sha1($ip.'|'.mb_strtolower($email));

        foreach ([[$requestKey, self::MAX_REQUESTS], [$failureKey, self::MAX_FAILURES]] as [$key, $limit]) {
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                $this->report($request, $email, 'rate limited');

                return $this->refused(
                    'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
                    429,
                    ['Retry-After' => (string) RateLimiter::availableIn($key)],
                );
            }
        }

        RateLimiter::hit($requestKey, self::DECAY);

        $credential = $this->authenticator->attempt($email, (string) $request->getPassword());

        if ($credential === null) {
            RateLimiter::hit($failureKey, self::DECAY);
            $this->report($request, $email, 'no usable application password matched');

            return $this->unauthorised();
        }

        $user = $credential->user;

        if ($user === null) {
            // The owning row is gone but the cascade did not fire — a database
            // in a state nobody designed. Refuse rather than guess.
            $this->report($request, $email, 'the credential has no owner');

            return $this->unauthorised();
        }

        RateLimiter::clear($failureKey);

        $request->setUserResolver(fn () => $user);
        $request->attributes->set(self::ATTRIBUTE, $credential);

        $this->authenticator->recordUse($credential, $ip);

        return $next($request);
    }

    /**
     * The credential this request authenticated with, if it did.
     *
     * One accessor rather than the string literal in three files, so renaming
     * the attribute is a one-line change instead of a grep.
     */
    public static function credential(Request $request): ?ApplicationPassword
    {
        $credential = $request->attributes->get(self::ATTRIBUTE);

        return $credential instanceof ApplicationPassword ? $credential : null;
    }

    private function unauthorised(): Response
    {
        return $this->refused(
            'Unauthenticated. Use HTTP Basic auth: your Kargah email address, and an application password as the password.',
            401,
            ['WWW-Authenticate' => 'Basic realm="Kargah"'],
        );
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function refused(string $message, int $status, array $headers = []): Response
    {
        return response()->json(['message' => $message], $status, $headers);
    }

    private function report(Request $request, string $email, string $reason): void
    {
        Log::warning('Application password authentication failed.', [
            // The address tried and where from. Never the presented secret —
            // a failed attempt is very often somebody's real password typed
            // into the wrong box, and a log file keeps it for ever.
            'email' => $email === '' ? '(none presented)' : $email,
            'ip' => $request->ip(),
            'path' => $request->path(),
            'reason' => $reason,
        ]);
    }
}
