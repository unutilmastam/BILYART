<?php

namespace Tests\Feature\Security;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Spec §43 items 1–3. */
class TenantIsolationApiTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    /** @return array{0: User, 1: Tenant, 2: Tenant} */
    private function setUpTenants(): array
    {
        [$a, $b] = $this->asSystem(fn () => [Tenant::factory()->create(), Tenant::factory()->create(['branch_limit' => 5])]);
        $this->branchFor($a, ['name' => 'A-1']);
        $this->branchFor($a, ['name' => 'A-2']);
        foreach (range(1, 5) as $i) {
            $this->branchFor($b, ['name' => "B-$i"]);
        }

        return [$this->tenantUser('CLIENT_OWNER', $a), $a, $b];
    }

    #[Test]
    public function item_1_client_a_only_sees_its_own_data(): void
    {
        [$userA] = $this->setUpTenants();

        $names = collect($this->actingAs($userA)->getJson('/api/admin/branches')->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['A-1', 'A-2'], $names);
    }

    #[Test]
    public function item_2_changing_the_url_to_client_b_resource_returns_404(): void
    {
        [$userA, , $b] = $this->setUpTenants();
        $bBranch = $this->asSystem(fn () => $b->branches()->first());

        $this->actingAs($userA)->getJson('/api/admin/branches/'.$bBranch->public_id)
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonMissing(['name' => $bBranch->name]);
    }

    #[Test]
    public function item_3_tenant_parameters_in_query_body_or_headers_are_ignored(): void
    {
        [$userA, $a, $b] = $this->setUpTenants();

        foreach (['tenant_id', 'tenantId', 'tenant'] as $param) {
            $names = collect($this->actingAs($userA)
                ->withHeaders(['X-Tenant-Id' => (string) $b->id, 'X-Tenant' => $b->public_id])
                ->getJson("/api/admin/branches?$param={$b->id}")
                ->assertOk()->json('data'))->pluck('name')->all();
            $this->assertSame(['A-1', 'A-2'], $names, "param $param leaked data");
        }
    }

    #[Test]
    public function super_admin_cannot_use_client_routes_and_clients_cannot_use_super_routes(): void
    {
        [$userA] = $this->setUpTenants();

        Route::middleware(['web', 'auth:web', 'tenant.user', 'super.admin'])->get('/_test/super', fn () => ['ok' => true]);

        $this->actingAs($this->superAdmin())->getJson('/api/admin/branches')->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
        $this->actingAs($userA)->getJson('/_test/super')->assertStatus(403);
        $this->actingAs($this->superAdmin())->getJson('/_test/super')->assertOk();
    }

    #[Test]
    public function guests_get_401_in_the_standard_error_shape(): void
    {
        $this->getJson('/api/admin/branches')
            ->assertStatus(401)
            ->assertJsonStructure(['error' => ['code', 'message', 'requestId']])
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    #[Test]
    public function expired_or_suspended_clients_are_blocked_from_business_routes_but_keep_their_data(): void
    {
        [$userA, $a] = $this->setUpTenants();

        foreach ([['subscription_expires_at' => now()->subMinute()], ['status_flag' => 'SUSPENDED']] as $state) {
            $this->asSystem(fn () => Tenant::query()->whereKey($a->id)->update($state + ['status_flag' => $state['status_flag'] ?? 'ACTIVE']));

            $this->actingAs($userA)->getJson('/api/admin/branches')->assertStatus(402)->assertJsonPath('error.code', 'SUBSCRIPTION_INACTIVE');
            $this->actingAs($userA)->getJson('/api/me')->assertOk();
            $this->assertSame(2, $this->asSystem(fn () => $a->branches()->count()));

            $this->asSystem(fn () => Tenant::query()->whereKey($a->id)->update(['status_flag' => 'ACTIVE', 'subscription_expires_at' => now()->addDays(30)]));
        }
    }
}
