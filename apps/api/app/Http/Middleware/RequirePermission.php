<?php

namespace App\Http\Middleware;

use App\Domain\Auth\Permissions;
use App\Domain\Users\Models\User;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** `perm:<permission>` — checks config/permissions.php for the user's role. */
final class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        foreach ($permissions as $permission) {
            if (! $user instanceof User || ! Permissions::roleHas($user->role, $permission)) {
                throw ApiException::of(ErrorCode::FORBIDDEN);
            }
        }

        return $next($request);
    }
}
