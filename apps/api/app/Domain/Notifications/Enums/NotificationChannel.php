<?php

namespace App\Domain\Notifications\Enums;

/** Notification delivery channels. */
enum NotificationChannel: string
{
    case IN_APP = 'IN_APP';
    case TELEGRAM = 'TELEGRAM';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
