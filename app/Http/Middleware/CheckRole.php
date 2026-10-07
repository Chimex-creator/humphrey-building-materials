<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CheckRole middleware
 *
 * Blocks a logged-in user from a route unless their role is allowed.
 *
 * Usage in routes:
 *   Route::get('/admin', ...)->middleware('role:admin');
 *   Route::get('/inventory', ...)->middleware('role:admin,inventory');
 *   Route::get('/sales', ...)->middleware('role:admin,sales');
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        // Not logged in → send to login page (Phase 2 will create it).
        if (! $user) {
            return redirect()->route('login');
        }

        // Logged in but role not in the allowed list → deny.
        if (! in_array($user->role, $roles, true)) {
            abort(403, 'You are not authorised to access this page.');
        }

        return $next($request);
    }
}
