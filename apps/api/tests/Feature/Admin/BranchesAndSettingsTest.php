<?php

namespace Tests\Feature\Admin;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class BranchesAndSettingsTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    #[Test]
    public function working_hours_and_closed_days_round_trip(): void
    {
        $owner = $this->tenantUser();
        $branch = $this->actingAs($owner)->postJson('/api/admin/branches', ['name' => 'Markaz', 'timezone' => 'Asia/Tashkent', 'reportTime' => '23:30'])
            ->assertCreated()->assertJsonPath('data.reportTime', '23:30')->json('data.id');

        $this->actingAs($owner)->getJson("/api/admin/branches/$branch/working-hours")->assertJsonCount(7, 'data')->assertJsonPath('data.0.opensAt', '00:00');

        $days = array_map(fn ($d) => ['weekday' => $d, 'isClosed' => $d === 1, 'opensAt' => $d === 1 ? null : '10:00', 'closesAt' => $d === 1 ? null : '02:00'], range(1, 7));
        $this->actingAs($owner)->putJson("/api/admin/branches/$branch/working-hours", ['days' => $days])->assertOk()
            ->assertJsonPath('data.0.isClosed', true)->assertJsonPath('data.1.opensAt', '10:00')->assertJsonPath('data.1.closesAt', '02:00');

        $this->actingAs($owner)->putJson("/api/admin/branches/$branch/working-hours", ['days' => array_slice($days, 0, 6)])->assertStatus(422);

        $day = $this->actingAs($owner)->postJson("/api/admin/branches/$branch/closed-days", ['date' => now()->addDays(3)->toDateString(), 'reason' => 'Bayram'])
            ->assertCreated()->json('id');
        $this->actingAs($owner)->getJson("/api/admin/branches/$branch/closed-days")->assertJsonPath('data.0.reason', 'Bayram');
        $this->actingAs($owner)->deleteJson("/api/admin/branches/$branch/closed-days/$day")->assertNoContent();
    }

    #[Test]
    public function tenant_settings_are_validated_and_isolated(): void
    {
        [$a, $b] = $this->asSystem(fn () => [Tenant::factory()->create(), Tenant::factory()->create()]);
        $ownerA = $this->tenantUser('CLIENT_OWNER', $a);
        $ownerB = $this->tenantUser('CLIENT_OWNER', $b);

        $this->actingAs($ownerA)->getJson('/api/admin/settings')->assertOk()->assertJsonPath('photoRetentionDays', 30)->assertJsonMissingPath('photoRequired');
        $this->actingAs($ownerA)->putJson('/api/admin/settings', ['photoRetentionDays' => 7, 'warningText' => '{table}-stol, 5 daqiqa qoldi.', 'tenantId' => $b->id])
            ->assertOk()->assertJsonPath('photoRetentionDays', 7);
        $this->actingAs($ownerB)->getJson('/api/admin/settings')->assertJsonPath('photoRetentionDays', 30);

        $this->actingAs($ownerA)->putJson('/api/admin/settings', ['photoRetentionDays' => 0])->assertStatus(422);
        // The photo rule is not a tenant setting any more: the old field is ignored.
        $this->actingAs($ownerA)->putJson('/api/admin/settings', ['photoRequired' => false])->assertOk()->assertJsonMissingPath('photoRequired');
        $this->actingAs($this->tenantUser('CLIENT_MANAGER', $a))->getJson('/api/admin/settings')->assertStatus(403);
    }

    #[Test]
    public function tenant_audit_log_only_shows_own_actions(): void
    {
        $ownerA = $this->tenantUser();
        $ownerB = $this->tenantUser();
        $this->actingAs($ownerA)->postJson('/api/admin/branches', ['name' => 'A'])->assertCreated();
        $this->actingAs($ownerB)->postJson('/api/admin/branches', ['name' => 'B'])->assertCreated();

        $actions = collect($this->actingAs($ownerA)->getJson('/api/admin/audit-logs')->assertOk()->json('data'));
        $this->assertCount(1, $actions->where('action', 'branch.created'));
    }
}
