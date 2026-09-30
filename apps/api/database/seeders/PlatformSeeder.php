<?php

namespace Database\Seeders;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/** Creates the first Super Admin from SUPER_ADMIN_LOGIN / SUPER_ADMIN_PASSWORD (first deploy only; idempotent). */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        $login = strtolower(trim((string) env('SUPER_ADMIN_LOGIN', '')));
        $password = (string) env('SUPER_ADMIN_PASSWORD', '');

        if ($login === '' || strlen($password) < 12) {
            throw new RuntimeException('Set SUPER_ADMIN_LOGIN and SUPER_ADMIN_PASSWORD (min 12 chars) in .env before seeding.');
        }

        app(TenantContext::class)->runAsSystem(function () use ($login, $password): void {
            if (User::query()->where('login', $login)->exists()) {
                return;
            }
            $user = new User(['name' => 'Super Admin', 'login' => $login, 'password' => $password]);
            $user->role = Role::SUPER_ADMIN;
            $user->save();
        });
    }
}
