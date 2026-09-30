<?php

namespace App\Domain\Sessions\Services;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Branches\Services\WorkingHoursCalendar;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Services\DeviceCommandBus;
use App\Domain\Notifications\Enums\Severity;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Pricing\Models\PricingPlan;
use App\Domain\Pricing\Services\PriceCalculator;
use App\Domain\Sessions\Enums\PaymentStatus;
use App\Domain\Sessions\Enums\SessionStatus;
use App\Domain\Sessions\Models\GameSession;
use App\Domain\Sessions\Models\SessionEvent;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tablets\Models\Tablet;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Services\TenantSettings;
use App\Domain\Users\Models\User;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The only way to change a session's state (ARCHITECTURE §4/§5). Backend
 * timestamps are authoritative (UTC). Double booking is prevented by a
 * transaction + row lock on the table + the DB unique guard (spec §32).
 */
final class SessionService
{
    /** A reservation (table chosen, photo being taken) holds the table this long. */
    public const RESERVATION_TTL_SEC = 120;

    /** A STOP that is not acknowledged within this time completes anyway (the device also stops locally at endAt). */
    public const COMPLETING_TIMEOUT_SEC = 60;

    public function __construct(
        private readonly PriceCalculator $prices,
        private readonly WorkingHoursCalendar $calendar,
        private readonly DeviceCommandBus $commands,
        private readonly TenantSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** Step 1–4 of the customer flow: reserve the table and fix the price. */
    public function prepare(Tablet $tablet, BilliardTable $table, int $minutes): GameSession
    {
        $now = CarbonImmutable::now();
        if ($table->branch_id !== $tablet->branch_id) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }
        /** @var Branch $branch */
        $branch = Branch::query()->findOrFail($table->branch_id);
        if (! $table->is_active || ! $branch->is_active) {
            throw ApiException::of(ErrorCode::TABLE_DISABLED);
        }
        if (! $this->calendar->isOpenAt($branch, $now)) {
            throw ApiException::of(ErrorCode::BRANCH_CLOSED);
        }
        $plan = $table->pricing_plan_id ? PricingPlan::query()->find($table->pricing_plan_id) : null;
        if ($plan === null || ! $plan->is_active) {
            throw ApiException::of(ErrorCode::PRICING_NOT_CONFIGURED);
        }
        if (! in_array($minutes, array_map('intval', $plan->allowed_durations ?? []), true)) {
            throw ApiException::of(ErrorCode::DURATION_NOT_ALLOWED);
        }
        $device = $this->onlineDeviceFor($table, $now);
        $amount = $this->prices->quote($plan, $minutes);

        try {
            return DB::transaction(function () use ($tablet, $table, $plan, $device, $minutes, $amount, $now): GameSession {
                BilliardTable::query()->whereKey($table->id)->lockForUpdate()->firstOrFail();
                app(SessionFinalizer::class)->finalizeTable($table->id, $now);

                if (GameSession::query()->where('table_id', $table->id)->whereIn('status', array_map(fn ($s) => $s->value, SessionStatus::occupying()))->exists()) {
                    throw ApiException::of(ErrorCode::TABLE_UNAVAILABLE);
                }

                $session = new GameSession;
                $session->forceFill([
                    'branch_id' => $table->branch_id,
                    'table_id' => $table->id,
                    'device_id' => $device->id,
                    'tablet_id' => $tablet->id,
                    'pricing_plan_id' => $plan->id,
                    'status' => SessionStatus::RESERVED,
                    'duration_minutes' => $minutes,
                    'reserved_until' => $now->addSeconds(self::RESERVATION_TTL_SEC),
                    'price_per_hour_snapshot' => $plan->price_per_hour,
                    'rounding_step_snapshot' => $plan->rounding_step,
                    'amount' => $amount,
                ])->save();
                $this->event($session, null, SessionStatus::RESERVED, ActorType::TABLET, $tablet->id);

                return $session;
            });
        } catch (UniqueConstraintViolationException) {
            // A parallel request won the race between our check and insert: the DB guard decided.
            throw ApiException::of(ErrorCode::TABLE_UNAVAILABLE);
        }
    }

