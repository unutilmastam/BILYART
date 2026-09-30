<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Correlates logs, audit rows and error responses. A client-supplied id is accepted only if it is a safe short token. */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $given = (string) $request->headers->get('X-Request-Id', '');
        $id = preg_match('/^[A-Za-z0-9-]{8,36}$/', $given) ? $given : (string) Str::uuid();
        $request->attributes->set('request_id', $id);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
