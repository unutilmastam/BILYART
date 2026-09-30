<?php

namespace App\Domain\Devices\Enums;

/** Device command types (spec §16). */
enum CommandType: string
{
    case START_SESSION = 'START_SESSION';
    case STOP_SESSION = 'STOP_SESSION';
    case WARNING = 'WARNING';
    case SYNC = 'SYNC';
    case PING = 'PING';
    case CONFIG_UPDATE = 'CONFIG_UPDATE';
    case OTA = 'OTA';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
