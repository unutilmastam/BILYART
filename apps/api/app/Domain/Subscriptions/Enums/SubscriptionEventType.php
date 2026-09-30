<?php

namespace App\Domain\Subscriptions\Enums;

/** Subscription history event types. */
enum SubscriptionEventType: string
{
    case CREATED = 'CREATED';
    case ACTIVATED = 'ACTIVATED';
    case EXTENDED = 'EXTENDED';
    case SUSPENDED = 'SUSPENDED';
    case RESUMED = 'RESUMED';
    case DEACTIVATED = 'DEACTIVATED';
    case EXPIRED = 'EXPIRED';
    case LIMIT_CHANGED = 'LIMIT_CHANGED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
