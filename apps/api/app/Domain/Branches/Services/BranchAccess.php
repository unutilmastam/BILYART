<?php

namespace App\Domain\Branches\Services;

use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Optional restriction of managers/operators to some branches (user_branch_access).
 * Owners always see every branch; an empty assignment means "all branches".
 */
final class BranchAccess
{
    /** @var array<int, list<int>|null> */
    private array $cache = [];

    /** @return list<int>|null null = unrestricted */
    public function allowedBranchIds(User $user): ?array
    {
        if ($user->role === Role::CLIENT_OWNER) {
            return null;
        }
        if (! array_key_exists($user->id, $this->cache)) {
            $ids = DB::table('user_branch_access')->where('user_id', $user->id)->pluck('branch_id')->map(fn ($v) => (int) $v)->all();
            $this->cache[$user->id] = $ids === [] ? null : $ids;
        }

        return $this->cache[$user->id];
    }

    /** Restricts a query on a table with a branch column to the user's branches. */
    public function scope(Builder $query, User $user, string $column = 'branch_id'): Builder
    {
        $ids = $this->allowedBranchIds($user);

        return $ids === null ? $query : $query->whereIn($query->qualifyColumn($column), $ids);
    }

    public function canAccess(User $user, int $branchId): bool
    {
        $ids = $this->allowedBranchIds($user);

        return $ids === null || in_array($branchId, $ids, true);
    }
}