    /** Step 9–10: create the session times and send START to the ESP32. */
    public function start(Tablet $tablet, GameSession $session): GameSession
    {
        $this->assertOwnedByTablet($tablet, $session);

        // An expired reservation is cancelled (committed) before the error is returned.
        $expired = DB::transaction(function () use ($session): bool {
            $locked = GameSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status === SessionStatus::RESERVED && $locked->reserved_until->lessThanOrEqualTo(now())) {
                $this->transition($locked, SessionStatus::CANCELLED, ActorType::SYSTEM, null, 'RESERVATION_EXPIRED');

                return true;
            }

            return false;
        });
        if ($expired) {
            throw ApiException::of(ErrorCode::RESERVATION_EXPIRED);
        }

        return DB::transaction(function () use ($session): GameSession {
            $now = CarbonImmutable::now();
            /** @var GameSession $locked */
            $locked = GameSession::query()->lockForUpdate()->findOrFail($session->id);
            SessionStateMachine::assert($locked->status, SessionStatus::STARTING);

            $settings = $this->settings->for(Tenant::query()->findOrFail($locked->tenant_id));
            if ($settings['photo_required'] && ! $locked->photo()->whereNull('deleted_at')->exists()) {
                throw ApiException::of(ErrorCode::PHOTO_REQUIRED);
            }
            $table = BilliardTable::query()->findOrFail($locked->table_id);
            $device = $this->onlineDeviceFor($table, $now);

            $locked->forceFill([
                'device_id' => $device->id,
                'start_at' => $now,
                'end_at' => $now->addMinutes($locked->duration_minutes),
                'reserved_until' => null,
            ]);
            $this->transition($locked, SessionStatus::STARTING, ActorType::TABLET, $locked->tablet_id);

            $this->commands->startSession($device, $locked, (int) $settings['warn_before_minutes'] * 60, 3);
            $this->audit->log('session.created', $locked, [
                'table' => $table->number, 'minutes' => $locked->duration_minutes, 'amount' => $locked->amount,
                'endAt' => $locked->end_at->toIso8601ZuluString(),
            ]);

            return $locked;
        });
    }

    public function cancel(Tablet $tablet, GameSession $session): GameSession
    {
        $this->assertOwnedByTablet($tablet, $session);

        return DB::transaction(function () use ($session): GameSession {
            $locked = GameSession::query()->lockForUpdate()->findOrFail($session->id);
            SessionStateMachine::assert($locked->status, SessionStatus::CANCELLED);
            $this->transition($locked, SessionStatus::CANCELLED, ActorType::TABLET, $locked->tablet_id, 'CUSTOMER_CANCELLED');

            return $locked;
        });
    }

    /** Device acknowledged START: the light is on. */
    public function confirmStarted(GameSession $session, int $deviceId): GameSession
    {
        return DB::transaction(function () use ($session, $deviceId): GameSession {
            $locked = GameSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status !== SessionStatus::STARTING) {
                return $locked; // late/duplicate ACK: idempotent
            }
            $this->transition($locked, SessionStatus::ACTIVE, ActorType::DEVICE, $deviceId);
            $this->audit->log('session.started', $locked, [], ['actor_type' => ActorType::DEVICE, 'actor_id' => $deviceId]);

            return $locked;
        });
    }

    /** START was not acknowledged in time: release the table and make sure the light cannot turn on late. */
    public function failStart(GameSession $session, string $reason): GameSession
    {
        return DB::transaction(function () use ($session, $reason): GameSession {
            $locked = GameSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status !== SessionStatus::STARTING) {
                return $locked;
            }
            $locked->forceFill(['failure_reason' => $reason, 'ended_at' => now()]);
            $this->transition($locked, SessionStatus::FAILED, ActorType::SYSTEM, null, $reason);
            if ($locked->device_id && ($device = Device::query()->find($locked->device_id))) {
                $this->commands->stopSession($device, $locked);
            }
            $this->audit->log('session.failed', $locked, ['reason' => $reason], ['actor_type' => ActorType::SYSTEM, 'actor_id' => null]);
            $table = BilliardTable::query()->find($locked->table_id);
            app(NotificationService::class)->notify($locked->tenant_id, 'session_failed', 'session_failed:'.$locked->id, [
                'text' => __('notifications.session_failed', [
                    'table' => $table?->name ?? '—',
                    'branch' => Branch::query()->find($locked->branch_id)?->name ?? '—',
                    'reason' => __('notifications.reason.'.$reason) !== 'notifications.reason.'.$reason ? __('notifications.reason.'.$reason) : $reason,
                ]),
                'branchId' => $locked->branch_id,
                'sessionId' => $locked->public_id,
            ], Severity::CRITICAL);

            return $locked;
        });
    }

    /** Staff early stop (permission sessions.stop). */
    public function stop(User $user, GameSession $session): GameSession
    {
        return DB::transaction(function () use ($user, $session): GameSession {
            $locked = GameSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status === SessionStatus::ACTIVE && $locked->end_at->lessThanOrEqualTo(now())) {
                throw ApiException::of(ErrorCode::INVALID_STATE_TRANSITION); // already over
            }
            SessionStateMachine::assert($locked->status, SessionStatus::COMPLETING);
            $locked->forceFill(['ended_at' => now(), 'ended_early' => true, 'stopped_by' => $user->id]);
            $this->transition($locked, SessionStatus::COMPLETING, ActorType::USER, $user->id, 'STAFF_STOP');
            if ($locked->device_id && ($device = Device::query()->find($locked->device_id))) {
                $this->commands->stopSession($device, $locked);
            }
            $this->audit->log('session.stopped', $locked, ['endedEarly' => true]);

            return $locked;
        });
    }

    /** Finalizes a finished session (end reached, or STOP acknowledged/timed out). */
    public function complete(GameSession $session, ActorType $actor = ActorType::SYSTEM, ?int $actorId = null, ?string $reason = null): GameSession
    {
        return DB::transaction(function () use ($session, $actor, $actorId, $reason): GameSession {
            $locked = GameSession::query()->lockForUpdate()->findOrFail($session->id);
            if (! SessionStateMachine::canTransition($locked->status, SessionStatus::COMPLETED)) {
                return $locked;
            }
            if ($locked->status === SessionStatus::ACTIVE && $locked->end_at->isFuture()) {
                return $locked; // not over yet
            }
            $locked->forceFill(['ended_at' => $locked->ended_at ?? $locked->end_at]);
            $this->transition($locked, SessionStatus::COMPLETED, $actor, $actorId, $reason);
            $this->audit->log('session.completed', $locked, ['endedEarly' => $locked->ended_early], ['actor_type' => $actor, 'actor_id' => $actorId]);

            return $locked;
        });
    }

    /** Manual payment status by authorized staff (spec §10). No automatic verification exists. */
    public function markPayment(User $user, GameSession $session, PaymentStatus $status): GameSession
    {
        if ($session->status === SessionStatus::RESERVED || $session->status === SessionStatus::CANCELLED || $session->status === SessionStatus::FAILED) {
            throw ApiException::of(ErrorCode::INVALID_STATE_TRANSITION);
        }
        $old = $session->payment_status;
        $session->forceFill(['payment_status' => $status, 'payment_marked_by' => $user->id, 'payment_marked_at' => now()])->save();
        $this->audit->log('session.payment_marked', $session, ['from' => $old->value, 'to' => $status->value]);

        return $session;
    }

    public function transition(GameSession $session, SessionStatus $to, ActorType $actor, ?int $actorId, ?string $reason = null): void
    {
        $from = $session->status;
        SessionStateMachine::assert($from, $to);
        $session->forceFill(['status' => $to])->save();
        $this->event($session, $from, $to, $actor, $actorId, $reason);
    }

    private function event(GameSession $session, ?SessionStatus $from, SessionStatus $to, ActorType $actor, ?int $actorId, ?string $reason = null): void
    {
        $event = new SessionEvent([
            'session_id' => $session->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actor,
            'actor_id' => $actorId,
            'reason' => $reason,
        ]);
        $event->tenant_id = $session->tenant_id;
        $event->save();
    }

    private function onlineDeviceFor(BilliardTable $table, CarbonImmutable $now): Device
    {
        $device = Device::query()->where('active_table_id', $table->id)->where('status', 'PAIRED')->first();
        if ($device === null) {
            throw ApiException::of(ErrorCode::DEVICE_NOT_ASSIGNED);
        }
        $maxAge = (int) config('devices.start_max_last_seen_sec', 20);
        if ($device->last_seen_at === null || $device->last_seen_at->lessThan($now->subSeconds($maxAge))) {
            throw ApiException::of(ErrorCode::DEVICE_OFFLINE);
        }

        return $device;
    }

    private function assertOwnedByTablet(Tablet $tablet, GameSession $session): void
    {
        if ($session->tablet_id !== $tablet->id) {
            throw ApiException::of(ErrorCode::NOT_FOUND);
        }
    }
}
