<?php

namespace App\Domain\Notifications\Enums;

/** Notification severity. */
enum Severity: string
{
    case INFO = 'INFO';
    case WARNING = 'WARNING';
    case CRITICAL = 'CRITICAL';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
