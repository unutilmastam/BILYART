<?php

namespace App\Domain\Pricing\Enums;

/** Pricing model strategy key (spec §6: extensible). */
enum PricingType: string
{
    case HOURLY = 'HOURLY';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
