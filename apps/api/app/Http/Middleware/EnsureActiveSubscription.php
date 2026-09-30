<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** spec §29: business routes require ACTIVE or EXPIRING_SOON. Data is never deleted on expiry. */
final class EnsureActiveSubscription
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Tenant::query()->find($this->context->requireTenantId());
        if ($tenant === null || ! $tenant->hasActiveSubscription()) {
            throw ApiException::of(ErrorCode::SUBSCRIPTION_INACTIVE);
        }

        return $next($request);
    }
}
