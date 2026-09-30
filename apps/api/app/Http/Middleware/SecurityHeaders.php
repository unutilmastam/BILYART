<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** docs/SECURITY.md §9. Controllers serving HTML set their own CSP; everything else gets the locked-down one. */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        if (! $headers->has('Permissions-Policy')) {
            $headers->set('Permissions-Policy', config('security.permissions_policy'));
        }
        if (! $headers->has('Content-Security-Policy')) {
            $headers->set('Content-Security-Policy', config('security.csp.api'));
        }
        if (! $headers->has('Cache-Control') || $request->is('api/*', 'device/*')) {
            $headers->set('Cache-Control', 'no-store, private');
        }
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', config('security.hsts'));
        }
        $headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By'); // added by PHP itself when expose_php=On
        }

        return $response;
    }
}
