<?php

namespace Tests\Feature\Auth;

use App\Domain\Auth\Permissions;
use App\Domain\Users\Enums\Role;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** SECURITY.md §2 table, pinned so a config edit cannot silently widen access. */
class PermissionMatrixTest extends TestCase
{
    #[Test]
    public function roles_have_exactly_the_documented_permissions(): void
    {
        $operator = ['tables.view', 'sessions.view', 'sessions.create', 'sessions.stop', 'sessions.mark_payment'];
        $manager = array_merge($operator, ['photos.view', 'photos.delete', 'reports.view', 'tables.manage', 'pricing.manage', 'working_hours.manage', 'devices.manage', 'users.manage']);
        $owner = array_merge($manager, ['branches.manage', 'telegram.manage', 'tenant.settings', 'tenant.export']);

        $this->assertEqualsCanonicalizing($operator, Permissions::forRole(Role::CLIENT_OPERATOR));
        $this->assertEqualsCanonicalizing($manager, Permissions::forRole(Role::CLIENT_MANAGER));
        $this->assertEqualsCanonicalizing($owner, Permissions::forRole(Role::CLIENT_OWNER));
    }

    #[Test]
    public function super_admin_has_only_platform_permissions_and_no_photo_access(): void
    {
        $super = Permissions::forRole(Role::SUPER_ADMIN);

        $this->assertNotEmpty($super);
        foreach ($super as $permission) {
            $this->assertStringStartsWith('platform.', $permission);
        }
        $this->assertFalse(Permissions::roleHas(Role::SUPER_ADMIN, 'photos.view'));
        $this->assertFalse(Permissions::roleHas(Role::CLIENT_OPERATOR, 'photos.view'));
    }
}
