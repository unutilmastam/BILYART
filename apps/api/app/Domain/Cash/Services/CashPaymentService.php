<?php

namespace App\Domain\Cash\Services;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Enums\PaymentMode;
use App\Domain\Branches\Models\Branch;
use App\Domain\Cash\Enums\CashNoteStatus;
use App\Domain\Cash\Models\CashNote;
use App\Domain\Devices\Enums\DeviceKind;
use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Services\DeviceGateway;
use App\Domain\Notifications\Enums\Severity;
use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Sessions\Enums\PaymentStatus;
use App\Domain\Sessions\Enums\SessionStatus;
use App\Domain\Sessions\Models\GameSession;
use App\Domain\Sessions\Services\SessionService;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tablets\Models\Tablet;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Cash payment through the branch's bill acceptor (owner request 2026-10-05, adapted to this system):
 *
 *  tablet start ─▶ open(): the RESERVED session collects cash (the table stays held by the existing guard)
 *  bill box ESP ─▶ receive(): each bill is stored once (device + note_uid) and credited to that session
 *  fully paid / customer cancels / 3 min without a bill ─▶ acceptance stops, a short grace period catches a
 *  bill already inside the acceptor, then settle(): the paid amount becomes game time and the SERVER
 *  starts the lamp (SessionService::launch) — the tablet never commands a device.
 *
 * Only the cash device writes money; the tablet only reads it. Money never disappears silently:
 * a bill no session can take is stored UNASSIGNED and staff are notified; paid money whose lamp cannot
 * start ends as FAILED / PAID_NOT_STARTED with a critical notification.
 */
final class CashPaymentService
{
    /** The customer has this long after opening the payment and after every bill. */
    public const PAY_WINDOW_SEC = 180;

    /** After acceptance stops, a bill already inside the acceptor may still arrive. */
    public const GRACE_SEC = 8;

    /** UZS bills the acceptor is programmed for (DEVICE_PROTOCOL.md §cash). */
    public const NOMINALS = [1000, 2000, 5000, 10000, 20000, 50000, 100000, 200000];

    /** Never more than the lamp's hard cap. */
    public const MAX_MINUTES = DeviceGateway::MAX_SESSION_SEC / 60;

    public function __construct(
        private readonly SessionService $sessions,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /** Tablet: photo taken, now the customer pays. One paying session per bill acceptor (branch row lock). */
    public function open(Tablet $tablet, GameSession $session): GameSession
    {
        return DB::transaction(function () use ($session): GameSession {
            $now = CarbonImmutable::now();
            Branch::query()->whereKey($session->branch_id)->lockForUpdate()->firstOrFail();
            /** @var GameSession $locked */
            $locked = GameSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status !== SessionStatus::RESERVED || $locked->payment_source !== null) {
                throw ApiException::of(ErrorCode::INVALID_STATE_TRANSITION);
            }
            if (! $locked->photo()->whereNull('deleted_at')->exists()) {
                throw ApiException::of(ErrorCode::PHOTO_REQUIRED);
            }
            // Money is taken only when the lamp can be switched on right after.
            $this->sessions->onlineDeviceFor(BilliardTable::query()->findOrFail($locked->table_id), $now);
            $cash = $this->cashDeviceFor($locked->branch_id, $now);

            $this->settleDueFor($cash);
            if (GameSession::query()->where('cash_device_id', $cash->id)->where('status', SessionStatus::RESERVED->value)->exists()) {
                throw ApiException::of(ErrorCode::CASH_BUSY);
            }

            $until = $now->addSeconds(self::PAY_WINDOW_SEC);
            $locked->forceFill([
                'payment_source' => PaymentMode::BILL_ACCEPTOR,
                'cash_device_id' => $cash->id,
                'paying_until' => $until,
                'reserved_until' => $until->addSeconds(self::GRACE_SEC),
            ])->save();
            $this->audit->log('session.payment_opened', $locked, ['amount' => $locked->amount, 'cashDevice' => $cash->device_code]);

            return $locked;
        });
    }

