<?php

namespace App\Domain\Auth\Services;

use App\Domain\Audit\Enums\ActorType;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenancy\Enums\TenantStatusFlag;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Credential check with account lockout: 5 consecutive failures lock the
 * account for 15 minutes (SECURITY.md §1). Per-IP throttling is a separate
 * route rate limiter. Unknown login and wrong password look identical.
 */
final class LoginService
{
    public const MAX_FAILURES = 5;

    public const LOCK_MINUTES = 15;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function attempt(string $login, string $password): User
    {
        $login = strtolower(trim($login));

        return $this->context->runAsSystem(function () use ($login, $password): User {
            /** @var User|null $user */
            $user = User::query()->where('login', $login)->first();

            if ($user === null) {
                Hash::check($password, '$2y$12$'.str_repeat('a', 53)); // equalize timing
                throw ApiException::of(ErrorCode::INVALID_CREDENTIALS);
            }

            if ($user->locked_until !== null && $user->locked_until->isFuture()) {
                throw ApiException::of(ErrorCode::ACCOUNT_LOCKED, ['minutes' => (int) ceil(now()->diffInSeconds($user->locked_until) / 60)]);
            }

            if (! Hash::check($password, $user->password)) {
                $this->registerFailure($user);
                throw ApiException::of(ErrorCode::INVALID_CREDENTIALS);
            }

            if (! $user->is_active || $this->tenantDeactivated($user)) {
                throw ApiException::of(ErrorCode::ACCOUNT_DISABLED);
            }

            $user->forceFill(['failed_logins' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();
            if (Hash::needsRehash($user->password)) {
                $user->forceFill(['password' => $password])->save();
            }

            $this->audit->log('auth.login', $user, [], ['tenant_id' => $user->tenant_id, 'actor_type' => ActorType::USER, 'actor_id' => $user->id]);

            return $user;
        });
    }

    private function registerFailure(User $user): void
    {
        DB::transaction(function () use ($user): void {
            /** @var User $fresh */
            $fresh = User::query()->lockForUpdate()->findOrFail($user->id);
            $failures = $fresh->failed_logins + 1;
            $locked = $failures >= self::MAX_FAILURES;
            $fresh->forceFill([
                'failed_logins' => $locked ? 0 : $failures,
                'locked_until' => $locked ? now()->addMinutes(self::LOCK_MINUTES) : $fresh->locked_until,
            ])->save();

            $this->audit->log($locked ? 'auth.locked' : 'auth.login_failed', $fresh, ['failures' => $failures], [
                'tenant_id' => $fresh->tenant_id, 'actor_type' => ActorType::SYSTEM, 'actor_id' => null,
            ]);
        });
    }

    private function tenantDeactivated(User $user): bool
    {
        if ($user->tenant_id === null) {
            return false;
        }

        return Tenant::query()->whereKey($user->tenant_id)->toBase()->value('status_flag') === TenantStatusFlag::DEACTIVATED->value;
    }
}
