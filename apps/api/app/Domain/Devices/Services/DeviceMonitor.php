<?php

namespace App\Domain\Devices\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Devices\Models\Device;
use App\Domain\Notifications\Enums\Severity;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Tables\Models\BilliardTable;
use Carbon\CarbonImmutable;

/**
 * Offline/online alerts (spec §40) based only on real heartbeats. A device is
 * alerted as offline after 60 s without a poll (short blips stay silent), once
 * per offline episode; "back online" is sent when it polls again.
 */
final class DeviceMonitor
{
    public const OFFLINE_ALERT_AFTER_SEC = 60;

    public function __construct(private readonly NotificationService $notifications) {}

    /** @return array{offline: int, online: int} */
    public function check(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $offline = 0;
        $online = 0;

        $gone = Device::query()->where('status', 'PAIRED')->whereNull('offline_since')
            ->where('last_seen_at', '<', $now->subSeconds(self::OFFLINE_ALERT_AFTER_SEC))->get();
        foreach ($gone as $device) {
            $device->forceFill(['offline_since' => $device->last_seen_at])->save();
            $this->notify($device, 'device_offline', Severity::WARNING);
            $offline++;
        }

        $back = Device::query()->where('status', 'PAIRED')->whereNotNull('offline_since')
            ->where('last_seen_at', '>=', $now->subSeconds((int) config('devices.online_timeout_sec', 15)))->get();
        foreach ($back as $device) {
            $this->notify($device, 'device_online', Severity::INFO);
            $device->forceFill(['offline_since' => null])->save();
            $online++;
        }

        return ['offline' => $offline, 'online' => $online];
    }

    private function notify(Device $device, string $type, Severity $severity): void
    {
        $table = BilliardTable::query()->find($device->table_id);
        $branch = Branch::query()->find($device->branch_id);
        $this->notifications->notify($device->tenant_id, $type, "{$type}:{$device->id}:".$device->offline_since?->getTimestamp(), [
            'text' => __("notifications.{$type}", ['device' => $device->device_code, 'table' => $table?->name ?? '—', 'branch' => $branch?->name ?? '—']),
            'branchId' => $device->branch_id,
            'deviceId' => $device->public_id,
        ], $severity);
    }
}
