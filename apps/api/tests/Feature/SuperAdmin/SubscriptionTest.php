<?php

namespace Tests\Feature\SuperAdmin;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Subscriptions\Models\SubscriptionEvent;
use App\Domain\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Spec §4: payments, both extension rules, history; §5 limits. */
class SubscriptionTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    private function tenant(array $attrs = []): Tenant
    {
        return $this->asSystem(fn () => Tenant::factory()->create($attrs));
    }

    #[Test]
    public function payment_while_active_extends_from_the_current_expiry(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:00:00'));
        $tenant = $this->tenant(['subscription_expires_at' => '2026-10-31 00:00:00']);

        $this->actingAs($this->superAdmin())->postJson("/api/super/tenants/{$tenant->public_id}/payments", [
            'amount' => 500000, 'method' => 'CASH', 'days' => 30,
        ])->assertCreated()->assertJsonPath('data.subscription.expiresAt', '2026-11-30T00:00:00Z');
    }

    #[Test]
    public function payment_after_expiry_extends_from_today(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00:00'));
        $tenant = $this->tenant(['subscription_expires_at' => '2026-10-31 00:00:00']);

        $this->actingAs($this->superAdmin())->postJson("/api/super/tenants/{$tenant->public_id}/payments", [
            'amount' => 500000, 'method' => 'BANK_TRANSFER', 'days' => 30, 'note' => 'Oktabr',
        ])->assertCreated()
            ->assertJsonPath('data.subscription.expiresAt', '2026-12-05T10:00:00Z')
            ->assertJsonPath('data.subscription.status', 'ACTIVE');

        $history = $this->actingAs($this->superAdmin())->getJson("/api/super/tenants/{$tenant->public_id}/subscription")->assertOk();
        $history->assertJsonPath('payments.0.amount', 500000)
            ->assertJsonPath('payments.0.method', 'BANK_TRANSFER')
            ->assertJsonPath('periods.0.startsAt', '2026-11-05T10:00:00Z')
            ->assertJsonPath('events.0.type', 'ACTIVATED');
    }

    #[Test]
    public function manual_extension_and_setting_expiry_are_recorded(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 00:00:00'));
        $tenant = $this->tenant(['subscription_expires_at' => '2026-10-10 00:00:00']);
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson("/api/super/tenants/{$tenant->public_id}/extend", ['days' => 5, 'reason' => 'Kompensatsiya'])
            ->assertOk()->assertJsonPath('data.subscription.expiresAt', '2026-10-15T00:00:00Z');
        $this->actingAs($admin)->putJson("/api/super/tenants/{$tenant->public_id}/expiry", ['expiresAt' => '2026-12-31T23:59:59Z', 'reason' => 'Yillik'])
            ->assertOk()->assertJsonPath('data.subscription.expiresAt', '2026-12-31T23:59:59Z');

        $types = $this->asSystem(fn () => SubscriptionEvent::query()->where('tenant_id', $tenant->id)->orderBy('id')->pluck('type')->map->value->all());
        $this->assertSame(['EXTENDED', 'EXPIRY_SET'], $types);
    }

    #[Test]
    public function limit_change_is_audited_with_old_and_new_values(): void
    {
        $tenant = $this->tenant(['branch_limit' => 2]);

        $this->actingAs($this->superAdmin())->putJson("/api/super/tenants/{$tenant->public_id}/limits", [
            'branchLimit' => 3, 'tableLimit' => 20, 'deviceLimit' => null, 'userLimit' => null,
        ])->assertOk()->assertJsonPath('data.limits.branchLimit', 3)->assertJsonPath('data.limits.tableLimit', 20);

        $event = $this->asSystem(fn () => SubscriptionEvent::query()->where('tenant_id', $tenant->id)->where('type', 'LIMIT_CHANGED')->firstOrFail());
        $this->assertSame(2, $event->old_value['branch_limit']);
        $this->assertSame(3, $event->new_value['branch_limit']);
        $audit = $this->asSystem(fn () => AuditLog::query()->where('action', 'tenant.limits_changed')->firstOrFail());
        $this->assertSame($tenant->id, $audit->tenant_id);
        $this->assertSame(3, $audit->metadata['to']['branch_limit']);
    }

    #[Test]
    public function payments_must_be_integer_uzs_with_a_known_method(): void
    {
        $tenant = $this->tenant();
        $admin = $this->superAdmin();

        $this->actingAs($admin)->postJson("/api/super/tenants/{$tenant->public_id}/payments", ['amount' => 100.5, 'method' => 'CASH', 'days' => 30])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['amount']]]);
        $this->actingAs($admin)->postJson("/api/super/tenants/{$tenant->public_id}/payments", ['amount' => 1000, 'method' => 'CRYPTO', 'days' => 30])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['method']]]);
        $this->actingAs($admin)->postJson("/api/super/tenants/{$tenant->public_id}/payments", ['amount' => 1000, 'method' => 'CASH', 'days' => 0])
            ->assertStatus(422);
    }
}
