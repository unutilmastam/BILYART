<?php

namespace Tests\Unit\Tenancy;

use App\Domain\Tenancy\Enums\SubscriptionStatus;
use App\Domain\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SubscriptionStatusTest extends TestCase
{
    private function tenant(?string $expiresAt, string $flag = 'ACTIVE'): Tenant
    {
        $tenant = new Tenant;
        $tenant->forceFill(['status_flag' => $flag, 'subscription_expires_at' => $expiresAt]);

        return $tenant;
    }

    #[Test]
    public function status_is_derived_from_flag_and_expiry(): void
    {
        $now = CarbonImmutable::parse('2026-10-01 12:00:00');

        $this->assertSame(SubscriptionStatus::ACTIVE, $this->tenant('2026-10-31 12:00:00')->subscriptionStatus($now));
        $this->assertSame(SubscriptionStatus::EXPIRING_SOON, $this->tenant('2026-10-06 12:00:00')->subscriptionStatus($now));
        $this->assertSame(SubscriptionStatus::EXPIRING_SOON, $this->tenant('2026-10-01 12:00:01')->subscriptionStatus($now));
        $this->assertSame(SubscriptionStatus::EXPIRED, $this->tenant('2026-10-01 12:00:00')->subscriptionStatus($now));
        $this->assertSame(SubscriptionStatus::EXPIRED, $this->tenant(null)->subscriptionStatus($now));
        $this->assertSame(SubscriptionStatus::SUSPENDED, $this->tenant('2026-10-31 12:00:00', 'SUSPENDED')->subscriptionStatus($now));
        $this->assertSame(SubscriptionStatus::DEACTIVATED, $this->tenant('2026-10-31 12:00:00', 'DEACTIVATED')->subscriptionStatus($now));
    }

    #[Test]
    public function days_left_rounds_up_and_never_goes_negative(): void
    {
        $now = CarbonImmutable::parse('2026-10-01 12:00:00');

        $this->assertSame(30, $this->tenant('2026-10-31 12:00:00')->daysLeft($now));
        $this->assertSame(1, $this->tenant('2026-10-01 18:00:00')->daysLeft($now));
        $this->assertSame(0, $this->tenant('2026-09-30 12:00:00')->daysLeft($now));
        $this->assertTrue($this->tenant('2026-10-03 12:00:00')->hasActiveSubscription($now));
        $this->assertFalse($this->tenant('2026-09-30 12:00:00')->hasActiveSubscription($now));
    }
}
