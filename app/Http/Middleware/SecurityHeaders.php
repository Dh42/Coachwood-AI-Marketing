<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser protections for the platform's own pages: never framed by another
 * site, no guessing at file types, and HTTPS only once seen over HTTPS.
 * Only on the web group: the chat widget's API, the claim form and the store's
 * app proxy are called from the store and keep their own headers.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Frame-Options', 'DENY', false);
        $headers->set('Content-Security-Policy', "frame-ancestors 'none'", false);
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);

        if (($seconds = (int) config('security.hsts_seconds')) > 0 && ($request->isSecure() || str_starts_with((string) config('app.url'), 'https://'))) {
            $headers->set('Strict-Transport-Security', "max-age={$seconds}; includeSubDomains", false);
        }

        return $response;
    }
}
