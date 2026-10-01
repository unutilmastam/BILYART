<?php

namespace App\Domain\Sessions\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Services\WorkingHoursCalendar;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Services\DeviceCommandBus;
use App\Domain\Sessions\Enums\SessionStatus;
use App\Domain\Sessions\Models\GameSession;
use App\Domain\Tables\Models\BilliardTable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Derives the display status of tables (protocol TableStatus) from
 * authoritative data at read time — never from a stored flag, so a missed
 * cron run cannot show a finished table as busy (ARCHITECTURE §2.2).
 */
final class TableStatusResolver
{
    public function __construct(private readonly WorkingHoursCalendar $calendar) {}

    /**
     * @param  Collection<int, BilliardTable>  $tables
     * @return array<int, array{status: string, session: ?GameSession, device: ?Device}> keyed by table id
     */
    public function resolve(Collection $tables, int $warnBeforeMinutes, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $ids = $tables->pluck('id')->all();
        $sessions = GameSession::query()->whereIn('table_id', $ids)
            ->whereIn('status', array_map(fn ($s) => $s->value, SessionStatus::occupying()))
            ->get()->filter(fn (GameSession $s) => self::stillOccupying($s, $now))->keyBy('table_id');
        $devices = Device::query()->whereIn('id', $tables->pluck('device_id')->filter()->unique()->all())->where('status', 'PAIRED')->get()->keyBy('id');
        $branchOpen = [];

        $out = [];
        foreach ($tables as $table) {
            $branch = $table->relationLoaded('branch') ? $table->branch : Branch::query()->find($table->branch_id);
            $branchOpen[$table->branch_id] ??= $branch !== null && $branch->is_active && $this->calendar->isOpenAt($branch, $now);
            /** @var GameSession|null $session */
            $session = $sessions->get($table->id);
            /** @var Device|null $device */
            $device = $table->device_id !== null && $table->device_channel !== null ? $devices->get($table->device_id) : null;

            $status = match (true) {
                ! $table->is_active => 'DISABLED',
                $session?->status === SessionStatus::RESERVED => 'RESERVED',
                $session?->status === SessionStatus::STARTING => 'STARTING',
                $session !== null && $session->end_at !== null => $session->end_at->subMinutes($warnBeforeMinutes)->lessThanOrEqualTo($now) ? 'WARNING' : 'BUSY',
                ! $branchOpen[$table->branch_id] => 'CLOSED',
                $device === null || ! $device->isOnline($now) => 'DEVICE_OFFLINE',
                default => 'AVAILABLE',
            };
            $out[$table->id] = ['status' => $status, 'session' => $session, 'device' => $device];
        }

        return $out;
    }

    /** Effective occupancy: ended/expired rows still waiting for the finalizer do not hold the table. */
    public static function stillOccupying(GameSession $s, CarbonImmutable $now): bool
    {
        return match ($s->status) {
            SessionStatus::RESERVED => $s->reserved_until !== null && $s->reserved_until->greaterThan($now),
            SessionStatus::STARTING => $s->start_at !== null && $s->start_at->greaterThan($now->subSeconds(DeviceCommandBus::START_ACK_TIMEOUT_SEC)),
            SessionStatus::ACTIVE => $s->end_at !== null && $s->end_at->greaterThan($now),
            SessionStatus::COMPLETING => false,
            default => false,
        };
    }
}
