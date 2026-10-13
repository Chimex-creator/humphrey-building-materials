<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Hardening headers (nosniff / clickjacking / referrer) on every response.
        $middleware->append(SecurityHeaders::class);

        // Register our role middleware so we can use ->middleware('role:admin')
        $middleware->alias([
            'role' => CheckRole::class,
        ]);

        // PHASE 1 — every authenticated request re-checks is_active, so a
        // deactivated account's existing session is terminated immediately.
        // NOTE: `auth` is a middleware *alias* in Laravel 11+, not a group —
        // appending to a group named `auth` would shadow the alias and drop
        // Authenticate. The `web` group is the correct hook (session started;
        // the middleware ignores guests and only logs out inactive users).
        $middleware->appendToGroup('web', EnsureUserIsActive::class);

        // Paystack calls this route from its own servers — it has no session
        // cookie, so the CSRF token check must be skipped for it only.
        // The route validates authenticity via the X-Paystack-Signature header.
        $middleware->validateCsrfTokens(except: [
            'paystack/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
