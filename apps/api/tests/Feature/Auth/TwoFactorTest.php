<?php

namespace Tests\Feature\Auth;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Auth\Support\Totp;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** SECURITY.md §1a — optional TOTP second factor. */
class TwoFactorTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private const PASSWORD = 'password-Secret-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(now()->startOfMinute()->addSeconds(5)); // stay inside one 30 s step while a test runs
    }

    private function now(): int
    {
        return now()->getTimestamp();
    }

    /** @return array{0: User, 1: string, 2: list<string>} user, secret, recovery codes */
    private function enabledFor(User $user): array
    {
        $secret = $this->actingAs($user)->postJson('/api/me/2fa/setup', ['password' => self::PASSWORD])->assertOk()->json('secret');
        $codes = $this->postJson('/api/me/2fa/confirm', ['code' => Totp::code($secret, Totp::step($this->now()))])
            ->assertOk()->assertJsonCount(8, 'recoveryCodes')->json('recoveryCodes');
        $this->postJson('/api/auth/logout');
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->travel(31)->seconds(); // the confirm code's step is used up

        return [$user->fresh(), $secret, $codes];
    }

    private function login(string $login, ?string $code = null)
    {
        return $this->postJson('/api/auth/login', array_filter(['login' => $login, 'password' => self::PASSWORD, 'code' => $code]));
    }

    #[Test]
    public function totp_matches_the_rfc_6238_test_vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');

        $this->assertSame('287082', Totp::code($secret, Totp::step(59)));
        $this->assertSame('081804', Totp::code($secret, Totp::step(1111111109)));
        $this->assertSame('050471', Totp::code($secret, Totp::step(1111111111)));
        $this->assertSame('005924', Totp::code($secret, Totp::step(1234567890)));
        $this->assertSame('12345678901234567890', Totp::base32Decode($secret));
        // ±1 step drift accepted, 2 steps rejected, already-used steps rejected.
        $this->assertNotNull(Totp::match($secret, Totp::code($secret, Totp::step(1000) - 1), 1000));
        $this->assertNull(Totp::match($secret, Totp::code($secret, Totp::step(1000) - 2), 1000));
        $this->assertNull(Totp::match($secret, Totp::code($secret, Totp::step(1000)), 1000, Totp::step(1000)));
    }

    #[Test]
    public function setup_needs_the_password_and_is_only_active_after_a_correct_first_code(): void
    {
        $user = $this->tenantUser('CLIENT_OWNER', null, ['login' => 'ali']);

        $this->actingAs($user)->postJson('/api/me/2fa/setup', ['password' => 'wrong'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['password']]]);
        $setup = $this->postJson('/api/me/2fa/setup', ['password' => self::PASSWORD])->assertOk();
        $this->assertStringStartsWith('otpauth://totp/', $setup->json('uri'));
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $setup->json('secret'));

        $this->getJson('/api/me')->assertJsonPath('user.twoFactorEnabled', false);
        $this->postJson('/api/me/2fa/confirm', ['code' => '000000'])->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['code']]]);
        $this->postJson('/api/me/2fa/confirm', ['code' => Totp::code($setup->json('secret'), Totp::step($this->now()))])->assertOk();
        $this->getJson('/api/me')->assertJsonPath('user.twoFactorEnabled', true)->assertJsonMissingPath('user.twoFactorSecret');

        // Secret is encrypted at rest and never returned again.
        $raw = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertNotEmpty($raw);
        $this->assertStringNotContainsString($setup->json('secret'), $raw);
        $this->postJson('/api/me/2fa/setup', ['password' => self::PASSWORD])->assertStatus(409);
        $this->assertTrue($this->asSystem(fn () => AuditLog::query()->where('action', 'auth.2fa_enabled')->exists()));
    }

    #[Test]
    public function login_requires_a_valid_unused_code(): void
    {
        [$user, $secret] = $this->enabledFor($this->tenantUser('CLIENT_OWNER', null, ['login' => 'ali']));

        $this->login('ali')->assertStatus(401)->assertJsonPath('error.code', 'TWO_FACTOR_REQUIRED');
        $this->getJson('/api/me')->assertStatus(401);
        $this->login('ali', '123456')->assertStatus(401)->assertJsonPath('error.code', 'TWO_FACTOR_INVALID');

        $code = Totp::code($secret, Totp::step($this->now()));
        $this->login('ali', $code)->assertOk()->assertJsonPath('user.twoFactorEnabled', true);

        // Replay of the same code (e.g. shoulder-surfed) fails even within its 30 s window.
        $this->postJson('/api/auth/logout');
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->login('ali', $code)->assertStatus(401)->assertJsonPath('error.code', 'TWO_FACTOR_INVALID');
    }

    #[Test]
    public function wrong_codes_count_towards_the_account_lockout(): void
    {
        [, $secret] = $this->enabledFor($this->tenantUser('CLIENT_OWNER', null, ['login' => 'ali']));

        for ($i = 0; $i < 5; $i++) {
            $this->login('ali', '00000'.$i);
        }
        $this->login('ali', Totp::code($secret, Totp::step($this->now())))->assertStatus(423)->assertJsonPath('error.code', 'ACCOUNT_LOCKED');
    }

    #[Test]
    public function a_recovery_code_works_exactly_once(): void
    {
        [, , $codes] = $this->enabledFor($this->tenantUser('CLIENT_OWNER', null, ['login' => 'ali']));

        $this->login('ali', strtolower($codes[0]))->assertOk();
        $this->postJson('/api/auth/logout');
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->login('ali', $codes[0])->assertStatus(401)->assertJsonPath('error.code', 'TWO_FACTOR_INVALID');
        $this->login('ali', $codes[1])->assertOk();
        $this->assertTrue($this->asSystem(fn () => AuditLog::query()->where('action', 'auth.2fa_recovery_used')->exists()));
    }

    #[Test]
    public function disabling_needs_password_and_a_code(): void
    {
        [$user, $secret] = $this->enabledFor($this->tenantUser('CLIENT_OWNER', null, ['login' => 'ali']));
        $code = Totp::code($secret, Totp::step($this->now()));

        $this->actingAs($user)->postJson('/api/me/2fa/disable', ['password' => 'wrong', 'code' => $code])->assertStatus(422);
        $this->postJson('/api/me/2fa/disable', ['password' => self::PASSWORD, 'code' => '111111'])->assertStatus(422);
        $this->postJson('/api/me/2fa/disable', ['password' => self::PASSWORD, 'code' => $code])->assertNoContent();

        $this->assertNull(DB::table('users')->where('id', $user->id)->value('two_factor_secret'));
        $this->app['auth']->forgetGuards();
        $this->login('ali')->assertOk();
    }

    #[Test]
    public function an_administrator_password_reset_switches_2fa_off_for_a_lost_phone(): void
    {
        $owner = $this->tenantUser('CLIENT_OWNER', null, ['login' => 'boss']);
        [$staff] = $this->enabledFor($this->tenantUser('CLIENT_OPERATOR', $owner->tenant, ['login' => 'kassir']));
        [$ownerWith2fa] = $this->enabledFor($owner);

        // Owner resets the operator's password.
        $this->actingAs($ownerWith2fa)->patchJson('/api/admin/users/'.$staff->public_id, ['password' => 'Brand-New-Pass-1'])->assertOk()
            ->assertJsonPath('data.twoFactorEnabled', false);

        // Super Admin resets the owner's password.
        $this->actingAs($this->superAdmin())->postJson('/api/super/tenants/'.$owner->tenant->public_id.'/owner/reset-password')->assertOk();
        $this->assertNull(DB::table('users')->where('id', $owner->id)->value('two_factor_confirmed_at'));
    }

    #[Test]
    public function the_console_command_is_the_last_resort_for_a_super_admin(): void
    {
        [$admin] = $this->enabledFor($this->superAdmin(['login' => 'root.admin']));

        $this->artisan('user:2fa-reset', ['login' => 'root.admin'])->assertSuccessful();
        $this->assertNull(DB::table('users')->where('id', $admin->id)->value('two_factor_confirmed_at'));
        $this->artisan('user:2fa-reset', ['login' => 'nobody'])->assertFailed();
    }
}
