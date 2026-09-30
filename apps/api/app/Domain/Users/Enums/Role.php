<?php

namespace App\Domain\Users\Enums;

/** User roles (spec §28). DEVICE and TABLET are separate principals, not users. */
enum Role: string
{
    case SUPER_ADMIN = 'SUPER_ADMIN';
    case CLIENT_OWNER = 'CLIENT_OWNER';
    case CLIENT_MANAGER = 'CLIENT_MANAGER';
    case CLIENT_OPERATOR = 'CLIENT_OPERATOR';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
