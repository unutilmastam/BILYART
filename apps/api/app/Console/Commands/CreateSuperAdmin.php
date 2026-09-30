<?php

namespace App\Console\Commands;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\User;
use Illuminate\Console\Command;

/** First deploy (cPanel → Terminal): creates a Super Admin; the password is typed hidden and never stored in .env. */
final class CreateSuperAdmin extends Command
{
    protected $signature = 'admin:create-super {login : Login, e.g. bakhrullo}';

    protected $description = 'Create a Super Admin account (password asked interactively)';

    public function handle(TenantContext $context, AuditLogger $audit): int
    {
        $login = strtolower(trim((string) $this->argument('login')));
        if (! preg_match('/^[a-z0-9._-]{3,64}$/', $login)) {
            $this->error('Login: 3–64 characters, a-z 0-9 . _ -');

            return self::FAILURE;
        }
        $password = (string) $this->secret('Password (min 12 characters, hidden)');
        if (strlen($password) < 12 || $password !== (string) $this->secret('Repeat the password')) {
            $this->error('Passwords are too short or do not match.');

            return self::FAILURE;
        }

        return $context->runAsSystem(function () use ($login, $password, $audit): int {
            if (User::query()->where('login', $login)->exists()) {
                $this->error("Login {$login} already exists.");

                return self::FAILURE;
            }
            $user = new User(['name' => 'Super Admin', 'login' => $login, 'password' => $password]);
            $user->role = Role::SUPER_ADMIN;
            $user->save();
            $audit->log('user.created', $user, ['role' => 'SUPER_ADMIN', 'via' => 'console'], ['tenant_id' => null, 'actor_type' => ActorType::SYSTEM, 'actor_id' => null]);
            $this->info("Super Admin {$login} created. Sign in at /admin and turn on two-factor login (Hisobim).");

            return self::SUCCESS;
        });
    }
}
