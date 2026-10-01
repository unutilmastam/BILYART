<?php

namespace App\Domain\Devices\Services;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
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
 * tenant/branch until explicitly unpaired (spec §17). Tables are wired to its
 * relay channels by the admin. tenant/branch/table sent by a device are never
 * trusted — they come only from the pairing and the wiring.
 */
final class DeviceRegistry
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LimitGuard $limits,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array{deviceCode: string, pairingCode: string, pairingExpiresAt: int, pollToken: string, serverTime: int} */
    public function register(string $hardwareId, string $firmwareVersion, string $ip, int $channelCount = 1): array
    {
        $hardwareId = strtoupper($hardwareId);

        return $this->context->runAsSystem(fn () => DB::transaction(function () use ($hardwareId, $firmwareVersion, $ip, $channelCount): array {
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
            $device->forceFill(['firmware_version' => $firmwareVersion, 'channel_count' => $channelCount, 'last_ip' => $ip, 'last_seen_at' => now()])->save();

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

    /** Client admin enters the code shown by the device portal and chooses the branch (spec §17). Tables are wired to channels afterwards. */
    public function pair(User $admin, string $code, Branch $branch): Device
    {
        if (! $branch->is_active) {
            throw ApiException::of(ErrorCode::VALIDATION_FAILED);
        }
        $tenantId = $this->context->requireTenantId();

        return $this->limits->within(LimitGuard::DEVICES, function () use ($admin, $code, $branch, $tenantId): Device {
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

            $this->context->runAsSystem(function () use ($device, $pairing, $branch, $admin, $tenantId): void {
                $device->forceFill([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branch->id,
                    'status' => DeviceStatus::PAIRED,
                    'paired_at' => now(),
                    'paired_by' => $admin->id,
                ])->save();
                $pairing->forceFill(['used_at' => now(), 'used_by' => $admin->id, 'tenant_id' => $tenantId])->save();
            });
            $this->audit->log('device.paired', $device, ['code' => $device->device_code, 'branch' => $branch->name, 'channels' => $device->channel_count]);

            return $device;
        });
    }

    /** Moves a paired device to another branch of the same tenant; its channels must be free first. */
    public function moveToBranch(Device $device, Branch $branch): Device
    {
        return DB::transaction(function () use ($device, $branch): Device {
            if (BilliardTable::query()->where('device_id', $device->id)->exists()) {
                throw ApiException::of(ErrorCode::CONFLICT); // unwire its tables first
            }
            $from = $device->branch_id;
            $device->forceFill(['branch_id' => $branch->id])->save();
            $this->audit->log('device.moved', $device, ['fromBranch' => $from, 'toBranch' => $branch->id]);

            return $device;
        });
    }

    /**
     * Wires a table's lamp to a relay channel of a device in the same branch (null = unwire).
     * One table per channel (DB unique); never while the table has a running session.
     */
    public function assignChannel(BilliardTable $table, ?Device $device, ?int $channel): BilliardTable
    {
        return DB::transaction(function () use ($table, $device, $channel): BilliardTable {
            /** @var BilliardTable $locked */
            $locked = BilliardTable::query()->lockForUpdate()->findOrFail($table->id);
            if ($locked->device_id === $device?->id && $locked->device_channel === ($device ? $channel : null)) {
                return $locked;
            }
            $this->assertNoRunningSession([$locked->id]);
            if ($device !== null) {
                if ($device->status !== DeviceStatus::PAIRED || $device->branch_id !== $locked->branch_id) {
                    throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['deviceId' => [__('validation.exists', ['attribute' => 'device'])]]]);
                }
                if ($channel === null || $channel < 1 || $channel > $device->channel_count) {
                    throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['deviceChannel' => [__('validation.between.numeric', ['attribute' => 'channel', 'min' => 1, 'max' => $device->channel_count])]]]);
                }
                $taken = BilliardTable::query()->where('device_id', $device->id)->where('device_channel', $channel)->whereKeyNot($locked->id)->exists();
                if ($taken) {
                    throw ApiException::of(ErrorCode::CONFLICT); // that relay already drives another table
                }
            }
            $from = ['device' => $locked->device_id, 'channel' => $locked->device_channel];
            $locked->forceFill(['device_id' => $device?->id, 'device_channel' => $device ? $channel : null])->save();
            $this->audit->log('table.device_wired', $locked, ['from' => $from, 'to' => ['device' => $device?->device_code, 'channel' => $device ? $channel : null]]);

            return $locked;
        });
    }

    /** Unpair = revoke the registration: token invalid, tables unwired, hardware id released (re-pairing needs the portal). */
    public function unpair(User $admin, Device $device): Device
    {
        return DB::transaction(function () use ($admin, $device): Device {
            $tableIds = BilliardTable::query()->where('device_id', $device->id)->pluck('id')->all();
            $this->assertNoRunningSession($tableIds);
            BilliardTable::query()->whereIn('id', $tableIds)->update(['device_id' => null, 'device_channel' => null]);
            $device->forceFill([
                'status' => DeviceStatus::REVOKED,
                'active_hardware_id' => null,
                'token_hash' => null,
                'revoked_at' => now(),
                'revoked_by' => $admin->id,
            ])->save();
            $this->audit->log('device.unpaired', $device, ['code' => $device->device_code, 'unwiredTables' => count($tableIds)]);

            return $device;
        });
    }

    /** @param list<int> $tableIds */
    private function assertNoRunningSession(array $tableIds): void
    {
        if ($tableIds !== [] && GameSession::query()->whereIn('table_id', $tableIds)
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
