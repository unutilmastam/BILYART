<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Auth\Services\TwoFactorService;
use App\Domain\Subscriptions\Enums\PaymentMethod;
use App\Domain\Subscriptions\Enums\SubscriptionEventType;
use App\Domain\Subscriptions\Models\SubscriptionEvent;
use App\Domain\Subscriptions\Services\SubscriptionService;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Enums\Role;
use App\Domain\Users\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Client lifecycle owned by the Super Admin (spec §3). */
final class TenantService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SubscriptionService $subscriptions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, contact_name?: ?string, contact_phone?: ?string, timezone?: string, branch_limit: int, table_limit?: ?int, device_limit?: ?int, user_limit?: ?int}  $data
     * @param  array{name: string, login: string, password: string}  $owner
     * @param  array{amount: int, method: PaymentMethod, days: int, note?: ?string}|null  $payment
     */
    public function create(array $data, array $owner, ?array $payment, User $actor): Tenant
    {
        return $this->context->runAsSystem(fn () => DB::transaction(function () use ($data, $owner, $payment, $actor): Tenant {
            $tenant = new Tenant(array_intersect_key($data, array_flip(['name', 'contact_name', 'contact_phone', 'timezone'])));
            $tenant->forceFill(array_intersect_key($data, array_flip(['branch_limit', 'table_limit', 'device_limit', 'user_limit'])));
            $tenant->save();

            $user = new User(['name' => $owner['name'], 'login' => strtolower(trim($owner['login'])), 'password' => $owner['password']]);
            $user->role = Role::CLIENT_OWNER;
            $user->tenant_id = $tenant->id;
            $user->save();

            $event = new SubscriptionEvent(['type' => SubscriptionEventType::CREATED, 'new_value' => ['name' => $tenant->name, 'branchLimit' => $tenant->branch_limit], 'actor_user_id' => $actor->id]);
            $event->tenant_id = $tenant->id;
            $event->save();

            $this->audit->log('tenant.created', $tenant, ['name' => $tenant->name, 'ownerLogin' => $user->login], ['tenant_id' => $tenant->id]);
            $this->audit->log('user.created', $user, ['role' => $user->role->value], ['tenant_id' => $tenant->id]);

            if ($payment !== null) {
                $this->subscriptions->recordPayment($tenant, $payment['amount'], $payment['method'], $payment['days'], $actor, null, $payment['note'] ?? null);
            }

            return $tenant->refresh();
        }));
    }

    /** Issues a one-time temporary password for the client owner (shown once to the Super Admin). */
    public function resetOwnerPassword(Tenant $tenant, User $actor): array
    {
        return $this->context->runAsSystem(function () use ($tenant): array {
            /** @var User $owner */
            $owner = User::query()->where('tenant_id', $tenant->id)->where('role', Role::CLIENT_OWNER->value)->orderBy('id')->firstOrFail();
            $password = Str::password(14, symbols: false);
            // A lost phone is the usual reason for a reset, so the second factor is switched off too.
            $owner->forceFill(['password' => $password, 'failed_logins' => 0, 'locked_until' => null] + TwoFactorService::cleared())->save();
            $this->audit->log('user.password_reset', $owner, [], ['tenant_id' => $tenant->id]);

            return ['login' => $owner->login, 'temporaryPassword' => $password];
        });
    }
}
