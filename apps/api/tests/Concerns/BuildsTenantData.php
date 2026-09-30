<?php

namespace Tests\Concerns;

use App\Domain\Branches\Models\Branch;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Raw-SQL builders that bypass Eloquent, so tests prove the *database* enforces the rules. */
trait BuildsTenantData
{
    /** @return array{tenant: Tenant, branch: Branch, table: BilliardTable} */
    protected function tenantWithTable(): array
    {
        return $this->asSystem(function (): array {
            $tenant = Tenant::factory()->create();
            $branch = Branch::factory()->create(['tenant_id' => $tenant->id]);
            $table = BilliardTable::factory()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'number' => 1]);

            return compact('tenant', 'branch', 'table');
        });
    }

    protected function insertSession(int $tenantId, int $branchId, int $tableId, string $status, array $extra = []): int
    {
        $started = in_array($status, ['STARTING', 'ACTIVE', 'COMPLETING', 'COMPLETED'], true);

        return (int) DB::table('game_sessions')->insertGetId(array_merge([
            'public_id' => (string) Str::ulid(),
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'table_id' => $tableId,
            'status' => $status,
            'duration_minutes' => 60,
            'start_at' => $started ? now() : null,
            'end_at' => $started ? now()->addHour() : null,
            'price_per_hour_snapshot' => 20000,
            'rounding_step_snapshot' => 1000,
            'amount' => 20000,
            'payment_status' => 'UNPAID',
            'created_at' => now(),
            'updated_at' => now(),
        ], $extra));
    }
}
