<?php

namespace App\Domain\Tenancy\Enums;

/** Administrative flag set by the Super Admin. */
enum TenantStatusFlag: string
{
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case DEACTIVATED = 'DEACTIVATED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