    /** Tablet "cancel" (or the window ran out): stop accepting; what is already paid becomes game time after the grace period. */
    public function close(GameSession $session, string $reason): GameSession
    {
        return DB::transaction(function () use ($session, $reason): GameSession {
            /** @var GameSession $locked */
            $locked = GameSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status !== SessionStatus::RESERVED || $locked->payment_source !== PaymentMode::BILL_ACCEPTOR) {
                throw ApiException::of(ErrorCode::INVALID_STATE_TRANSITION);
            }
            $now = CarbonImmutable::now();
            if ($locked->paying_until !== null && $locked->paying_until->greaterThan($now)) {
                $locked->forceFill(['paying_until' => $now, 'reserved_until' => $now->addSeconds(self::GRACE_SEC)])->save();
                $this->audit->log('session.payment_closed', $locked, ['reason' => $reason, 'paid' => $locked->cash_paid]);
            }

            return $locked;
        });
    }

    /** What the bill acceptor should do now (its heartbeat). Also settles payments whose grace period is over. */
    public function activeFor(Device $cash): ?GameSession
    {
        $this->settleDueFor($cash);

        return GameSession::query()->where('cash_device_id', $cash->id)->where('status', SessionStatus::RESERVED->value)
            ->where('paying_until', '>', CarbonImmutable::now())->first();
    }

    /**
     * Bills reported by the cash device (possibly re-sent from its flash queue after a reboot).
     *
     * @param  list<array{noteUid: string, nominal: int, sessionId?: ?string, deviceTs?: ?int}>  $notes
     * @return list<array{noteUid: string, status: string}>
     */
    public function receive(Device $cash, array $notes): array
    {
        $results = [];
        foreach ($notes as $note) {
            [$result, $fullyPaid] = $this->receiveOne($cash, $note);
            $results[] = $result;
            if ($fullyPaid !== null) {
                $this->settle($fullyPaid, force: true); // the customer waits: start right away
            }
        }

        return $results;
    }

    /** @return array{0: array{noteUid: string, status: string}, 1: ?GameSession} */
    private function receiveOne(Device $cash, array $note): array
    {
        $uid = (string) $note['noteUid'];
        try {
            return DB::transaction(function () use ($cash, $note, $uid): array {
                if (CashNote::query()->where('device_id', $cash->id)->where('note_uid', $uid)->exists()) {
                    return [['noteUid' => $uid, 'status' => 'DUPLICATE'], null];
                }
                $now = CarbonImmutable::now();
                $nominal = (int) $note['nominal'];
                $base = GameSession::query()->where('cash_device_id', $cash->id)->lockForUpdate();
                /** @var GameSession|null $session */
                $session = ! empty($note['sessionId'])
                    ? (clone $base)->where('public_id', $note['sessionId'])->first()
                    : null;
                $session ??= (clone $base)->where('status', SessionStatus::RESERVED->value)->first();
                $credit = $session !== null && $session->status === SessionStatus::RESERVED && $session->payment_source === PaymentMode::BILL_ACCEPTOR;

                $row = new CashNote;
                $row->forceFill([
                    'branch_id' => $cash->branch_id,
                    'device_id' => $cash->id,
                    'session_id' => $session?->id,
                    'note_uid' => $uid,
                    'nominal' => $nominal,
                    'status' => $credit ? CashNoteStatus::CREDITED : CashNoteStatus::UNASSIGNED,
                    'device_ts' => isset($note['deviceTs']) ? (int) $note['deviceTs'] : null,
                    'received_at' => $now,
                ])->save();

                $fullyPaid = null;
                if ($credit) {
                    $session->cash_paid += $nominal;
                    if ($session->paying_until !== null && $session->paying_until->greaterThan($now)) {
                        $until = $now->addSeconds(self::PAY_WINDOW_SEC)->max($session->paying_until);
                        $session->forceFill(['paying_until' => $until, 'reserved_until' => $until->addSeconds(self::GRACE_SEC)]);
                    }
                    $session->save();
                    $fullyPaid = $session->cash_paid >= $session->amount ? $session : null;
                }
                $this->audit->log('cash.note_received', $row, [
                    'nominal' => $nominal, 'status' => $row->status->value, 'session' => $session?->public_id, 'paid' => $session?->cash_paid,
                ], ['actor_type' => ActorType::DEVICE, 'actor_id' => $cash->id]);
                if (! $credit) {
                    $this->notifyUnassigned($cash, $row);
                }

                return [['noteUid' => $uid, 'status' => $row->status->value], $fullyPaid];
            });
        } catch (UniqueConstraintViolationException) {
            return [['noteUid' => $uid, 'status' => 'DUPLICATE'], null]; // a parallel re-send won the race
        }
    }

    /**
     * Ends the payment: the paid amount becomes game time and the server starts the lamp.
     * Without $force it only acts once the grace period is over (or the price is reached).
     */
    public function settle(GameSession $session, bool $force = false): GameSession
    {
        return DB::transaction(function () use ($session, $force): GameSession {
            /** @var GameSession $locked */
            $locked = GameSession::query()->lockForUpdate()->findOrFail($session->id);
            if ($locked->status !== SessionStatus::RESERVED || $locked->payment_source !== PaymentMode::BILL_ACCEPTOR) {
                return $locked;
            }
            $now = CarbonImmutable::now();
            $paid = $locked->cash_paid;
            if (! $force && $paid < $locked->amount && $locked->reserved_until !== null && $locked->reserved_until->greaterThan($now)) {
                return $locked; // still collecting
            }
            if ($paid <= 0) {
                $locked->forceFill(['paying_until' => null])->save();
                $this->sessions->transition($locked, SessionStatus::CANCELLED, ActorType::SYSTEM, null, 'NOT_PAID');

                return $locked;
            }

            $minutes = self::minutesFor($paid, $locked->price_per_hour_snapshot);
            if ($paid >= $locked->amount) {
                $minutes = max($minutes, $locked->duration_minutes); // the chosen time is always honoured once its price is paid
            }
            $minutes = min(self::MAX_MINUTES, $minutes);
            if ($minutes < 1) {
                // Too little for a single minute: keep the money visible for staff instead of starting a 0-minute game.
                CashNote::query()->where('session_id', $locked->id)->update(['status' => CashNoteStatus::UNASSIGNED->value]);
                $locked->forceFill(['paying_until' => null])->save();
                $this->sessions->transition($locked, SessionStatus::CANCELLED, ActorType::SYSTEM, null, 'PAID_TOO_LITTLE');
                $this->notifyStaff($locked, 'cash_too_little', Severity::WARNING, ['amount' => self::money($paid)]);

                return $locked;
            }

            try {
                return $this->sessions->launch($locked->id, ActorType::SYSTEM, null, ['minutes' => $minutes, 'amount' => $paid]);
            } catch (ApiException $e) {
                return $this->failPaid($locked, $paid, $e->errorCode->value);
            }
        });
    }

    /** Settles one session if its grace period is over (tablet read path; the scheduler covers the rest). */
    public function settleIfDue(GameSession $session): GameSession
    {
        if ($session->status === SessionStatus::RESERVED && $session->payment_source === PaymentMode::BILL_ACCEPTOR
            && $session->reserved_until !== null && $session->reserved_until->lessThanOrEqualTo(now())) {
            return $this->settle($session);
        }

        return $session;
    }

    /** Minutes the paid amount buys at the hourly price (integer UZS, rounded down). */
    public static function minutesFor(int $paid, int $pricePerHour): int
    {
        return $pricePerHour > 0 ? intdiv($paid * 60, $pricePerHour) : 0;
    }

    private function settleDueFor(Device $cash): void
    {
        GameSession::query()->where('cash_device_id', $cash->id)->where('status', SessionStatus::RESERVED->value)
            ->where('reserved_until', '<=', CarbonImmutable::now())->get()
            ->each(fn (GameSession $s) => $this->settle($s));
    }

    private function cashDeviceFor(int $branchId, CarbonImmutable $now): Device
    {
        /** @var Device|null $cash */
        $cash = Device::query()->where('branch_id', $branchId)->where('kind', DeviceKind::CASH->value)
            ->where('status', DeviceStatus::PAIRED->value)->first();
        if ($cash === null || ! $cash->isOnline($now)) {
            throw ApiException::of(ErrorCode::CASH_DEVICE_OFFLINE);
        }

        return $cash;
    }

    /** Paid, but the lamp could not be started (device went offline): never hide it. */
    private function failPaid(GameSession $locked, int $paid, string $reason): GameSession
    {
        $locked->forceFill([
            'amount' => $paid,
            'payment_status' => PaymentStatus::PAID,
            'payment_marked_at' => now(),
            'paying_until' => null,
            'reserved_until' => null,
            'ended_at' => now(),
            'failure_reason' => 'PAID_NOT_STARTED',
        ]);
        $this->sessions->transition($locked, SessionStatus::FAILED, ActorType::SYSTEM, null, 'PAID_NOT_STARTED');
        $this->audit->log('session.paid_not_started', $locked, ['paid' => $paid, 'cause' => $reason], ['actor_type' => ActorType::SYSTEM, 'actor_id' => null]);
        $this->notifyStaff($locked, 'cash_paid_not_started', Severity::CRITICAL, ['amount' => self::money($paid)]);

        return $locked;
    }

    private function notifyStaff(GameSession $session, string $type, Severity $severity, array $replace): void
    {
        $table = BilliardTable::query()->find($session->table_id);
        $this->notifications->notify($session->tenant_id, $type, $type.':'.$session->id, [
            'text' => __('notifications.'.$type, $replace + [
                'table' => $table?->name ?? '—',
                'branch' => Branch::query()->find($session->branch_id)?->name ?? '—',
            ]),
            'branchId' => $session->branch_id,
            'sessionId' => $session->public_id,
        ], $severity);
    }

    private function notifyUnassigned(Device $cash, CashNote $note): void
    {
        $this->notifications->notify($cash->tenant_id, 'cash_unassigned', 'cash_unassigned:'.$note->id, [
            'text' => __('notifications.cash_unassigned', [
                'amount' => self::money($note->nominal),
                'branch' => Branch::query()->find($cash->branch_id)?->name ?? '—',
            ]),
            'branchId' => $cash->branch_id,
        ], Severity::WARNING);
    }

    private static function money(int $amount): string
    {
        return number_format($amount, 0, '.', ' ');
    }
}
