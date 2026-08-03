<?php

namespace App\Http\Middleware;

use App\Support\TwoFactorChallenge;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The challenge page is only a page for somebody mid-sign-in.
 *
 * Reaching `/two-factor-challenge` with no pending challenge — a bookmark, a
 * back button, an expired wait — sends you to the login form rather than
 * showing a code box that could never accept anything.
 *
 * This guards the **page request**. It does not guard the Livewire round trips
 * that follow it: those go to `/livewire/update`, and Livewire only re-applies
 * an allowlist of framework middleware to them. The component checks the
 * challenge again inside every action it exposes, and that check — not this
 * one — is what actually stops a stale tab from verifying a code.
 */
class RequireTwoFactorChallenge
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! TwoFactorChallenge::isPending()) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
