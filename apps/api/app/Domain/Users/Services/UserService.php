<?php

namespace App\Domain\Users\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Auth\Services\TwoFactorService;
use App\Domain\Tenancy\Services\LimitGuard;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\User;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Staff management (spec §6 Users). Rules: nobody creates SUPER_ADMIN here;
 * managers manage only managers/operators; the last active owner cannot be
 * demoted or deactivated; nobody deactivates themselves.
 */
final class UserService
{
    public function __construct(
        private readonly LimitGuard $limits,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function create(User $actor, array $data): User
    {
        $role = Role::from($data['role']);
        $this->assertCanAssign($actor, $role);

        return $this->limits->within(LimitGuard::USERS, function () use ($data, $role): User {
            $user = new User(['name' => $data['name'], 'login' => $data['login'], 'password' => $data['password']]);
            $user->role = $role;
            $user->save();
            $this->syncBranches($user, $data['branch_ids'] ?? []);
            $this->audit->log('user.created', $user, ['role' => $role->value]);

            return $user;
        });
    }

    public function update(User $actor, User $user, array $data): User
    {
        $this->assertCanManage($actor, $user);
        $newRole = isset($data['role']) ? Role::from($data['role']) : $user->role;
        if ($newRole !== $user->role) {
            $this->assertCanAssign($actor, $newRole);
        }
        $deactivating = array_key_exists('is_active', $data) && $data['is_active'] === false && $user->is_active;
        $reactivating = ($data['is_active'] ?? null) === true && ! $user->is_active;

        if ($deactivating && $user->id === $actor->id) {
            throw ApiException::of(ErrorCode::FORBIDDEN);
        }

        $apply = function () use ($actor, $user, $data, $newRole, $deactivating): User {
            if (($deactivating || $newRole !== Role::CLIENT_OWNER) && $user->role === Role::CLIENT_OWNER) {
                $this->assertAnotherOwnerRemains($user);
            }
            $user->forceFill(array_filter([
                'name' => $data['name'] ?? null,
                'role' => $newRole,
                'is_active' => $data['is_active'] ?? null,
            ], fn ($v) => $v !== null));
            if (! empty($data['password'])) {
                $user->forceFill(['password' => $data['password'], 'failed_logins' => 0, 'locked_until' => null]);
                if ($user->id !== $actor->id) {
                    $user->forceFill(TwoFactorService::cleared()); // reset for a lost phone; own 2FA is changed only under Account
                }
            }
            $user->save();
            if (array_key_exists('branch_ids', $data)) {
                $this->syncBranches($user, $data['branch_ids']);
            }
            $this->audit->log($deactivating ? 'user.deactivated' : 'user.updated', $user, [
                'fields' => array_keys(array_diff_key($data, ['password' => 1])),
                'passwordChanged' => ! empty($data['password']),
            ]);

            return $user;
        };

        return $reactivating ? $this->limits->within(LimitGuard::USERS, $apply) : DB::transaction($apply);
    }

    private function assertCanAssign(User $actor, Role $role): void
    {
        $allowed = match ($actor->role) {
            Role::CLIENT_OWNER => [Role::CLIENT_OWNER, Role::CLIENT_MANAGER, Role::CLIENT_OPERATOR],
            Role::CLIENT_MANAGER => [Role::CLIENT_MANAGER, Role::CLIENT_OPERATOR],
            default => [],
        };
        if (! in_array($role, $allowed, true)) {
            throw ApiException::of(ErrorCode::FORBIDDEN);
        }
    }

    private function assertCanManage(User $actor, User $target): void
    {
        if ($actor->role === Role::CLIENT_MANAGER && $target->role === Role::CLIENT_OWNER) {
            throw ApiException::of(ErrorCode::FORBIDDEN);
        }
    }

    private function assertAnotherOwnerRemains(User $owner): void
    {
        // Lock the rows, then count (PostgreSQL does not allow FOR UPDATE with aggregates).
        $others = User::query()->where('role', Role::CLIENT_OWNER->value)->where('is_active', true)
            ->whereKeyNot($owner->id)->lockForUpdate()->pluck('id')->count();
        if ($others === 0) {
            throw ApiException::of(ErrorCode::CONFLICT);
        }
    }

    /** @param list<int> $branchIds already validated as the tenant's branches */
    private function syncBranches(User $user, array $branchIds): void
    {
        DB::table('user_branch_access')->where('user_id', $user->id)->delete();
        $tenantId = $this->context->requireTenantId();
        foreach (array_unique($branchIds) as $branchId) {
            DB::table('user_branch_access')->insert(['tenant_id' => $tenantId, 'user_id' => $user->id, 'branch_id' => $branchId, 'created_at' => now()]);
        }
    }
}
