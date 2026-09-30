<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Branches\Models\Branch;
use App\Domain\Subscriptions\Models\Subscription;
use App\Domain\Subscriptions\Models\SubscriptionPayment;
use App\Domain\Tenancy\Enums\SubscriptionStatus;
use App\Domain\Tenancy\Enums\TenantStatusFlag;
use App\Domain\Users\Models\User;
use App\Support\Concerns\HasPublicId;
use Carbon\CarbonImmutable;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A client (billiard business). Platform-level entity: not tenant-scoped itself.
 *
 * @property int $id
 * @property string $public_id
 * @property TenantStatusFlag $status_flag
 * @property CarbonImmutable|null $subscription_expires_at
 */
#[UseFactory(TenantFactory::class)]
class Tenant extends Model
{
    use HasFactory, HasPublicId;

    /** Days before expiry when the status becomes EXPIRING_SOON (spec §4). */
    public const EXPIRING_SOON_DAYS = 5;

    protected $fillable = [
        'name', 'contact_name', 'contact_phone', 'timezone', 'settings',
    ];

    protected $attributes = [
        'status_flag' => 'ACTIVE',
        'branch_limit' => 1,
        'timezone' => 'Asia/Tashkent',
    ];

    protected function casts(): array
    {
        return [
            'status_flag' => TenantStatusFlag::class,
            'subscription_expires_at' => 'immutable_datetime',
            'branch_limit' => 'integer',
            'table_limit' => 'integer',
            'device_limit' => 'integer',
            'user_limit' => 'integer',
            'settings' => 'array',
        ];
    }

    /** Derived status; order of precedence: DEACTIVATED > SUSPENDED > EXPIRED > EXPIRING_SOON > ACTIVE. */
    public function subscriptionStatus(?CarbonImmutable $now = null): SubscriptionStatus
    {
        $now ??= CarbonImmutable::now();

        return match (true) {
            $this->status_flag === TenantStatusFlag::DEACTIVATED => SubscriptionStatus::DEACTIVATED,
            $this->status_flag === TenantStatusFlag::SUSPENDED => SubscriptionStatus::SUSPENDED,
            $this->subscription_expires_at === null || $this->subscription_expires_at->lessThanOrEqualTo($now) => SubscriptionStatus::EXPIRED,
            $this->subscription_expires_at->lessThanOrEqualTo($now->addDays(self::EXPIRING_SOON_DAYS)) => SubscriptionStatus::EXPIRING_SOON,
            default => SubscriptionStatus::ACTIVE,
        };
    }

    public function hasActiveSubscription(?CarbonImmutable $now = null): bool
    {
        return in_array($this->subscriptionStatus($now), [SubscriptionStatus::ACTIVE, SubscriptionStatus::EXPIRING_SOON], true);
    }

    /** Whole days left (0 when expired or unknown). */
    public function daysLeft(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        if ($this->subscription_expires_at === null || $this->subscription_expires_at->lessThanOrEqualTo($now)) {
            return 0;
        }

        return (int) ceil($now->diffInSeconds($this->subscription_expires_at) / 86400);
    }

    /** @return HasMany<Branch, $this> */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return HasMany<SubscriptionPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }
}
