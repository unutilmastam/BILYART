<?php

namespace Tests\Concerns;

use App\Domain\Branches\Models\Branch;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Users\Models\User;

trait CreatesUsers
{
    protected function tenantUser(string $role = 'CLIENT_OWNER', ?Tenant $tenant = null, array $attributes = []): User
    {
        return $this->asSystem(function () use ($role, $tenant, $attributes): User {
            $tenant ??= Tenant::factory()->create();

            return User::factory()->create(array_merge(['tenant_id' => $tenant->id, 'role' => $role], $attributes))->fresh();
        });
    }

    protected function superAdmin(array $attributes = []): User
    {
        return $this->asSystem(fn () => User::factory()->superAdmin()->create($attributes)->fresh());
    }

    protected function branchFor(Tenant|int $tenant, array $attributes = []): Branch
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return $this->asSystem(fn () => Branch::factory()->create(array_merge(['tenant_id' => $tenantId], $attributes)));
    }
}
