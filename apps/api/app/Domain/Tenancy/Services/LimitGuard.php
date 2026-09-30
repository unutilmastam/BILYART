<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Branches\Models\Branch;
use App\Domain\Devices\Models\Device;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * License limits (spec §5, §43 items 7–8). The check and the insert run in
 * one transaction with the tenant row locked (SELECT … FOR UPDATE), so two
 * parallel requests can never both pass the last free slot.
 * Only *active* records count; re-activating a record is checked the same way.
 */
final class LimitGuard
{
    public const BRANCHES = 'branches';

    public const TABLES = 'tables';

    public const DEVICES = 'devices';

    public const USERS = 'users';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $create  performs the insert/re-activation inside the locked transaction
     * @return T
     */
    public function within(string $resource, Closure $create): mixed
    {
        $tenantId = $this->context->requireTenantId();

        return DB::transaction(function () use ($resource, $create, $tenantId) {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenantId);
            $limit = $this->limitFor($tenant, $resource);

            if ($limit !== null && $this->count($resource) >= $limit) {
                throw ApiException::of(ErrorCode::LIMIT_REACHED, ['limit' => $limit]);
            }

            return $create();
        });
    }

    private function limitFor(Tenant $tenant, string $resource): ?int
    {
        return match ($resource) {
            self::BRANCHES => $tenant->branch_limit,
            self::TABLES => $tenant->table_limit,
            self::DEVICES => $tenant->device_limit,
            self::USERS => $tenant->user_limit,
        };
    }

    /** Counts through the tenant-scoped models (current tenant only). */
    private function count(string $resource): int
    {
        return match ($resource) {
            self::BRANCHES => Branch::query()->where('is_active', true)->count(),
            self::TABLES => BilliardTable::query()->where('is_active', true)->count(),
            self::DEVICES => Device::query()->where('status', 'PAIRED')->count(),
            self::USERS => User::query()->where('is_active', true)->count(),
        };
    }
}
