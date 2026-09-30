<?php

namespace App\Domain\Sessions\Enums;

/** Set manually by staff (spec §10). Never verified automatically. */
enum PaymentStatus: string
{
    case UNPAID = 'UNPAID';
    case PAID = 'PAID';
    case WAIVED = 'WAIVED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
