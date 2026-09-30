<?php

namespace Tests\Feature\SuperAdmin;

use App\Domain\Branches\Models\Branch;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class TenantManagementTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function createClient(array $overrides = []): array
    {
        return $this->actingAs($this->superAdmin())->postJson('/api/super/tenants', array_replace_recursive([
            'name' => 'Ali Billiard',
            'contactPhone' => '+998901234567',
            'branchLimit' => 2,
            'owner' => ['name' => 'Ali', 'login' => 'Ali.Billiard', 'password' => 'Str0ngPassw0rd'],
            'payment' => ['amount' => 500000, 'method' => 'CASH', 'days' => 30],
        ], $overrides))->assertCreated()->json('data');
    }

    #[Test]
    public function super_admin_creates_a_client_with_owner_and_first_payment(): void
    {
        $data = $this->createClient();

        $this->assertSame('Ali Billiard', $data['name']);
        $this->assertSame('ACTIVE', $data['subscription']['status']);
        $this->assertSame(30, $data['subscription']['daysLeft']);
        $this->assertSame(2, $data['limits']['branchLimit']);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['login' => 'ali.billiard', 'password' => 'Str0ngPassw0rd'])
            ->assertOk()->assertJsonPath('user.role', 'CLIENT_OWNER')->assertJsonPath('tenant.name', 'Ali Billiard');
    }

    #[Test]
    public function a_client_without_payment_starts_expired(): void
    {
        $data = $this->createClient(['payment' => null, 'owner' => ['login' => 'nopay']]);

        $this->assertSame('EXPIRED', $data['subscription']['status']);
    }

    #[Test]
    public function owner_login_must_be_unique_and_password_strong(): void
    {
        $this->createClient();

        $this->actingAs($this->superAdmin())->postJson('/api/super/tenants', [
            'name' => 'X', 'branchLimit' => 1, 'owner' => ['name' => 'X', 'login' => 'ALI.BILLIARD', 'password' => 'weak'],
        ])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['owner.login', 'owner.password']]]);
    }

    #[Test]
    public function suspend_blocks_the_client_and_activate_restores_without_data_loss(): void
    {
        $data = $this->createClient();
        $tenant = $this->asSystem(fn () => Tenant::query()->where('public_id', $data['id'])->firstOrFail());
        $this->branchFor($tenant, ['name' => 'Markaz']);
        $owner = $this->asSystem(fn () => $tenant->users()->firstOrFail());
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson("/api/super/tenants/{$data['id']}/suspend", ['reason' => 'To\'lov yo\'q'])
            ->assertOk()->assertJsonPath('data.subscription.status', 'SUSPENDED');
        $this->actingAs($owner)->getJson('/api/admin/branches')->assertStatus(402);
        $this->actingAs($owner)->getJson('/api/admin/subscription')->assertOk()->assertJsonPath('status', 'SUSPENDED');

        $this->actingAs($admin)->postJson("/api/super/tenants/{$data['id']}/activate")->assertOk()->assertJsonPath('data.subscription.status', 'ACTIVE');
        $this->actingAs($owner)->getJson('/api/admin/branches')->assertOk()->assertJsonPath('data.0.name', 'Markaz');
    }

    #[Test]
    public function deactivated_clients_are_logged_out(): void
    {
        $data = $this->createClient();
        $owner = $this->asSystem(fn () => Tenant::query()->where('public_id', $data['id'])->firstOrFail()->users()->firstOrFail());

        $this->actingAs($this->superAdmin())->postJson("/api/super/tenants/{$data['id']}/deactivate")->assertOk();
        $this->actingAs($owner)->getJson('/api/me')->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_DISABLED');
    }

    #[Test]
    public function list_filters_by_derived_status_and_shows_usage(): void
    {
        $this->asSystem(function (): void {
            Tenant::factory()->create(['name' => 'Active', 'subscription_expires_at' => now()->addDays(20)]);
            Tenant::factory()->create(['name' => 'Soon', 'subscription_expires_at' => now()->addDays(3)]);
            Tenant::factory()->create(['name' => 'Late', 'subscription_expires_at' => now()->subDay()]);
            $s = Tenant::factory()->create(['name' => 'Paused', 'status_flag' => 'SUSPENDED']);
            Branch::factory()->count(2)->create(['tenant_id' => $s->id]);
        });
        $admin = $this->superAdmin();

        foreach (['ACTIVE' => 'Active', 'EXPIRING_SOON' => 'Soon', 'EXPIRED' => 'Late', 'SUSPENDED' => 'Paused'] as $status => $name) {
            $this->actingAs($admin)->getJson("/api/super/tenants?status=$status")->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', $name);
        }
        $this->actingAs($admin)->getJson('/api/super/tenants?q=Pau')->assertJsonPath('data.0.usage.branches', 2)->assertJsonPath('meta.total', 1);
    }

    #[Test]
    public function dashboard_counts_and_revenue(): void
    {
        $this->createClient();
        $this->createClient(['name' => 'B', 'owner' => ['login' => 'b-owner'], 'payment' => ['amount' => 300000]]);

        $this->actingAs($this->superAdmin())->getJson('/api/super/dashboard')->assertOk()
            ->assertJsonPath('tenants.total', 2)
            ->assertJsonPath('tenants.active', 2)
            ->assertJsonPath('revenue.thisMonth', 800000)
            ->assertJsonPath('revenue.total', 800000);
    }

    #[Test]
    public function owner_password_reset_returns_a_temporary_password_once(): void
    {
        $data = $this->createClient();

        $reset = $this->actingAs($this->superAdmin())->postJson("/api/super/tenants/{$data['id']}/owner/reset-password")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['login' => 'ali.billiard', 'password' => $reset->json('temporaryPassword')])->assertOk();
    }

    #[Test]
    public function platform_settings_are_shown_to_inactive_clients(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->putJson('/api/super/settings', [
            'supportContact' => '+998 90 000 00 00', 'paymentInstructions' => 'Karta: 8600 ...', 'unknownKey' => 'x',
        ])->assertOk()->assertJsonPath('supportContact', '+998 90 000 00 00')->assertJsonMissingPath('unknownKey');

        $tenant = $this->asSystem(fn () => Tenant::factory()->expired()->create());
        $this->actingAs($this->tenantUser('CLIENT_OPERATOR', $tenant))->getJson('/api/admin/subscription')->assertOk()
            ->assertJsonPath('status', 'EXPIRED')->assertJsonPath('paymentInstructions', 'Karta: 8600 ...');
    }

    #[Test]
    public function audit_log_lists_platform_actions_with_filters(): void
    {
        $data = $this->createClient();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->getJson('/api/super/audit-logs?action=tenant.')->assertOk()
            ->assertJsonPath('data.0.action', 'tenant.created')->assertJsonPath('data.0.tenant', 'Ali Billiard');
        $this->actingAs($admin)->getJson("/api/super/audit-logs?tenantId={$data['id']}")->assertOk()->assertJsonPath('meta.total', 3); // tenant.created, user.created, subscription.payment_recorded
    }

    #[Test]
    public function client_users_cannot_reach_any_super_admin_endpoint(): void
    {
        $owner = $this->tenantUser();
        $t = $this->asSystem(fn () => Tenant::factory()->create());

        foreach (['get' => ['/api/super/dashboard', '/api/super/tenants', "/api/super/tenants/{$t->public_id}", '/api/super/audit-logs', '/api/super/payments', '/api/super/settings'],
            'post' => ["/api/super/tenants/{$t->public_id}/suspend", "/api/super/tenants/{$t->public_id}/payments"]] as $method => $urls) {
            foreach ($urls as $url) {
                $this->actingAs($owner)->json($method, $url)->assertStatus(403);
            }
        }
    }
}
