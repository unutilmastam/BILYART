<?php

namespace App\Domain\Subscriptions\Enums;

/** Manual platform payment methods (spec §4). No gateway. */
enum PaymentMethod: string
{
    case CASH = 'CASH';
    case BANK_TRANSFER = 'BANK_TRANSFER';
    case CARD_TRANSFER = 'CARD_TRANSFER';
    case OTHER = 'OTHER';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
