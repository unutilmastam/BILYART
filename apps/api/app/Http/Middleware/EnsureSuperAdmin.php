<?php

namespace App\Http\Middleware;

use App\Domain\Users\Models\User;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isSuperAdmin()) {
            throw ApiException::of(ErrorCode::FORBIDDEN);
        }

        return $next($request);
    }
}
