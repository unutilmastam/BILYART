<?php

namespace Tests\Feature\Subscriptions;

use App\Domain\Notifications\Models\Notification;
use App\Domain\Subscriptions\Models\SubscriptionEvent;
use App\Domain\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsSessionFixtures;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Spec §4 reminders + expiry, §40 dedupe, §43 items 5–6. */
class SubscriptionMonitorTest extends TestCase
{
    use BuildsSessionFixtures, CreatesUsers, RefreshDatabase;

    private function tenantExpiring(string $expiresLocal): Tenant
    {
        return $this->asSystem(fn () => Tenant::factory()->create(['subscription_expires_at' => CarbonImmutable::parse($expiresLocal, 'Asia/Tashkent')->utc()]));
    }

    private function notifications(Tenant $tenant): array
    {
        return $this->asSystem(fn () => Notification::query()->where('tenant_id', $tenant->id)->orderBy('id')->pluck('type')->all());
    }

    #[Test]
    public function reminders_at_5_3_1_0_days_are_sent_once_each_and_expiry_is_recorded_once(): void
    {
        $tenant = $this->tenantExpiring('2026-10-10 18:00:00');
        $owner = $this->tenantUser('CLIENT_OWNER', $tenant);

        foreach (['2026-10-05 09:00', '2026-10-05 15:00', '2026-10-06 09:00', '2026-10-07 09:00', '2026-10-09 09:00', '2026-10-10 09:00', '2026-10-10 19:00', '2026-10-11 09:00'] as $local) {
            $this->travelTo(CarbonImmutable::parse($local, 'Asia/Tashkent'));
            $this->artisan('subscriptions:check')->assertSuccessful();
        }

        $texts = $this->asSystem(fn () => Notification::query()->where('tenant_id', $tenant->id)->orderBy('id')->pluck('payload')->map(fn ($p) => $p['text'])->all());
        $this->assertSame([
            'Obuna muddati 5 kundan keyin tugaydi (10.10.2026).',
            'Obuna muddati 3 kundan keyin tugaydi (10.10.2026).',
            'Obuna muddati 1 kundan keyin tugaydi (10.10.2026).',
            'Obuna muddati bugun tugaydi.',
            "Obuna muddati tugadi. Ma'lumotlaringiz saqlanadi. To'lov uchun platforma administratori bilan bog'laning.",
        ], $texts);
        $this->assertSame(1, $this->asSystem(fn () => SubscriptionEvent::query()->where('tenant_id', $tenant->id)->where('type', 'EXPIRED')->count()));

        // Super Admin gets platform notifications; the client sees only its own in-app list.
        $this->assertGreaterThanOrEqual(5, $this->asSystem(fn () => Notification::query()->whereNull('tenant_id')->count()));
        $this->actingAs($owner)->getJson('/api/admin/notifications')->assertOk()->assertJsonPath('unread', 5)->assertJsonCount(5, 'data');
        $this->actingAs($this->superAdmin())->getJson('/api/super/notifications')->assertOk()->assertJsonPath('data.0.type', 'platform');
    }

    #[Test]
    public function an_extension_restarts_the_reminder_cycle(): void
    {
        $tenant = $this->tenantExpiring('2026-10-10 18:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'Asia/Tashkent'));
        $this->artisan('subscriptions:check');

        $this->actingAs($this->superAdmin())->postJson("/api/super/tenants/{$tenant->public_id}/payments", ['amount' => 500000, 'method' => 'CASH', 'days' => 30])->assertCreated();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 09:00', 'Asia/Tashkent'));
        $this->artisan('subscriptions:check');

        $this->assertSame(['subscription_expiring', 'subscription_expiring'], $this->notifications($tenant));
    }

    #[Test]
    public function suspended_or_healthy_tenants_get_no_reminders(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'Asia/Tashkent'));
        $healthy = $this->tenantExpiring('2026-12-31 00:00:00');
        $suspended = $this->asSystem(fn () => Tenant::factory()->create(['status_flag' => 'SUSPENDED', 'subscription_expires_at' => now()->addDays(3)]));

        $this->artisan('subscriptions:check');

        $this->assertSame([], $this->notifications($healthy));
        $this->assertSame([], $this->notifications($suspended));
    }

    #[Test]
    public function expired_clients_keep_access_to_status_notifications_and_export_but_not_business(): void
    {
        $h = $this->hall();
        $owner = $this->tenantUser('CLIENT_OWNER', $h['tenant']);
        $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->assertCreated();
        $this->asSystem(fn () => $h['tenant']->forceFill(['subscription_expires_at' => now()->subMinute()])->save());

        $this->actingAs($owner)->getJson('/api/admin/subscription')->assertOk()->assertJsonPath('status', 'EXPIRED');
        $this->actingAs($owner)->getJson('/api/admin/notifications')->assertOk();
        $this->actingAs($owner)->getJson('/api/admin/tables')->assertStatus(402);
        $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->assertStatus(402);

        $export = $this->actingAs($owner)->get('/api/admin/export')->assertOk();
        $data = json_decode($export->streamedContent(), true);
        $this->assertSame('Markaz', $data['branches'][0]['name']);
        $this->assertCount(1, $data['sessions']);
        $this->assertArrayNotHasKey('photo', $data['sessions'][0]);
        $this->actingAs($this->tenantUser('CLIENT_MANAGER', $h['tenant']))->get('/api/admin/export')->assertStatus(403);
    }

    #[Test]
    public function notifications_are_tenant_isolated_and_can_be_marked_read(): void
    {
        $a = $this->tenantExpiring('2026-10-10 18:00:00');
        $b = $this->tenantExpiring('2026-10-10 18:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00', 'Asia/Tashkent'));
        $this->artisan('subscriptions:check');
        $ownerA = $this->tenantUser('CLIENT_OWNER', $a);
        $idB = $this->asSystem(fn () => Notification::query()->where('tenant_id', $b->id)->value('public_id'));

        $this->actingAs($ownerA)->postJson("/api/admin/notifications/$idB/read")->assertNotFound();
        $idA = $this->actingAs($ownerA)->getJson('/api/admin/notifications')->json('data.0.id');
        $this->actingAs($ownerA)->postJson("/api/admin/notifications/$idA/read")->assertNoContent();
        $this->actingAs($ownerA)->getJson('/api/admin/notifications')->assertJsonPath('unread', 0);
        $this->assertNull($this->asSystem(fn () => Notification::query()->where('public_id', $idB)->value('read_at')));
    }
}
