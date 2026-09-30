<?php

namespace App\Domain\Sessions\Services;

use App\Domain\Sessions\Enums\SessionStatus as S;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;

/**
 * The only definition of legal session transitions (spec §33).
 *
 *  RESERVED ──start──▶ STARTING ──device ACK──▶ ACTIVE ──end reached──▶ COMPLETED
 *     │                   │  │                    │
 *     │ cancel/expired    │  └─no ACK─▶ FAILED    └─staff stop─▶ COMPLETING ──STOP ACK/timeout──▶ COMPLETED
 *     ▼                   └─staff stop─▶ COMPLETING
 *  CANCELLED
 */
final class SessionStateMachine
{
    /** @var array<string, list<S>> */
    private const ALLOWED = [
        'RESERVED' => [S::STARTING, S::CANCELLED],
        'STARTING' => [S::ACTIVE, S::FAILED, S::COMPLETING],
        'ACTIVE' => [S::COMPLETING, S::COMPLETED],
        'COMPLETING' => [S::COMPLETED],
        'COMPLETED' => [],
        'CANCELLED' => [],
        'FAILED' => [],
    ];

    public static function canTransition(S $from, S $to): bool
    {
        return in_array($to, self::ALLOWED[$from->value], true);
    }

    public static function assert(S $from, S $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw ApiException::of(ErrorCode::INVALID_STATE_TRANSITION);
        }
    }

    /** @return list<S> */
    public static function targets(S $from): array
    {
        return self::ALLOWED[$from->value];
    }
}
