<?php

namespace App\Domain\Sessions\Enums;

/** Explicit session state machine states (spec §33). Transitions live in SessionStateMachine. */
enum SessionStatus: string
{
    case RESERVED = 'RESERVED';
    case STARTING = 'STARTING';
    case ACTIVE = 'ACTIVE';
    case COMPLETING = 'COMPLETING';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case FAILED = 'FAILED';

    /**
     * Statuses that hold the table. Must match the double-booking guard in
     * migration 2026_10_01_000007 (table_lock / partial unique index).
     *
     * @return list<self>
     */
    public static function occupying(): array
    {
        return [self::RESERVED, self::STARTING, self::ACTIVE, self::COMPLETING];
    }

    public function isOccupying(): bool
    {
        return in_array($this, self::occupying(), true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED, self::FAILED], true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
