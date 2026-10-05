<?php

namespace App\Http\Resources\Tablet;

use App\Domain\Branches\Enums\PaymentMode;
use App\Domain\Sessions\Enums\SessionStatus;
use App\Domain\Sessions\Models\GameSession;
use App\Domain\Tables\Models\BilliardTable;

/** protocol: tablet.session.schema.json */
final class TabletSessionResource
{
    public static function make(GameSession $s): array
    {
        $table = BilliardTable::query()->find($s->table_id);

        return [
            'serverTime' => now()->toIso8601ZuluString(),
            'session' => [
                'id' => $s->public_id,
                'tableId' => $table?->public_id,
                'tableNumber' => $table?->number,
                'status' => $s->status->value,
                'durationMinutes' => $s->duration_minutes,
                'amount' => $s->amount,
                'reservedUntil' => $s->reserved_until?->toIso8601ZuluString(),
                'startAt' => $s->start_at?->toIso8601ZuluString(),
                'endAt' => $s->end_at?->toIso8601ZuluString(),
                'hasPhoto' => $s->photo()->whereNull('deleted_at')->exists(),
                'failureReason' => $s->failure_reason,
                'payment' => $s->payment_source === PaymentMode::BILL_ACCEPTOR ? [
                    'mode' => 'BILL_ACCEPTOR',
                    'paid' => $s->cash_paid,
                    'accepting' => $s->status === SessionStatus::RESERVED && $s->paying_until !== null && $s->paying_until->isFuture() && $s->cash_paid < $s->amount,
                    'acceptUntil' => $s->paying_until?->toIso8601ZuluString(),
                ] : null,
            ],
        ];
    }
}
