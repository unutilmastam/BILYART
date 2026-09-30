<?php

namespace App\Domain\Audit\Enums;

/** Who performed an audited action. */
enum ActorType: string
{
    case USER = 'USER';
    case DEVICE = 'DEVICE';
    case TABLET = 'TABLET';
    case SYSTEM = 'SYSTEM';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
