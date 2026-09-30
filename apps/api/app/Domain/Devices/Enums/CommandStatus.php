<?php

namespace App\Domain\Devices\Enums;

/** Device command delivery status (spec §34). */
enum CommandStatus: string
{
    case PENDING = 'PENDING';
    case SENT = 'SENT';
    case ACKNOWLEDGED = 'ACKNOWLEDGED';
    case FAILED = 'FAILED';
    case EXPIRED = 'EXPIRED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
