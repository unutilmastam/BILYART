<?php

namespace Tests\Feature\Admin;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class TablesAndPricingTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function owner(array $tenant = ['branch_limit' => 3]): array
    {
        $t = $this->asSystem(fn () => Tenant::factory()->create($tenant));
        $owner = $this->tenantUser('CLIENT_OWNER', $t);
        $branch = $this->actingAs($owner)->postJson('/api/admin/branches', ['name' => 'Markaz'])->json('data.id');

        return [$owner, $branch, $t];
    }

    #[Test]
    public function owner_creates_a_plan_and_a_priced_table(): void
    {
        [$owner, $branch] = $this->owner();

        $plan = $this->actingAs($owner)->postJson('/api/admin/pricing-plans', [
            'name' => 'Standart', 'pricePerHour' => 25000, 'roundingStep' => 1000, 'allowedDurations' => [60, 30, 50],
        ])->assertCreated()
            ->assertJsonPath('data.quotes', [['minutes' => 30, 'amount' => 13000], ['minutes' => 50, 'amount' => 21000], ['minutes' => 60, 'amount' => 25000]])
            ->json('data.id');

        $this->actingAs($owner)->postJson('/api/admin/tables', ['branchId' => $branch, 'number' => 1, 'pricingPlanId' => $plan])
            ->assertCreated()->assertJsonPath('data.name', '1-stol')->assertJsonPath('data.pricingPlan.pricePerHour', 25000);
    }

    #[Test]
    public function money_must_be_integer(): void
    {
        [$owner] = $this->owner();
        $this->actingAs($owner)->postJson('/api/admin/pricing-plans', ['name' => 'X', 'pricePerHour' => 20000.5, 'allowedDurations' => [60]])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['pricePerHour']]]);
    }

    #[Test]
    public function table_numbers_are_unique_per_branch(): void
    {
        [$owner, $branch] = $this->owner();
        $this->actingAs($owner)->postJson('/api/admin/tables', ['branchId' => $branch, 'number' => 1])->assertCreated();
        $this->actingAs($owner)->postJson('/api/admin/tables', ['branchId' => $branch, 'number' => 1])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['number']]]);
    }

    #[Test]
    public function another_tenants_branch_or_plan_cannot_be_referenced(): void
    {
        [$ownerA, $branchA] = $this->owner();
        [$ownerB, $branchB] = $this->owner();
        $planB = $this->actingAs($ownerB)->postJson('/api/admin/pricing-plans', ['name' => 'B', 'pricePerHour' => 1, 'allowedDurations' => [60]])->json('data.id');
        $tableB = $this->actingAs($ownerB)->postJson('/api/admin/tables', ['branchId' => $branchB, 'number' => 1])->json('data.id');

        $this->actingAs($ownerA)->postJson('/api/admin/tables', ['branchId' => $branchB, 'number' => 5])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['branchId']]]);
        $this->actingAs($ownerA)->postJson('/api/admin/tables', ['branchId' => $branchA, 'number' => 5, 'pricingPlanId' => $planB])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['pricingPlanId']]]);
        $this->actingAs($ownerA)->getJson("/api/admin/tables/$tableB")->assertNotFound();
        $this->actingAs($ownerA)->patchJson("/api/admin/tables/$tableB", ['name' => 'hack'])->assertNotFound();
        $this->actingAs($ownerA)->getJson("/api/admin/pricing-plans/$planB")->assertNotFound();
        $this->actingAs($ownerA)->patchJson("/api/admin/branches/$branchB", ['name' => 'hack'])->assertNotFound();
    }

    #[Test]
    public function a_branch_specific_plan_cannot_price_a_table_in_another_branch(): void
    {
        [$owner, $branch1] = $this->owner();
        $branch2 = $this->actingAs($owner)->postJson('/api/admin/branches', ['name' => 'Ikkinchi'])->json('data.id');
        $plan = $this->actingAs($owner)->postJson('/api/admin/pricing-plans', ['name' => 'Only 2', 'branchId' => $branch2, 'pricePerHour' => 1000, 'allowedDurations' => [60]])->json('data.id');

        $this->actingAs($owner)->postJson('/api/admin/tables', ['branchId' => $branch1, 'number' => 1, 'pricingPlanId' => $plan])->assertStatus(422);
        $this->actingAs($owner)->postJson('/api/admin/tables', ['branchId' => $branch2, 'number' => 1, 'pricingPlanId' => $plan])->assertCreated();
    }

    #[Test]
    public function price_changes_are_audited(): void
    {
        [$owner] = $this->owner();
        $plan = $this->actingAs($owner)->postJson('/api/admin/pricing-plans', ['name' => 'S', 'pricePerHour' => 20000, 'allowedDurations' => [60]])->json('data.id');
        $this->actingAs($owner)->patchJson("/api/admin/pricing-plans/$plan", ['pricePerHour' => 30000])->assertOk();

        $log = $this->asSystem(fn () => AuditLog::query()->where('action', 'pricing.changed')->firstOrFail());
        $this->assertSame(20000, $log->metadata['from']['price_per_hour']);
        $this->assertSame(30000, $log->metadata['to']['price_per_hour']);
    }

    #[Test]
    public function operators_can_view_tables_but_not_change_them_or_prices(): void
    {
        [$owner, $branch, $tenant] = $this->owner();
        $table = $this->actingAs($owner)->postJson('/api/admin/tables', ['branchId' => $branch, 'number' => 1])->json('data.id');
        $operator = $this->tenantUser('CLIENT_OPERATOR', $tenant);

        $this->actingAs($operator)->getJson('/api/admin/tables')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($operator)->patchJson("/api/admin/tables/$table", ['name' => 'x'])->assertStatus(403);
        $this->actingAs($operator)->getJson('/api/admin/pricing-plans')->assertStatus(403);
    }
}
