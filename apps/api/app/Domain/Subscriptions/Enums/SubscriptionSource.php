<?php

namespace App\Domain\Subscriptions\Enums;

/** Why a subscription period was added. */
enum SubscriptionSource: string
{
    case PAYMENT = 'PAYMENT';
    case MANUAL_ADJUST = 'MANUAL_ADJUST';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
