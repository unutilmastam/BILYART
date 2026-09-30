<?php

namespace App\Domain\Auth\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Auth\Support\Totp;
use App\Domain\Users\Models\User;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Optional TOTP second factor for any admin user (SECURITY.md §1a).
 * Setup is two-step (secret shown → first code confirms it); a code is valid
 * once (last used step is stored); 8 single-use recovery codes are shown once.
 */
final class TwoFactorService
{
    public const RECOVERY_CODES = 8;

    public function __construct(private readonly AuditLogger $audit) {}

    public function enabled(User $user): bool
    {
        return $user->two_factor_confirmed_at !== null;
    }

    /** @return array{secret: string, uri: string} */
    public function begin(User $user, string $password): array
    {
        $this->assertPassword($user, $password);
        if ($this->enabled($user)) {
            throw ApiException::of(ErrorCode::CONFLICT);
        }
        $secret = Totp::newSecret();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_last_step' => null, 'two_factor_recovery_codes' => null])->save();

        return ['secret' => $secret, 'uri' => Totp::uri($secret, $user->login, (string) config('app.name'))];
    }

    /** @return list<string> recovery codes, shown once */
    public function confirm(User $user, string $code): array
    {
        if ($this->enabled($user) || $user->two_factor_secret === null) {
            throw ApiException::of(ErrorCode::CONFLICT);
        }
        $step = Totp::match($user->two_factor_secret, $code, now()->getTimestamp());
        if ($step === null) {
            throw $this->invalidCodeField();
        }
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = Totp::base32Encode(random_bytes(7));
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5, 5);
        }
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $step,
            'two_factor_recovery_codes' => array_map(fn ($c) => $this->hashRecovery($c), $codes),
        ])->save();
        $this->audit->log('auth.2fa_enabled', $user);

        return $codes;
    }

    public function disable(User $user, string $password, string $code): void
    {
        $this->assertPassword($user, $password);
        if (! $this->enabled($user)) {
            throw ApiException::of(ErrorCode::CONFLICT);
        }
        if (! $this->consume($user, $code)) {
            throw $this->invalidCodeField();
        }
        $user->forceFill(self::cleared())->save();
        $this->audit->log('auth.2fa_disabled', $user);
    }

    /** Checks a TOTP or recovery code and marks it used. Safe against concurrent reuse (row lock). */
    public function consume(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            /** @var User $fresh */
            $fresh = User::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($user->id);
            if ($fresh->two_factor_confirmed_at === null || $fresh->two_factor_secret === null) {
                return false;
            }
            $code = trim($code);
            $step = Totp::match($fresh->two_factor_secret, $code, now()->getTimestamp(), $fresh->two_factor_last_step);
            if ($step !== null) {
                $fresh->forceFill(['two_factor_last_step' => $step])->save();
                $user->setRawAttributes($fresh->getAttributes(), true);

                return true;
            }
            $hash = $this->hashRecovery($code);
            $remaining = $fresh->two_factor_recovery_codes ?? [];
            $index = array_search($hash, $remaining, true);
            if ($index === false) {
                return false;
            }
            unset($remaining[$index]);
            $fresh->forceFill(['two_factor_recovery_codes' => array_values($remaining)])->save();
            $user->setRawAttributes($fresh->getAttributes(), true);
            $this->audit->log('auth.2fa_recovery_used', $fresh, ['remaining' => count($remaining)], ['tenant_id' => $fresh->tenant_id]);

            return true;
        });
    }

    /** Attributes that switch 2FA off (also used by password resets done by an administrator). */
    public static function cleared(): array
    {
        return ['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null, 'two_factor_recovery_codes' => null];
    }

    private function hashRecovery(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $code)));
    }

    private function assertPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['password' => [__('auth.password')]]]);
        }
    }

    private function invalidCodeField(): ApiException
    {
        return new ApiException(ErrorCode::VALIDATION_FAILED, [], ['fields' => ['code' => [__('errors.TWO_FACTOR_INVALID')]]]);
    }
}
