<?php

namespace App\Domain\Tablets\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Branches\Models\Branch;
use App\Domain\Pairing\PairingCodes;
use App\Domain\Tablets\Enums\TabletStatus;
use App\Domain\Tablets\Models\Tablet;
use App\Domain\Tablets\Models\TabletPairing;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use App\Support\Http\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Kiosk tablet pairing (spec §37): the tablet shows a code, a client admin
 * enters it with a branch. The tablet can never choose its tenant/branch.
 * Each registration is a new row; the bearer token is delivered exactly once.
 */
final class TabletRegistry
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function register(string $appVersion, ?string $deviceModel, string $ip): array
    {
        return $this->context->runAsSystem(fn () => DB::transaction(function () use ($appVersion, $deviceModel, $ip): array {
            $tablet = new Tablet(['app_version' => $appVersion, 'device_model' => $deviceModel]);
            $tablet->forceFill([
                'device_code' => $this->uniqueCode(),
                'status' => TabletStatus::UNPAIRED,
                'registered_at' => now(),
                'last_ip' => $ip,
                'last_seen_at' => now(),
            ])->save();

            $code = PairingCodes::fresh();
            $pollToken = PairingCodes::secret();
            $pairing = new TabletPairing;
            $pairing->forceFill([
                'tablet_id' => $tablet->id,
                'code_hash' => $code['hash'],
                'poll_token_hash' => hash('sha256', $pollToken),
                'expires_at' => now()->addSeconds(PairingCodes::TTL_SEC),
            ])->save();

            return [
                'tabletCode' => $tablet->device_code,
                'pairingCode' => $code['code'],
                'pairingExpiresAt' => $pairing->expires_at->toIso8601ZuluString(),
                'pollToken' => $pollToken,
                'serverTime' => now()->toIso8601ZuluString(),
            ];
        }));
    }

    public function pairingStatus(string $pollToken): array
    {
        return $this->context->runAsSystem(fn () => DB::transaction(function () use ($pollToken): array {
            /** @var TabletPairing|null $pairing */
            $pairing = TabletPairing::query()->where('poll_token_hash', hash('sha256', $pollToken))->lockForUpdate()->first();
            if ($pairing === null) {
                throw ApiException::of(ErrorCode::UNAUTHENTICATED);
            }
            $out = ['serverTime' => now()->toIso8601ZuluString()];
            if ($pairing->used_at === null) {
                return $out + ['status' => $pairing->expires_at->isPast() ? 'EXPIRED' : 'WAITING'];
            }
            if ($pairing->token_delivered_at !== null) {
                return $out + ['status' => 'PAIRED'];
            }
            $token = PairingCodes::secret();
            Tablet::query()->whereKey($pairing->tablet_id)->update(['token_hash' => hash('sha256', $token)]);
            $pairing->forceFill(['token_delivered_at' => now()])->save();

            return $out + ['status' => 'PAIRED', 'token' => $token];
        }));
    }

    public function pair(User $admin, string $code, Branch $branch, ?string $name): Tablet
    {
        $tenantId = $this->context->requireTenantId();

        return DB::transaction(function () use ($admin, $code, $branch, $name, $tenantId): Tablet {
            $pairing = $this->context->runAsSystem(fn () => TabletPairing::query()
                ->where('code_hash', PairingCodes::hash($code))->whereNull('used_at')->lockForUpdate()->first());
            if ($pairing === null) {
                throw ApiException::of(ErrorCode::PAIRING_CODE_INVALID);
            }
            if ($pairing->expires_at->isPast()) {
                throw ApiException::of(ErrorCode::PAIRING_CODE_EXPIRED);
            }
            /** @var Tablet $tablet */
            $tablet = $this->context->runAsSystem(fn () => Tablet::query()->lockForUpdate()->findOrFail($pairing->tablet_id));
            if ($tablet->status !== TabletStatus::UNPAIRED) {
                throw ApiException::of(ErrorCode::PAIRING_CODE_INVALID);
            }
            $this->context->runAsSystem(function () use ($tablet, $pairing, $branch, $admin, $name, $tenantId): void {
                $tablet->forceFill([
                    'tenant_id' => $tenantId, 'branch_id' => $branch->id, 'name' => $name ?: $tablet->device_code,
                    'status' => TabletStatus::PAIRED, 'paired_at' => now(), 'paired_by' => $admin->id,
                ])->save();
                $pairing->forceFill(['used_at' => now(), 'used_by' => $admin->id, 'tenant_id' => $tenantId])->save();
            });
            $this->audit->log('tablet.paired', $tablet, ['code' => $tablet->device_code, 'branch' => $branch->public_id]);

            return $tablet;
        });
    }

    public function revoke(User $admin, Tablet $tablet): Tablet
    {
        $tablet->forceFill(['status' => TabletStatus::REVOKED, 'token_hash' => null, 'revoked_at' => now(), 'revoked_by' => $admin->id])->save();
        $this->audit->log('tablet.revoked', $tablet, ['code' => $tablet->device_code]);

        return $tablet;
    }

    private function uniqueCode(): string
    {
        do {
            $code = 'TABLET-'.strtoupper(Str::random(6));
        } while (Tablet::query()->where('device_code', $code)->exists() || ! preg_match('/^TABLET-[0-9A-Z]{6}$/', $code));

        return $code;
    }
}
