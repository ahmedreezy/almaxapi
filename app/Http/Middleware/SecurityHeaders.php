<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds security headers to every response.
 *
 * These headers address multiple OWASP Top-10 risks:
 * - X-Frame-Options: prevents clickjacking
 * - X-Content-Type-Options: prevents MIME sniffing
 * - Referrer-Policy: limits referrer data leakage
 * - X-XSS-Protection: legacy browser XSS filter
 * - Permissions-Policy: disables unused browser APIs
 * - Content-Security-Policy: for API responses, restricts content
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // For API-only responses, a restrictive CSP prevents any accidental HTML
        if ($request->is('api/*')) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'");
        }

        // Remove headers that reveal server information
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        return $response;
    }
}
