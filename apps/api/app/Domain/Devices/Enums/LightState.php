<?php

namespace App\Domain\Devices\Enums;

/** Relay/light state reported by a device. */
enum LightState: string
{
    case ON = 'ON';
    case OFF = 'OFF';
    case WARNING = 'WARNING';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
