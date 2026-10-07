<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser hardening headers on every response.
 *
 * - nosniff stops the browser from guessing a different content type
 *   (e.g. treating an uploaded file as a script).
 * - SAMEORIGIN stops other sites framing our pages (clickjacking),
 *   while still allowing our own pages to embed each other.
 * - strict-origin-when-cross-origin only sends the full URL when the
 *   destination is the same site, so referral leakage stays minimal.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        return $response;
    }
}
