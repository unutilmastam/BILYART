<?php

namespace App\Domain\Tablets\Enums;

/** Lifecycle of a tablet registration row. */
enum TabletStatus: string
{
    case UNPAIRED = 'UNPAIRED';
    case PAIRED = 'PAIRED';
    case REVOKED = 'REVOKED';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
