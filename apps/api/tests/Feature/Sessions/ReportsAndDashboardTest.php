<?php

namespace Tests\Feature\Sessions;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsSessionFixtures;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ReportsAndDashboardTest extends TestCase
{
    use BuildsSessionFixtures, CreatesUsers, RefreshDatabase;

    private function seedSession(array $h, string $status, string $startUtc, int $minutes, int $amount, string $payment = 'UNPAID', array $extra = []): void
    {
        $start = CarbonImmutable::parse($startUtc);
        DB::table('game_sessions')->insert(array_merge([
            'public_id' => (string) Str::ulid(), 'tenant_id' => $h['tenant']->id, 'branch_id' => $h['branch']->id, 'table_id' => $h['table']->id,
            'status' => $status, 'duration_minutes' => $minutes, 'start_at' => $start, 'end_at' => $start->addMinutes($minutes),
            'price_per_hour_snapshot' => 20000, 'rounding_step_snapshot' => 1000, 'amount' => $amount, 'payment_status' => $payment,
            'created_at' => $start, 'updated_at' => $start,
        ], $extra));
    }

    #[Test]
    public function daily_report_uses_the_branch_timezone_and_counts_only_played_sessions(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00'));
        $h = $this->hall();
        // Tashkent is UTC+5: local 2026-10-05 = 2026-10-04 19:00Z .. 2026-10-05 19:00Z
        $this->seedSession($h, 'COMPLETED', '2026-10-04 19:30:00', 60, 20000, 'PAID');   // local 05 00:30 → counted
        $this->seedSession($h, 'COMPLETED', '2026-10-05 18:00:00', 120, 40000);          // local 05 23:00 → counted, unpaid
        $this->seedSession($h, 'COMPLETED', '2026-10-05 19:10:00', 60, 20000);           // local 06 → next day
        $this->seedSession($h, 'COMPLETED', '2026-10-04 18:00:00', 60, 20000);           // local 04 → previous day
        $this->seedSession($h, 'CANCELLED', '2026-10-05 10:00:00', 30, 10000, 'UNPAID', ['start_at' => null, 'end_at' => null]);
        $this->seedSession($h, 'COMPLETED', '2026-10-05 12:00:00', 60, 20000, 'WAIVED', ['ended_at' => '2026-10-05 12:15:00', 'ended_early' => true]);
        $owner = $this->tenantUser('CLIENT_OWNER', $h['tenant']);

        $r = $this->actingAs($owner)->getJson('/api/admin/reports/daily?date=2026-10-05')->assertOk();
        $r->assertJsonPath('totals.sessions', 3)
            ->assertJsonPath('totals.amount', 80000)
            ->assertJsonPath('totals.minutes', 60 + 120 + 15)
            ->assertJsonPath('totals.unpaidCount', 1)
            ->assertJsonPath('totals.unpaidAmount', 40000)
            ->assertJsonPath('branches.0.tables.0.sessions', 3)
            ->assertJsonPath('branches.0.tables.0.minutes', 195);

        $this->actingAs($owner)->getJson('/api/admin/reports/monthly?month=2026-10')->assertOk()->assertJsonPath('totals.sessions', 5);
        $this->actingAs($this->tenantUser('CLIENT_OPERATOR', $h['tenant']))->getJson('/api/admin/reports/daily?date=2026-10-05')->assertStatus(403);
    }

    #[Test]
    public function reports_never_include_other_tenants(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00'));
        $a = $this->hall();
        $b = $this->hall();
        $this->seedSession($b, 'COMPLETED', '2026-10-05 10:00:00', 60, 99000);

        $this->actingAs($this->tenantUser('CLIENT_OWNER', $a['tenant']))->getJson('/api/admin/reports/daily?date=2026-10-05')
            ->assertJsonPath('totals.amount', 0)->assertJsonCount(1, 'branches');
        $this->actingAs($this->tenantUser('CLIENT_OWNER', $a['tenant']))->getJson("/api/admin/reports/daily?date=2026-10-05&branchId={$b['branch']->public_id}")
            ->assertNotFound();
    }

    #[Test]
    public function dashboard_shows_live_tables_and_today_figures(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00'));
        $h = $this->hall();
        $this->seedSession($h, 'ACTIVE', '2026-10-05 09:30:00', 60, 20000);
        $owner = $this->tenantUser('CLIENT_OWNER', $h['tenant']);

        $this->actingAs($owner)->getJson('/api/admin/dashboard')->assertOk()
            ->assertJsonPath('totals.playing', 1)
            ->assertJsonPath('totals.available', 0)
            ->assertJsonPath('totals.sessionsToday', 1)
            ->assertJsonPath('totals.amountToday', 20000)
            ->assertJsonPath('totals.minutesToday', 30)
            ->assertJsonPath('totals.devicesOnline', 1)
            ->assertJsonPath('branches.0.tables.0.status', 'BUSY');
    }
}
