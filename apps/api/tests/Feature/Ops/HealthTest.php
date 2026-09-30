<?php

namespace Tests\Feature\Ops;

use App\Domain\Health\HealthService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Spec §56. */
class HealthTest extends TestCase
{
    use CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['backup.encryption_key' => base64_encode(str_repeat('k', 32)), 'app.health_token' => 'monitor-token-123']);
    }

    #[Test]
    public function public_health_shows_only_the_overall_status(): void
    {
        Cache::forever(HealthService::SCHEDULER_KEY, now()->getTimestamp());
        $this->artisan('backup:run')->assertSuccessful();

        $this->getJson('/health')->assertOk()->assertExactJson(['status' => 'ok']);
        $this->getJson('/health/db')->assertOk()->assertExactJson(['status' => 'ok']);
        $this->getJson('/health', ['Authorization' => 'Bearer monitor-token-123'])->assertOk()
            ->assertJsonPath('checks.db.status', 'ok')->assertJsonPath('checks.storage.writable', true)
            ->assertJsonPath('checks.backups.status', 'ok');
    }

    #[Test]
    public function a_dead_scheduler_or_missing_backup_is_reported(): void
    {
        Cache::forget(HealthService::SCHEDULER_KEY);

        $this->getJson('/health')->assertStatus(503)->assertExactJson(['status' => 'fail']);
        $details = $this->actingAs($this->superAdmin())->getJson('/api/super/health')->assertOk();
        $details->assertJsonPath('checks.messaging.status', 'fail')->assertJsonPath('checks.backups.status', 'fail');
        $this->actingAs($this->tenantUser())->getJson('/api/super/health')->assertStatus(403);
    }

    #[Test]
    public function the_scheduler_heartbeat_is_registered(): void
    {
        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description ?? $e->command)->all();

        $this->assertContains('scheduler-heartbeat', $events);
        foreach (['sessions:finalize', 'subscriptions:check', 'backup:run', 'devices:monitor', 'notifications:deliver', 'photos:prune'] as $cmd) {
            $this->assertTrue(collect($events)->contains(fn ($e) => is_string($e) && str_contains($e, $cmd)), "$cmd is not scheduled");
        }
    }
}
