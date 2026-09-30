<?php

namespace App\Console\Commands;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Auth\Services\TwoFactorService;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use Illuminate\Console\Command;

/** Last-resort recovery when a Super Admin lost both the phone and the recovery codes (cPanel → Terminal). */
final class ResetTwoFactor extends Command
{
    protected $signature = 'user:2fa-reset {login}';

    protected $description = 'Switch off two-factor login for one user';

    public function handle(TenantContext $context, AuditLogger $audit): int
    {
        return $context->runAsSystem(function () use ($audit): int {
            $user = User::query()->where('login', strtolower(trim((string) $this->argument('login'))))->first();
            if ($user === null) {
                $this->error('User not found.');

                return self::FAILURE;
            }
            $user->forceFill(TwoFactorService::cleared())->save();
            $audit->log('auth.2fa_reset', $user, ['via' => 'console'], ['tenant_id' => $user->tenant_id, 'actor_type' => ActorType::SYSTEM, 'actor_id' => null]);
            $this->info("Two-factor login is off for {$user->login}. Sign in with the password and enable it again.");

            return self::SUCCESS;
        });
    }
}
