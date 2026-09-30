<?php

namespace App\Domain\Devices\Enums;

/** Lifecycle of a device registration row. */
enum DeviceStatus: string
{
    case UNPAIRED = 'UNPAIRED';
    case PAIRED = 'PAIRED';
    case REVOKED = 'REVOKED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
