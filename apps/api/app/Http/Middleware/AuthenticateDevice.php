<?php

namespace App\Http\Middleware;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Auth\CurrentPrincipal;
use App\Domain\Auth\Principal;
use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Devices\Models\Device;
use App\Domain\Tenancy\TenantContext;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `Authorization: Device <deviceCode>.<token>` (DEVICE_PROTOCOL.md §1).
 * Only the SHA-256 of the token is stored. Tenant/branch/table come from the
 * device row. Unknown/revoked → 401 REPAIR_REQUIRED (the device re-registers).
 */
final class AuthenticateDevice
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CurrentPrincipal $principal,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $header = (string) $request->header('Authorization', '');
        if (! preg_match('/^Device (ESP32-[0-9A-F]{6})\.([A-Za-z0-9_-]{32,128})$/', $header, $m)) {
            throw ApiException::of(ErrorCode::DEVICE_UNAUTHORIZED);
        }

        /** @var Device|null $device */
        $device = $this->context->runAsSystem(fn () => Device::query()->where('token_hash', hash('sha256', $m[2]))->first());
        if ($device === null || $device->device_code !== $m[1] || $device->status !== DeviceStatus::PAIRED || $device->tenant_id === null) {
            throw ApiException::of(ErrorCode::REPAIR_REQUIRED);
        }

        $request->attributes->set('device', $device);
        $this->principal->set(new Principal(ActorType::DEVICE, $device->id, $device->tenant_id));

        return $this->context->runAsTenant($device->tenant_id, fn () => $next($request));
    }
}
