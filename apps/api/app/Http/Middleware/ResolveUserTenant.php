<?php

namespace App\Http\Middleware;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Auth\CurrentPrincipal;
use App\Domain\Auth\Principal;
use App\Domain\Tenancy\Enums\TenantStatusFlag;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Central step of the pipeline (ARCHITECTURE §4): after `auth`, derive the
 * tenant from the authenticated user — never from the request — and set the
 * TenantContext (SUPER_ADMIN → SYSTEM). Runs before route-model binding so
 * bound models are already tenant-scoped (foreign ids → 404).
 */
final class ResolveUserTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CurrentPrincipal $principal,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            throw ApiException::of(ErrorCode::UNAUTHENTICATED);
        }

        if (! $user->is_active || ! $this->tenantAllowsLogin($user)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            throw ApiException::of(ErrorCode::ACCOUNT_DISABLED);
        }

        $this->principal->set(new Principal(ActorType::USER, $user->id, $user->tenant_id));

        $run = fn () => $next($request);

        return $user->isSuperAdmin()
            ? $this->context->runAsSystem($run)
            : $this->context->runAsTenant((int) $user->tenant_id, $run);
    }

    private function tenantAllowsLogin(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        $flag = Tenant::query()->whereKey($user->tenant_id)->toBase()->value('status_flag');

        return $flag !== null && $flag !== TenantStatusFlag::DEACTIVATED->value;
    }
}
