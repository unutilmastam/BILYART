<?php

namespace App\Domain\Tenancy\Enums;

/** Derived subscription status (spec §29). Never stored. */
enum SubscriptionStatus: string
{
    case ACTIVE = 'ACTIVE';
    case EXPIRING_SOON = 'EXPIRING_SOON';
    case EXPIRED = 'EXPIRED';
    case SUSPENDED = 'SUSPENDED';
    case DEACTIVATED = 'DEACTIVATED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
