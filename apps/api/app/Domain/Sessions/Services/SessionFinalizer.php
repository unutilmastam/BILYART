<?php

namespace App\Domain\Sessions\Services;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Devices\Services\DeviceCommandBus;
use App\Domain\Sessions\Enums\SessionStatus;
use App\Domain\Sessions\Models\GameSession;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Time-driven transitions (ARCHITECTURE §2.2). Runs every minute from the
 * scheduler for all tenants, and for one table inside the session-start lock,
 * so correctness never depends on cron punctuality: reads also derive the
 * effective status from end_at.
 */
final class SessionFinalizer
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly TenantSettings $settings,
    ) {}

    /** @return array{completed: int, expired: int, failed: int, stopTimeouts: int, warned: int} */
    public function finalizeDue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

        return [
            'completed' => $this->each(GameSession::query()->where('status', SessionStatus::ACTIVE->value)->where('end_at', '<=', $now),
                fn (GameSession $s) => $this->sessions->complete($s, ActorType::SYSTEM, null, 'END_REACHED')),
            'expired' => $this->each(GameSession::query()->where('status', SessionStatus::RESERVED->value)->where('reserved_until', '<=', $now),
                fn (GameSession $s) => $this->expireReservation($s)),
            'failed' => $this->each(GameSession::query()->where('status', SessionStatus::STARTING->value)->where('start_at', '<=', $now->subSeconds(DeviceCommandBus::START_ACK_TIMEOUT_SEC)),
                fn (GameSession $s) => $this->sessions->failStart($s, 'DEVICE_NO_ACK')),
            'stopTimeouts' => $this->each(GameSession::query()->where('status', SessionStatus::COMPLETING->value)->where('ended_at', '<=', $now->subSeconds(SessionService::COMPLETING_TIMEOUT_SEC)),
                fn (GameSession $s) => $this->sessions->complete($s, ActorType::SYSTEM, null, 'STOP_ACK_TIMEOUT')),
            'warned' => $this->markWarnings($now),
        ];
    }

    /** Called inside the table lock before a new reservation. */
    public function finalizeTable(int $tableId, CarbonImmutable $now): void
    {
        $base = fn (): Builder => GameSession::query()->where('table_id', $tableId);
        $this->each($base()->where('status', SessionStatus::ACTIVE->value)->where('end_at', '<=', $now),
            fn (GameSession $s) => $this->sessions->complete($s, ActorType::SYSTEM, null, 'END_REACHED'));
        $this->each($base()->where('status', SessionStatus::RESERVED->value)->where('reserved_until', '<=', $now),
            fn (GameSession $s) => $this->expireReservation($s));
        $this->each($base()->where('status', SessionStatus::STARTING->value)->where('start_at', '<=', $now->subSeconds(DeviceCommandBus::START_ACK_TIMEOUT_SEC)),
            fn (GameSession $s) => $this->sessions->failStart($s, 'DEVICE_NO_ACK'));
        $this->each($base()->where('status', SessionStatus::COMPLETING->value)->where('ended_at', '<=', $now->subSeconds(SessionService::COMPLETING_TIMEOUT_SEC)),
            fn (GameSession $s) => $this->sessions->complete($s, ActorType::SYSTEM, null, 'STOP_ACK_TIMEOUT'));
    }

    private function expireReservation(GameSession $session): void
    {
        DB::transaction(function () use ($session): void {
            $locked = GameSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status === SessionStatus::RESERVED) {
                $this->sessions->transition($locked, SessionStatus::CANCELLED, ActorType::SYSTEM, null, 'RESERVATION_EXPIRED');
            }
        });
    }

    /** Server-side record of the 5-minute warning (the physical warning is local on ESP32 + tablet). */
    private function markWarnings(CarbonImmutable $now): int
    {
        $count = 0;
        $candidates = GameSession::query()->where('status', SessionStatus::ACTIVE->value)->whereNull('warned_at')
            ->where('end_at', '>', $now)->where('end_at', '<=', $now->addMinutes(30))->get();
        $minutes = [];
        foreach ($candidates as $session) {
            $minutes[$session->tenant_id] ??= (int) $this->settings->for(Tenant::query()->findOrFail($session->tenant_id))['warn_before_minutes'];
            if ($session->end_at->subMinutes($minutes[$session->tenant_id])->lessThanOrEqualTo($now)) {
                $session->forceFill(['warned_at' => $now])->save();
                $count++;
            }
        }

        return $count;
    }

    private function each(Builder $query, \Closure $callback): int
    {
        $count = 0;
        foreach ($query->orderBy('id')->limit(500)->get() as $session) {
            $callback($session);
            $count++;
        }

        return $count;
    }
}
