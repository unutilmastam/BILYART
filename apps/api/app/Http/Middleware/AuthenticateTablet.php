<?php

namespace App\Http\Middleware;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Auth\CurrentPrincipal;
use App\Domain\Auth\Principal;
use App\Domain\Tablets\Enums\TabletStatus;
use App\Domain\Tablets\Models\Tablet;
use App\Domain\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Authorization: Bearer <token>` issued once at pairing. Only the SHA-256 is
 * stored. Tenant and branch come from the tablet row, never from input.
 */
final class AuthenticateTablet
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CurrentPrincipal $principal,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) $request->bearerToken();
        if (strlen($token) < 32 || strlen($token) > 128) {
            throw ApiException::of(ErrorCode::UNAUTHENTICATED);
        }

        /** @var Tablet|null $tablet */
        $tablet = $this->context->runAsSystem(
            fn () => Tablet::query()->where('token_hash', hash('sha256', $token))->first()
        );

        if ($tablet === null || $tablet->status !== TabletStatus::PAIRED || $tablet->tenant_id === null) {
            throw ApiException::of(ErrorCode::UNAUTHENTICATED);
        }

        $request->attributes->set('tablet', $tablet);
        $this->principal->set(new Principal(ActorType::TABLET, $tablet->id, $tablet->tenant_id));

        return $this->context->runAsTenant($tablet->tenant_id, fn () => $next($request));
    }
}
