<?php

namespace App\Domain\Auth;

use App\Domain\Users\Enums\Role;

/** Reads config/permissions.php. The only place that knows which role has which permission. */
final class Permissions
{
    /** @return list<string> */
    public static function forRole(Role $role): array
    {
        return array_values(array_unique(config('permissions.roles.'.$role->value, [])));
    }

    public static function roleHas(Role $role, string $permission): bool
    {
        return in_array($permission, self::forRole($role), true);
    }

    /** @return list<string> every permission known to the system */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(...array_values(config('permissions.roles', [])))));
    }
}
