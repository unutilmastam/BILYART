<?php

namespace Tests\Feature\Admin;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Spec §61 scenario + §43 items 7–8. */
class LicenseLimitsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    #[Test]
    public function spec_61_branch_limit_scenario(): void
    {
        [$a, $b] = $this->asSystem(fn () => [Tenant::factory()->create(['branch_limit' => 2]), Tenant::factory()->create(['branch_limit' => 5])]);
        $ownerA = $this->tenantUser('CLIENT_OWNER', $a);
        $ownerB = $this->tenantUser('CLIENT_OWNER', $b);
        foreach (range(1, 5) as $i) {
            $this->actingAs($ownerB)->postJson('/api/admin/branches', ['name' => "B-$i"])->assertCreated();
        }

        $this->actingAs($ownerA)->postJson('/api/admin/branches', ['name' => 'Branch 1'])->assertCreated();
        $this->actingAs($ownerA)->postJson('/api/admin/branches', ['name' => 'Branch 2'])->assertCreated();
        $this->actingAs($ownerA)->postJson('/api/admin/branches', ['name' => 'Branch 3'])
            ->assertStatus(422)->assertJsonPath('error.code', 'LIMIT_REACHED');

        $this->actingAs($this->superAdmin())->putJson("/api/super/tenants/{$a->public_id}/limits", [
            'branchLimit' => 3, 'tableLimit' => null, 'deviceLimit' => null, 'userLimit' => null,
        ])->assertOk();

        $this->actingAs($ownerA)->postJson('/api/admin/branches', ['name' => 'Branch 3'])->assertCreated();

        $names = collect($this->actingAs($ownerA)->getJson('/api/admin/branches')->json('data'))->pluck('name')->all();
        $this->assertSame(['Branch 1', 'Branch 2', 'Branch 3'], $names); // A never sees B
    }

    #[Test]
    public function item_7_branch_limit_cannot_be_bypassed_by_disabling_and_re_enabling(): void
    {
        $a = $this->asSystem(fn () => Tenant::factory()->create(['branch_limit' => 1]));
        $owner = $this->tenantUser('CLIENT_OWNER', $a);

        $first = $this->actingAs($owner)->postJson('/api/admin/branches', ['name' => 'One'])->assertCreated()->json('data.id');
        $this->actingAs($owner)->deleteJson("/api/admin/branches/$first")->assertOk()->assertJsonPath('data.isActive', false);
        $this->actingAs($owner)->postJson('/api/admin/branches', ['name' => 'Two'])->assertCreated();

        $this->actingAs($owner)->patchJson("/api/admin/branches/$first", ['isActive' => true])
            ->assertStatus(422)->assertJsonPath('error.code', 'LIMIT_REACHED');
    }

    #[Test]
    public function item_8_table_limit_cannot_be_bypassed(): void
    {
        $a = $this->asSystem(fn () => Tenant::factory()->create(['branch_limit' => 2, 'table_limit' => 2]));
        $owner = $this->tenantUser('CLIENT_OWNER', $a);
        $b1 = $this->actingAs($owner)->postJson('/api/admin/branches', ['name' => 'B1'])->json('data.id');
        $b2 = $this->actingAs($owner)->postJson('/api/admin/branches', ['name' => 'B2'])->json('data.id');

        $t1 = $this->actingAs($owner)->postJson('/api/admin/tables', ['branchId' => $b1, 'number' => 1])->assertCreated()->json('data.id');
        $this->actingAs($owner)->postJson('/api/admin/tables', ['branchId' => $b2, 'number' => 1])->assertCreated();
        $this->actingAs($owner)->postJson('/api/admin/tables', ['branchId' => $b2, 'number' => 2])
            ->assertStatus(422)->assertJsonPath('error.code', 'LIMIT_REACHED');

        $this->actingAs($owner)->deleteJson("/api/admin/tables/$t1")->assertOk();
        $this->actingAs($owner)->postJson('/api/admin/tables', ['branchId' => $b2, 'number' => 2])->assertCreated();
        $this->actingAs($owner)->patchJson("/api/admin/tables/$t1", ['isActive' => true])->assertStatus(422);
    }

    #[Test]
    public function user_limit_is_enforced(): void
    {
        $a = $this->asSystem(fn () => Tenant::factory()->create(['user_limit' => 2]));
        $owner = $this->tenantUser('CLIENT_OWNER', $a);

        $this->actingAs($owner)->postJson('/api/admin/users', ['name' => 'Op', 'login' => 'op1', 'password' => 'Passw0rd-Op1', 'role' => 'CLIENT_OPERATOR'])->assertCreated();
        $this->actingAs($owner)->postJson('/api/admin/users', ['name' => 'Op2', 'login' => 'op2', 'password' => 'Passw0rd-Op2', 'role' => 'CLIENT_OPERATOR'])
            ->assertStatus(422)->assertJsonPath('error.code', 'LIMIT_REACHED');
    }

    #[Test]
    public function operators_and_managers_cannot_create_branches(): void
    {
        $a = $this->asSystem(fn () => Tenant::factory()->create(['branch_limit' => 5]));

        foreach (['CLIENT_MANAGER', 'CLIENT_OPERATOR'] as $role) {
            $this->actingAs($this->tenantUser($role, $a))->postJson('/api/admin/branches', ['name' => 'X'])->assertStatus(403);
        }
    }
}
