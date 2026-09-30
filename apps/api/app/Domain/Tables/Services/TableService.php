<?php

namespace App\Domain\Tables\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Pricing\Models\PricingPlan;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tenancy\Services\LimitGuard;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

final class TableService
{
    public function __construct(
        private readonly LimitGuard $limits,
        private readonly AuditLogger $audit,
    ) {}

    public function create(array $attributes): BilliardTable
    {
        $this->assertPlanFitsBranch($attributes['pricing_plan_id'] ?? null, (int) $attributes['branch_id']);

        return $this->limits->within(LimitGuard::TABLES, function () use ($attributes): BilliardTable {
            $this->assertNumberFree((int) $attributes['branch_id'], (int) $attributes['number']);
            $table = BilliardTable::query()->create($attributes + ['is_active' => true]);
            $this->audit->log('table.created', $table, ['number' => $table->number, 'branch' => $table->branch_id]);

            return $table;
        });
    }

    public function update(BilliardTable $table, array $attributes): BilliardTable
    {
        if (array_key_exists('pricing_plan_id', $attributes)) {
            $this->assertPlanFitsBranch($attributes['pricing_plan_id'], $table->branch_id);
        }
        $reactivate = ($attributes['is_active'] ?? null) === true && ! $table->is_active;
        $apply = function () use ($table, $attributes): BilliardTable {
            if (isset($attributes['number']) && $attributes['number'] !== $table->number) {
                $this->assertNumberFree($table->branch_id, (int) $attributes['number']);
            }
            $old = $table->only(['pricing_plan_id', 'is_active', 'number', 'name']);
            $table->fill($attributes)->save();
            $this->audit->log(
                array_key_exists('pricing_plan_id', $attributes) && $old['pricing_plan_id'] !== $table->pricing_plan_id ? 'table.price_changed' : 'table.updated',
                $table,
                ['from' => $old, 'to' => $table->only(array_keys($old))],
            );

            return $table;
        };

        return $reactivate ? $this->limits->within(LimitGuard::TABLES, $apply) : DB::transaction($apply);
    }

    private function assertNumberFree(int $branchId, int $number): void
    {
        if (BilliardTable::query()->where('branch_id', $branchId)->where('number', $number)->exists()) {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['number' => [__('validation.unique', ['attribute' => 'number'])]]]);
        }
    }

    /** A plan must belong to the tenant (scope) and be global or for the table's branch. */
    private function assertPlanFitsBranch(?int $planId, int $branchId): void
    {
        if ($planId === null) {
            return;
        }
        $plan = PricingPlan::query()->find($planId);
        if ($plan === null || ($plan->branch_id !== null && $plan->branch_id !== $branchId)) {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['pricingPlanId' => [__('validation.exists', ['attribute' => 'pricing plan'])]]]);
        }
    }
}
