<?php

namespace Tests\Feature\Admin;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class UsersTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function tenant(): Tenant
    {
        return $this->asSystem(fn () => Tenant::factory()->create(['branch_limit' => 5]));
    }

    #[Test]
    public function owner_creates_staff_who_can_log_in_with_their_own_password(): void
    {
        $t = $this->tenant();
        $owner = $this->tenantUser('CLIENT_OWNER', $t);

        $this->actingAs($owner)->postJson('/api/admin/users', ['name' => 'Kassir', 'login' => 'Kassir1', 'password' => 'Kassir-pass1', 'role' => 'CLIENT_OPERATOR'])
            ->assertCreated()->assertJsonPath('data.login', 'kassir1')->assertJsonMissingPath('data.password');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['login' => 'kassir1', 'password' => 'Kassir-pass1'])->assertOk()->assertJsonPath('user.role', 'CLIENT_OPERATOR');
    }

    #[Test]
    public function managers_cannot_create_or_edit_owners_and_nobody_creates_super_admins(): void
    {
        $t = $this->tenant();
        $owner = $this->tenantUser('CLIENT_OWNER', $t);
        $manager = $this->tenantUser('CLIENT_MANAGER', $t);

        $this->actingAs($manager)->postJson('/api/admin/users', ['name' => 'X', 'login' => 'x-own', 'password' => 'Passw0rd-xx', 'role' => 'CLIENT_OWNER'])->assertStatus(403);
        $this->actingAs($manager)->patchJson("/api/admin/users/{$owner->public_id}", ['name' => 'Hacked'])->assertStatus(403);
        $this->actingAs($manager)->postJson('/api/admin/users', ['name' => 'Y', 'login' => 'y-op', 'password' => 'Passw0rd-yy', 'role' => 'CLIENT_OPERATOR'])->assertCreated();
        $this->actingAs($owner)->postJson('/api/admin/users', ['name' => 'Z', 'login' => 'z-sa', 'password' => 'Passw0rd-zz', 'role' => 'SUPER_ADMIN'])->assertStatus(422);
    }

    #[Test]
    public function the_last_owner_cannot_be_removed_and_nobody_deactivates_themselves(): void
    {
        $t = $this->tenant();
        $owner = $this->tenantUser('CLIENT_OWNER', $t);

        $this->actingAs($owner)->postJson("/api/admin/users/{$owner->public_id}/deactivate")->assertStatus(403);
        $this->actingAs($owner)->patchJson("/api/admin/users/{$owner->public_id}", ['role' => 'CLIENT_MANAGER'])->assertStatus(409);

        $second = $this->tenantUser('CLIENT_OWNER', $t);
        $this->actingAs($second)->postJson("/api/admin/users/{$owner->public_id}/deactivate")->assertOk()->assertJsonPath('data.isActive', false);
    }

    #[Test]
    public function operators_cannot_manage_users_and_other_tenants_users_are_invisible(): void
    {
        $t = $this->tenant();
        $operator = $this->tenantUser('CLIENT_OPERATOR', $t);
        $foreign = $this->tenantUser('CLIENT_OPERATOR');

        $this->actingAs($operator)->getJson('/api/admin/users')->assertStatus(403);
        $owner = $this->tenantUser('CLIENT_OWNER', $t);
        $this->actingAs($owner)->getJson("/api/admin/users/{$foreign->public_id}")->assertNotFound();
        $logins = collect($this->actingAs($owner)->getJson('/api/admin/users')->json('data'))->pluck('login');
        $this->assertNotContains($foreign->login, $logins);
    }

    #[Test]
    public function staff_restricted_to_a_branch_only_see_that_branch(): void
    {
        $t = $this->tenant();
        $owner = $this->tenantUser('CLIENT_OWNER', $t);
        $b1 = $this->actingAs($owner)->postJson('/api/admin/branches', ['name' => 'Birinchi'])->json('data.id');
        $b2 = $this->actingAs($owner)->postJson('/api/admin/branches', ['name' => 'Ikkinchi'])->json('data.id');
        $this->actingAs($owner)->postJson('/api/admin/tables', ['branchId' => $b1, 'number' => 1])->assertCreated();
        $t2 = $this->actingAs($owner)->postJson('/api/admin/tables', ['branchId' => $b2, 'number' => 1])->json('data.id');

        $this->actingAs($owner)->postJson('/api/admin/users', [
            'name' => 'Op', 'login' => 'op-b1', 'password' => 'Passw0rd-op', 'role' => 'CLIENT_OPERATOR', 'branchIds' => [$b1],
        ])->assertCreated()->assertJsonPath('data.branchIds', [$b1]);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['login' => 'op-b1', 'password' => 'Passw0rd-op'])->assertOk();

        $this->assertSame(['Birinchi'], collect($this->getJson('/api/admin/branches')->json('data'))->pluck('name')->all());
        $this->assertCount(1, $this->getJson('/api/admin/tables')->json('data'));
        $this->getJson("/api/admin/branches/$b2")->assertNotFound();
        $this->getJson("/api/admin/tables/$t2")->assertNotFound();
    }
}
