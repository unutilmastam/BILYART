<?php

namespace App\Http\Middleware;

use App\Domain\Users\Models\User;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Client-admin routes: only tenant users (the Super Admin does not operate client businesses, spec §3). */
final class EnsureTenantUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->isSuperAdmin() || $user->tenant_id === null) {
            throw ApiException::of(ErrorCode::FORBIDDEN);
        }

        return $next($request);
    }
}
