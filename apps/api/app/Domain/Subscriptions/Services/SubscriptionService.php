<?php

namespace App\Domain\Subscriptions\Services;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Subscriptions\Enums\PaymentMethod;
use App\Domain\Subscriptions\Enums\SubscriptionEventType;
use App\Domain\Subscriptions\Enums\SubscriptionSource;
use App\Domain\Subscriptions\Models\Subscription;
use App\Domain\Subscriptions\Models\SubscriptionEvent;
use App\Domain\Subscriptions\Models\SubscriptionPayment;
use App\Domain\Tenancy\Enums\TenantStatusFlag;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Users\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Platform licensing (spec §3–§5). All operations lock the tenant row, write a
 * subscription history row/event and an audit entry. Data is never deleted.
 */
final class SubscriptionService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Records a manual payment and extends the subscription by $days.
     * Base = max(now, current expiry): extends from the current expiry while active,
     * from today when already expired (spec §4, both cases).
     */
    public function recordPayment(
        Tenant $tenant,
        int $amount,
        PaymentMethod $method,
        int $days,
        User $actor,
        ?CarbonImmutable $paidAt = null,
        ?string $note = null,
    ): Subscription {
        return $this->locked($tenant, function (Tenant $tenant) use ($amount, $method, $days, $actor, $paidAt, $note): Subscription {
            $payment = new SubscriptionPayment(['amount' => $amount, 'method' => $method, 'note' => $note, 'paid_at' => $paidAt ?? now(), 'recorded_by' => $actor->id]);
            $payment->tenant_id = $tenant->id;
            $payment->save();

            $subscription = $this->addPeriod($tenant, $days, SubscriptionSource::PAYMENT, $actor, $payment->id, $note);
            $this->audit->log('subscription.payment_recorded', $payment, [
                'amount' => $amount, 'method' => $method->value, 'days' => $days,
                'expiresAt' => $subscription->expires_at->toIso8601ZuluString(),
            ], ['tenant_id' => $tenant->id]);

            return $subscription;
        });
    }

    /** Manual extension without a payment (e.g. compensation). */
    public function extend(Tenant $tenant, int $days, User $actor, string $reason): Subscription
    {
        return $this->locked($tenant, function (Tenant $tenant) use ($days, $actor, $reason): Subscription {
            $subscription = $this->addPeriod($tenant, $days, SubscriptionSource::MANUAL_ADJUST, $actor, null, $reason);
            $this->audit->log('subscription.extended', $tenant, ['days' => $days, 'reason' => $reason, 'expiresAt' => $subscription->expires_at->toIso8601ZuluString()], ['tenant_id' => $tenant->id]);

            return $subscription;
        });
    }

    /** Sets the expiry date directly (spec §3 "set subscription expiration"). */
    public function setExpiry(Tenant $tenant, CarbonImmutable $expiresAt, User $actor, string $reason): Tenant
    {
        return $this->locked($tenant, function (Tenant $tenant) use ($expiresAt, $actor, $reason): Tenant {
            $old = $tenant->subscription_expires_at;
            $tenant->forceFill(['subscription_expires_at' => $expiresAt])->save();
            $this->event($tenant, SubscriptionEventType::EXPIRY_SET, ['expiresAt' => $old?->toIso8601ZuluString()], ['expiresAt' => $expiresAt->toIso8601ZuluString(), 'reason' => $reason], $actor);
            $this->audit->log('subscription.expiry_set', $tenant, ['from' => $old?->toIso8601ZuluString(), 'to' => $expiresAt->toIso8601ZuluString(), 'reason' => $reason], ['tenant_id' => $tenant->id]);

            return $tenant;
        });
    }

    public function setStatus(Tenant $tenant, TenantStatusFlag $flag, User $actor, ?string $reason = null): Tenant
    {
        return $this->locked($tenant, function (Tenant $tenant) use ($flag, $actor, $reason): Tenant {
            $old = $tenant->status_flag;
            if ($old === $flag) {
                return $tenant;
            }
            $tenant->forceFill(['status_flag' => $flag])->save();

            $type = match ($flag) {
                TenantStatusFlag::SUSPENDED => SubscriptionEventType::SUSPENDED,
                TenantStatusFlag::DEACTIVATED => SubscriptionEventType::DEACTIVATED,
                TenantStatusFlag::ACTIVE => SubscriptionEventType::RESUMED,
            };
            $this->event($tenant, $type, ['status' => $old->value], ['status' => $flag->value, 'reason' => $reason], $actor);
            $this->audit->log('tenant.'.strtolower($type->value), $tenant, ['from' => $old->value, 'to' => $flag->value, 'reason' => $reason], ['tenant_id' => $tenant->id]);

            return $tenant;
        });
    }

    /** @param array{branch_limit: int, table_limit: ?int, device_limit: ?int, user_limit: ?int} $limits */
    public function setLimits(Tenant $tenant, array $limits, User $actor): Tenant
    {
        return $this->locked($tenant, function (Tenant $tenant) use ($limits, $actor): Tenant {
            $keys = ['branch_limit', 'table_limit', 'device_limit', 'user_limit'];
            $old = array_intersect_key($tenant->only($keys), array_flip($keys));
            $new = array_intersect_key($limits, array_flip($keys));
            if ($old == array_merge($old, $new)) {
                return $tenant;
            }
            $tenant->forceFill($new)->save();
            $this->event($tenant, SubscriptionEventType::LIMIT_CHANGED, $old, $tenant->only($keys), $actor);
            $this->audit->log('tenant.limits_changed', $tenant, ['from' => $old, 'to' => $tenant->only($keys)], ['tenant_id' => $tenant->id]);

            return $tenant;
        });
    }

    private function addPeriod(Tenant $tenant, int $days, SubscriptionSource $source, User $actor, ?int $paymentId, ?string $reason): Subscription
    {
        $now = CarbonImmutable::now();
        $wasActive = $tenant->subscription_expires_at !== null && $tenant->subscription_expires_at->greaterThan($now);
        $base = $wasActive ? $tenant->subscription_expires_at : $now;
        $expiresAt = $base->addDays($days);

        $subscription = new Subscription([
            'starts_at' => $base, 'expires_at' => $expiresAt, 'days' => $days, 'source' => $source,
            'payment_id' => $paymentId, 'reason' => $reason, 'created_by' => $actor->id,
        ]);
        $subscription->tenant_id = $tenant->id;
        $subscription->save();

        $old = $tenant->subscription_expires_at;
        $tenant->forceFill(['subscription_expires_at' => $expiresAt])->save();

        $firstEver = ! Subscription::query()->where('tenant_id', $tenant->id)->whereKeyNot($subscription->id)->exists();
        $this->event(
            $tenant,
            $wasActive ? SubscriptionEventType::EXTENDED : SubscriptionEventType::ACTIVATED,
            ['expiresAt' => $old?->toIso8601ZuluString()],
            ['expiresAt' => $expiresAt->toIso8601ZuluString(), 'days' => $days, 'source' => $source->value, 'firstEver' => $firstEver],
            $actor,
        );

        return $subscription;
    }

    private function event(Tenant $tenant, SubscriptionEventType $type, ?array $old, ?array $new, ?User $actor): void
    {
        $event = new SubscriptionEvent(['type' => $type, 'old_value' => $old, 'new_value' => $new, 'actor_user_id' => $actor?->id]);
        $event->tenant_id = $tenant->id;
        $event->save();
    }

    /**
     * @template T
     *
     * @param  \Closure(Tenant): T  $callback
     * @return T
     */
    private function locked(Tenant $tenant, \Closure $callback): mixed
    {
        return $this->context->runAsSystem(fn () => DB::transaction(function () use ($tenant, $callback) {
            /** @var Tenant $fresh */
            $fresh = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $result = $callback($fresh);
            $tenant->setRawAttributes($fresh->getAttributes(), true);

            return $result;
        }));
    }
}
