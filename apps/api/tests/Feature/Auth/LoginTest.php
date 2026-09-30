<?php

namespace Tests\Feature\Auth;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const PASSWORD = 'password-Secret-123';

    #[Test]
    public function a_user_logs_in_and_gets_profile_permissions_and_subscription(): void
    {
        $user = $this->tenantUser('CLIENT_MANAGER', null, ['login' => 'ali']);

        $this->postJson('/api/auth/login', ['login' => 'ALI ', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('user.id', $user->public_id)
            ->assertJsonPath('user.role', 'CLIENT_MANAGER')
            ->assertJsonPath('tenant.subscription.status', 'ACTIVE')
            ->assertJsonMissingPath('user.password');

        $this->getJson('/api/me')->assertOk()->assertJsonFragment(['reports.view'])->assertJsonMissing(['branches.manage']);
        $this->assertTrue($this->asSystem(fn () => AuditLog::query()->where('action', 'auth.login')->exists()));
    }

    #[Test]
    public function wrong_password_and_unknown_login_look_the_same(): void
    {
        $this->tenantUser('CLIENT_OWNER', null, ['login' => 'ali']);

        $wrong = $this->postJson('/api/auth/login', ['login' => 'ali', 'password' => 'nope'])->assertStatus(401);
        $unknown = $this->postJson('/api/auth/login', ['login' => 'nobody', 'password' => 'nope'])->assertStatus(401);

        $this->assertSame('INVALID_CREDENTIALS', $wrong->json('error.code'));
        $this->assertSame($wrong->json('error.message'), $unknown->json('error.message'));
    }

    #[Test]
    public function five_failures_lock_the_account_even_for_the_right_password(): void
    {
        $this->tenantUser('CLIENT_OWNER', null, ['login' => 'ali']);

        $this->withoutMiddleware(ThrottleRequests::class); // isolate the account lockout from the IP throttle
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['login' => 'ali', 'password' => 'wrong-'.$i])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', ['login' => 'ali', 'password' => self::PASSWORD])
            ->assertStatus(423)->assertJsonPath('error.code', 'ACCOUNT_LOCKED');

        $this->travel(16)->minutes();
        $this->postJson('/api/auth/login', ['login' => 'ali', 'password' => self::PASSWORD])->assertOk();
    }

    #[Test]
    public function login_is_throttled_per_ip_and_login(): void
    {
        $this->tenantUser('CLIENT_OWNER', null, ['login' => 'ali']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['login' => 'ali', 'password' => 'x']);
        }
        $this->postJson('/api/auth/login', ['login' => 'ali', 'password' => 'x'])
            ->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    #[Test]
    public function inactive_users_and_deactivated_tenants_cannot_log_in(): void
    {
        $this->tenantUser('CLIENT_OWNER', null, ['login' => 'off', 'is_active' => false]);
        $this->postJson('/api/auth/login', ['login' => 'off', 'password' => self::PASSWORD])->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_DISABLED');

        $tenant = $this->asSystem(fn () => Tenant::factory()->create(['status_flag' => 'DEACTIVATED']));
        $this->tenantUser('CLIENT_OWNER', $tenant, ['login' => 'gone']);
        $this->postJson('/api/auth/login', ['login' => 'gone', 'password' => self::PASSWORD])->assertStatus(403);
    }

    #[Test]
    public function expired_tenants_can_still_log_in_and_see_their_status(): void
    {
        $tenant = $this->asSystem(fn () => Tenant::factory()->expired()->create());
        $this->tenantUser('CLIENT_OWNER', $tenant, ['login' => 'late']);

        $this->postJson('/api/auth/login', ['login' => 'late', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('tenant.subscription.status', 'EXPIRED')->assertJsonPath('tenant.subscription.daysLeft', 0);
    }

    #[Test]
    public function a_user_deactivated_after_login_is_logged_out_on_the_next_request(): void
    {
        $user = $this->tenantUser();
        $this->actingAs($user)->getJson('/api/me')->assertOk();

        $this->asSystem(fn () => $user->forceFill(['is_active' => false])->save());
        $this->actingAs($user->fresh())->getJson('/api/me')->assertStatus(403)->assertJsonPath('error.code', 'ACCOUNT_DISABLED');
    }

    #[Test]
    public function logout_ends_the_session(): void
    {
        $this->tenantUser('CLIENT_OWNER', null, ['login' => 'ali']);
        $this->postJson('/api/auth/login', ['login' => 'ali', 'password' => self::PASSWORD])->assertOk();

        $this->postJson('/api/auth/logout')->assertNoContent();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/me')->assertStatus(401)->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    #[Test]
    public function password_change_requires_the_current_password(): void
    {
        $user = $this->tenantUser();

        $this->actingAs($user)->putJson('/api/me/password', ['currentPassword' => 'bad', 'password' => 'NewPassword123'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['currentPassword']]]);
        $this->actingAs($user)->putJson('/api/me/password', ['currentPassword' => self::PASSWORD, 'password' => 'short'])
            ->assertStatus(422);
        $this->actingAs($user)->putJson('/api/me/password', ['currentPassword' => self::PASSWORD, 'password' => 'NewPassword123'])
            ->assertNoContent();
    }
}
