<?php

namespace Tests\Feature\Ops;

use App\Domain\Users\Models\User;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** First-deploy helpers (docs/DEPLOY_UZ.md): creating the first Super Admin. */
class FirstDeployTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password-Long-2026';

    private function superAdmins()
    {
        return $this->asSystem(fn () => User::query()->where('role', 'SUPER_ADMIN')->get());
    }

    #[Test]
    public function the_console_command_creates_a_super_admin_with_a_hidden_password(): void
    {
        $this->artisan('admin:create-super', ['login' => 'Bakhrullo'])
            ->expectsQuestion('Password (min 12 characters, hidden)', self::PASSWORD)
            ->expectsQuestion('Repeat the password', self::PASSWORD)
            ->assertSuccessful();

        $admin = $this->superAdmins()->sole();
        $this->assertSame('bakhrullo', $admin->login);
        $this->assertNull($admin->tenant_id);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->postJson('/api/auth/login', ['login' => 'bakhrullo', 'password' => self::PASSWORD])->assertOk()->assertJsonPath('user.role', 'SUPER_ADMIN');
    }

    #[Test]
    public function weak_mismatched_or_duplicate_input_is_refused(): void
    {
        $this->artisan('admin:create-super', ['login' => 'root'])
            ->expectsQuestion('Password (min 12 characters, hidden)', 'short') // refused before the repeat question
            ->assertFailed();
        $this->artisan('admin:create-super', ['login' => 'root'])
            ->expectsQuestion('Password (min 12 characters, hidden)', self::PASSWORD)
            ->expectsQuestion('Repeat the password', strrev(self::PASSWORD))
            ->assertFailed();
        $this->artisan('admin:create-super', ['login' => 'bad login!'])->assertFailed();
        $this->assertCount(0, $this->superAdmins());
    }

    #[Test]
    public function the_seeder_reads_config_so_it_works_with_a_cached_config(): void
    {
        config(['platform.super_admin.login' => 'Owner', 'platform.super_admin.password' => self::PASSWORD.'x']);
        $this->seed(PlatformSeeder::class);
        $this->seed(PlatformSeeder::class); // idempotent

        $this->assertSame(['owner'], $this->superAdmins()->pluck('login')->all());
    }
}
