<?php

namespace App\Domain\Devices\Services;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DevicePairing;
use App\Domain\Pairing\PairingCodes;
use App\Domain\Sessions\Enums\SessionStatus;
use App\Domain\Sessions\Models\GameSession;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tenancy\Services\LimitGuard;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Device registration and pairing (DEVICE_PROTOCOL.md §2). A device belongs to
 * nobody until a client admin enters its code; it then stays bound to that
 * tenant/table until explicitly unpaired (spec §17). tenant/branch/table sent
 * by a device are never trusted — they come only from the pairing.
 */
final class DeviceRegistry
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitGuard $limits,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{deviceCode: string, pairingCode: string, pairingExpiresAt: int, pollToken: string, serverTime: int} */
    public function register(string $hardwareId, string $firmwareVersion, string $ip): array
    {
        $hardwareId = strtoupper($hardwareId);

        return $this->context->runAsSystem(fn () => DB::transaction(function () use ($hardwareId, $firmwareVersion, $ip): array {
            /** @var Device|null $device */
            $device = Device::query()->where('active_hardware_id', $hardwareId)->lockForUpdate()->first();
            if ($device?->status === DeviceStatus::PAIRED) {
                throw ApiException::of(ErrorCode::DEVICE_ALREADY_PAIRED); // unpair in admin first (physical + admin action)
            }
            if ($device === null) {
                $device = new Device(['firmware_version' => $firmwareVersion]);
                $device->forceFill([
                    'hardware_id' => $hardwareId,
                    'active_hardware_id' => $hardwareId,
                    'device_code' => Device::codeFor($hardwareId),
                    'status' => DeviceStatus::UNPAIRED,
                    'registered_at' => now(),
                ]);
            }
            $device->forceFill(['firmware_version' => $firmwareVersion, 'last_ip' => $ip, 'last_seen_at' => now()])->save();

            DevicePairing::query()->where('device_id', $device->id)->whereNull('used_at')->update(['expires_at' => now()]);
            $code = PairingCodes::fresh();
            $pollToken = PairingCodes::secret();
            $pairing = new DevicePairing;
            $pairing->forceFill([
                'device_id' => $device->id,
                'code_hash' => $code['hash'],
                'poll_token_hash' => hash('sha256', $pollToken),
                'expires_at' => now()->addSeconds(PairingCodes::TTL_SEC),
            ])->save();

            return [
                'deviceCode' => $device->device_code,
                'pairingCode' => $code['code'],
                'pairingExpiresAt' => $pairing->expires_at->getTimestamp(),
                'pollToken' => $pollToken,
                'serverTime' => now()->getTimestamp(),
            ];
        }));
    }

    /** Device-side handshake: returns the device token exactly once after an admin paired it. */
    public function pairingStatus(string $pollToken): array
    {
        return $this->context->runAsSystem(fn () => DB::transaction(function () use ($pollToken): array {
            /** @var DevicePairing|null $pairing */
            $pairing = DevicePairing::query()->where('poll_token_hash', hash('sha256', $pollToken))->lockForUpdate()->first();
            if ($pairing === null) {
                throw ApiException::of(ErrorCode::DEVICE_UNAUTHORIZED);
            }
            $out = ['serverTime' => now()->getTimestamp()];
            if ($pairing->used_at === null) {
                return $out + ['status' => $pairing->expires_at->isPast() ? 'EXPIRED' : 'WAITING'];
            }
            if ($pairing->token_delivered_at !== null) {
                return $out + ['status' => 'PAIRED'];
            }
            $token = PairingCodes::secret();
            Device::query()->whereKey($pairing->device_id)->update(['token_hash' => hash('sha256', $token)]);
            $pairing->forceFill(['token_delivered_at' => now()])->save();

            return $out + ['status' => 'PAIRED', 'token' => $token];
        }));
    }

    /** Client admin enters the code shown by the device portal and chooses a table (spec §17). */
    public function pair(User $admin, string $code, BilliardTable $table): Device
    {
        if (! $table->is_active) {
            throw ApiException::of(ErrorCode::TABLE_DISABLED);
        }
        $tenantId = $this->context->requireTenantId();

        return $this->limits->within(LimitGuard::DEVICES, function () use ($admin, $code, $table, $tenantId): Device {
            $pairing = $this->context->runAsSystem(fn () => DevicePairing::query()
                ->where('code_hash', PairingCodes::hash($code))->whereNull('used_at')->lockForUpdate()->first());
            if ($pairing === null) {
                throw ApiException::of(ErrorCode::PAIRING_CODE_INVALID);
            }
            if ($pairing->expires_at->isPast()) {
                throw ApiException::of(ErrorCode::PAIRING_CODE_EXPIRED);
            }
            /** @var Device $device */
            $device = $this->context->runAsSystem(fn () => Device::query()->lockForUpdate()->findOrFail($pairing->device_id));
            if ($device->status !== DeviceStatus::UNPAIRED) {
                throw ApiException::of(ErrorCode::PAIRING_CODE_INVALID);
            }
            if (Device::query()->where('active_table_id', $table->id)->exists()) {
                throw ApiException::of(ErrorCode::CONFLICT); // one device per table; unpair the old one first
            }

            $this->context->runAsSystem(function () use ($device, $pairing, $table, $admin, $tenantId): void {
                $device->forceFill([
                    'tenant_id' => $tenantId,
                    'branch_id' => $table->branch_id,
                    'table_id' => $table->id,
                    'active_table_id' => $table->id,
                    'status' => DeviceStatus::PAIRED,
                    'paired_at' => now(),
                    'paired_by' => $admin->id,
                ])->save();
                $pairing->forceFill(['used_at' => now(), 'used_by' => $admin->id, 'tenant_id' => $tenantId])->save();
            });
            $this->audit->log('device.paired', $device, ['code' => $device->device_code, 'table' => $table->number]);

            return $device;
        });
    }

    /** Moves a paired device to another table of the same tenant (no running session on either). */
    public function moveToTable(Device $device, BilliardTable $table): Device
    {
        return DB::transaction(function () use ($device, $table): Device {
            $this->assertNoRunningSession($device->table_id);
            $this->assertNoRunningSession($table->id);
            if (Device::query()->where('active_table_id', $table->id)->whereKeyNot($device->id)->exists()) {
                throw ApiException::of(ErrorCode::CONFLICT);
            }
            $from = $device->table_id;
            $device->forceFill(['branch_id' => $table->branch_id, 'table_id' => $table->id, 'active_table_id' => $table->id])->save();
            $this->audit->log('device.moved', $device, ['fromTable' => $from, 'toTable' => $table->id]);

            return $device;
        });
    }

    /** Unpair = revoke the registration: token invalid, table and hardware id released (re-pairing needs the portal). */
    public function unpair(User $admin, Device $device): Device
    {
        return DB::transaction(function () use ($admin, $device): Device {
            $this->assertNoRunningSession($device->table_id);
            $device->forceFill([
                'status' => DeviceStatus::REVOKED,
                'active_table_id' => null,
                'active_hardware_id' => null,
                'token_hash' => null,
                'revoked_at' => now(),
                'revoked_by' => $admin->id,
            ])->save();
            $this->audit->log('device.unpaired', $device, ['code' => $device->device_code]);

            return $device;
        });
    }

    private function assertNoRunningSession(?int $tableId): void
    {
        if ($tableId !== null && GameSession::query()->where('table_id', $tableId)
            ->whereIn('status', [SessionStatus::STARTING->value, SessionStatus::ACTIVE->value, SessionStatus::COMPLETING->value])
            ->where(fn ($q) => $q->whereNull('end_at')->orWhere('end_at', '>', now()))->exists()) {
            throw ApiException::of(ErrorCode::CONFLICT);
        }
    }

    public static function auditActor(Device $device): array
    {
        return ['tenant_id' => $device->tenant_id, 'actor_type' => ActorType::DEVICE, 'actor_id' => $device->id];
    }
}
