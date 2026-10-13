<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * PHASE 1 — deactivated accounts lose their session on the next request.
 *
 * Login-time checks alone are not enough: an admin can deactivate an
 * account while that user is still browsing. This middleware sits in the
 * `auth` group (after Authenticate), so every authenticated route re-checks
 * `is_active` and, if the account is deactivated, logs the session out and
 * invalidates it. Reactivating the account simply restores access — no
 * role or record changes are involved.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Strict comparison: only an explicit false counts as deactivated.
        // (A freshly created model may not carry the DB-defaulted column
        // yet, which would cast to null — that must not log anyone out.)
        if ($user && $user->is_active === false) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('login')
                ->with('error', 'This account has been deactivated. Please contact the administrator.');
        }

        return $next($request);
    }
}
