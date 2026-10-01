<?php

namespace Tests\Feature\Sessions;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Branches\Models\Branch;
use App\Domain\Devices\Models\DeviceCommand;
use App\Domain\Sessions\Models\GameSession;
use App\Domain\Sessions\Services\SessionService;
use App\Domain\Tables\Models\BilliardTable;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsProtocol;
use Tests\Concerns\BuildsSessionFixtures;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** ARCHITECTURE §5 end to end on the server side (device ACK simulated through SessionService). */
class SessionFlowTest extends TestCase
{
    use AssertsProtocol, BuildsSessionFixtures, CreatesUsers, RefreshDatabase;

    #[Test]
    public function full_session_lifecycle_with_derived_status_and_cron_finalization(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00'));
        $h = $this->hall();

        $boot = $this->withToken($h['token'])->getJson('/api/tablet/bootstrap')->assertOk();
        $this->assertMatchesProtocol('tablet.bootstrap.response', $boot->json());
        $boot->assertJsonPath('tables.0.status', 'AVAILABLE')->assertJsonPath('tables.0.pricing.durations.0', ['minutes' => 10, 'amount' => 4000]);

        $prepared = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 60])
            ->assertCreated()->assertJsonPath('session.status', 'RESERVED')->assertJsonPath('session.amount', 20000);
        $this->assertMatchesProtocol('tablet.session', $prepared->json());
        $id = $prepared->json('session.id');
        $this->withToken($h['token'])->getJson('/api/tablet/tables')->assertJsonPath('tables.0.status', 'RESERVED');

        $started = $this->tabletStart($h['token'], $id)->assertOk()
            ->assertJsonPath('session.status', 'STARTING')
            ->assertJsonPath('session.startAt', '2026-10-05T10:00:00Z')
            ->assertJsonPath('session.endAt', '2026-10-05T11:00:00Z');
        $this->assertMatchesProtocol('tablet.session', $started->json());

        $command = $this->asSystem(fn () => DeviceCommand::query()->where('device_id', $h['device']->id)->sole());
        $this->assertSame('START_SESSION', $command->type->value);
        $this->assertEquals(['sessionId' => $id, 'startAt' => 1791194400, 'endAt' => 1791198000, 'warnBeforeSec' => 300, 'flashCount' => 3, 'channel' => 1], $command->payload);
        $this->assertSame(1, $command->channel);

        $session = $this->asSystem(fn () => GameSession::query()->where('public_id', $id)->sole());
        $this->asSystem(fn () => app(SessionService::class)->confirmStarted($session, $h['device']->id));
        $tables = $this->withToken($h['token'])->getJson('/api/tablet/tables')->assertJsonPath('tables.0.status', 'BUSY')
            ->assertJsonPath('tables.0.session.endAt', '2026-10-05T11:00:00Z');
        $this->assertMatchesProtocol('tablet.tables.response', $tables->json());

        $this->travelTo(CarbonImmutable::parse('2026-10-05 10:55:00'));
        $this->touchDevice($h['device']);
        $this->withToken($h['token'])->getJson('/api/tablet/tables')->assertJsonPath('tables.0.status', 'WARNING');
        $this->artisan('sessions:finalize')->assertSuccessful();
        $this->assertNotNull($session->fresh()->warned_at);

        // After endAt the table is free even before cron ran (derived status).
        $this->travelTo(CarbonImmutable::parse('2026-10-05 11:00:01'));
        $this->touchDevice($h['device']);
        $this->withToken($h['token'])->getJson('/api/tablet/tables')->assertJsonPath('tables.0.status', 'AVAILABLE');

        $this->artisan('sessions:finalize')->assertSuccessful();
        $fresh = $session->fresh();
        $this->assertSame('COMPLETED', $fresh->status->value);
        $this->assertSame('2026-10-05 11:00:00', $fresh->ended_at->format('Y-m-d H:i:s'));
        $this->assertSame(
            ['RESERVED', 'STARTING', 'ACTIVE', 'COMPLETED'],
            $this->asSystem(fn () => $fresh->events()->pluck('to_status')->map->value->all())
        );
        $this->assertEqualsCanonicalizing(
            ['session.created', 'session.started', 'session.completed'],
            $this->asSystem(fn () => AuditLog::query()->where('action', 'like', 'session.%')->pluck('action')->all())
        );
    }

    #[Test]
    public function a_second_customer_cannot_take_a_reserved_or_busy_table(): void
    {
        $h = $this->hall();
        $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->assertCreated();

        $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])
            ->assertStatus(409)->assertJsonPath('error.code', 'TABLE_UNAVAILABLE')->assertJsonPath('error.message', 'Stol band. Boshqa stolni tanlang.');
    }

    #[Test]
    public function the_same_idempotency_key_never_creates_two_sessions(): void
    {
        $h = $this->hall();
        $key = (string) Str::uuid();
        $body = ['tableId' => $h['table']->public_id, 'durationMinutes' => 30];

        $a = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', $body, $key)->assertCreated();
        $b = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', $body, $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($a->json('session.id'), $b->json('session.id'));
        $this->assertSame(1, DB::table('game_sessions')->count());
        $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', $body)->assertStatus(409); // new key, table held
        $this->withToken($h['token'])->postJson('/api/tablet/sessions/prepare', $body)->assertStatus(400); // key required
    }

    #[Test]
    public function preconditions_are_checked_with_clear_codes(): void
    {
        $h = $this->hall();
        $prep = fn (int $minutes = 30) => $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => $minutes]);

        $prep(45)->assertStatus(422)->assertJsonPath('error.code', 'DURATION_NOT_ALLOWED');

        $this->asSystem(fn () => $h['device']->forceFill(['last_seen_at' => now()->subMinute()])->save());
        $prep()->assertStatus(422)->assertJsonPath('error.code', 'DEVICE_OFFLINE');

        $this->wire($h['table'], null, null);
        $prep()->assertStatus(422)->assertJsonPath('error.code', 'DEVICE_NOT_ASSIGNED');

        $this->asSystem(fn () => $h['table']->forceFill(['pricing_plan_id' => null])->save());
        $prep()->assertStatus(422)->assertJsonPath('error.code', 'PRICING_NOT_CONFIGURED');

        $this->asSystem(fn () => $h['table']->forceFill(['is_active' => false])->save());
        $prep()->assertStatus(422)->assertJsonPath('error.code', 'TABLE_DISABLED');
        $this->assertSame(0, DB::table('game_sessions')->count());
    }

    #[Test]
    public function the_branch_must_be_open(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00:00', 'Asia/Tashkent')); // Monday 08:00 local
        $h = $this->hall();
        $this->asSystem(fn () => DB::table('working_hours')->insert(array_map(fn ($d) => [
            'tenant_id' => $h['tenant']->id, 'branch_id' => $h['branch']->id, 'weekday' => $d, 'opens_at' => '10:00:00', 'closes_at' => '02:00:00',
            'is_closed' => false, 'created_at' => now(), 'updated_at' => now(),
        ], range(1, 7))));

        $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])
            ->assertStatus(422)->assertJsonPath('error.code', 'BRANCH_CLOSED');
        $this->withToken($h['token'])->getJson('/api/tablet/tables')->assertJsonPath('isOpenNow', false)->assertJsonPath('tables.0.status', 'CLOSED');
    }

    #[Test]
    public function a_photo_is_always_required_before_start_even_if_an_old_setting_turned_it_off(): void
    {
        // A stale tenant setting from before the rule (photo_required=false) must not open a way around it.
        $h = $this->hall([], ['photo_required' => false]);
        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->json('session.id');

        $this->tabletPost($h['token'], "/api/tablet/sessions/$id/start")->assertStatus(422)->assertJsonPath('error.code', 'PHOTO_REQUIRED');

        $this->withToken($h['token'])->getJson('/api/tablet/bootstrap')->assertJsonPath('settings.photoRequired', true);

        $this->tabletStart($h['token'], $id)->assertOk()->assertJsonPath('session.hasPhoto', true);
    }

    #[Test]
    public function an_expired_reservation_cannot_start_and_frees_the_table(): void
    {
        $h = $this->hall();
        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->json('session.id');

        $this->travel(121)->seconds();
        $this->touchDevice($h['device']);
        $this->tabletStart($h['token'], $id)->assertStatus(409)->assertJsonPath('error.code', 'RESERVATION_EXPIRED');
        $this->assertSame('CANCELLED', DB::table('game_sessions')->where('public_id', $id)->value('status'));
        $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->assertCreated();
    }

    #[Test]
    public function customer_can_cancel_a_reservation_but_not_a_started_session(): void
    {
        $h = $this->hall();
        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->json('session.id');
        $this->tabletPost($h['token'], "/api/tablet/sessions/$id/cancel")->assertOk()->assertJsonPath('session.status', 'CANCELLED');

        $id2 = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->json('session.id');
        $this->tabletStart($h['token'], $id2)->assertOk();
        $this->tabletPost($h['token'], "/api/tablet/sessions/$id2/cancel")->assertStatus(409)->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
    }

    #[Test]
    public function missing_device_ack_fails_the_session_releases_the_table_and_queues_stop(): void
    {
        $h = $this->hall();
        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->json('session.id');
        $this->tabletStart($h['token'], $id)->assertOk();

        $this->travel(21)->seconds();
        $this->artisan('sessions:finalize')->assertSuccessful();

        $row = DB::table('game_sessions')->where('public_id', $id)->first();
        $this->assertSame('FAILED', $row->status);
        $this->assertSame('DEVICE_NO_ACK', $row->failure_reason);
        $types = $this->asSystem(fn () => DeviceCommand::query()->orderBy('id')->get()->map(fn ($c) => $c->type->value.':'.$c->status->value)->all());
        $this->assertSame(['START_SESSION:EXPIRED', 'STOP_SESSION:PENDING'], $types);
        $this->touchDevice($h['device']);
        $this->withToken($h['token'])->getJson('/api/tablet/tables')->assertJsonPath('tables.0.status', 'AVAILABLE');
    }

    #[Test]
    public function staff_stop_queues_stop_and_completes_after_timeout(): void
    {
        $h = $this->hall();
        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 60])->json('session.id');
        $this->tabletStart($h['token'], $id)->assertOk();
        $session = $this->asSystem(fn () => GameSession::query()->where('public_id', $id)->sole());
        $this->asSystem(fn () => app(SessionService::class)->confirmStarted($session, $h['device']->id));
        $operator = $this->tenantUser('CLIENT_OPERATOR', $h['tenant']);

        $this->travel(10)->minutes();
        $this->actingAs($operator)->postJson("/api/admin/sessions/$id/stop")->assertOk()
            ->assertJsonPath('data.status', 'COMPLETING')->assertJsonPath('data.endedEarly', true);
        $this->assertSame('STOP_SESSION', $this->asSystem(fn () => DeviceCommand::query()->latest('id')->first()->type->value));

        $this->travel(61)->seconds();
        $this->artisan('sessions:finalize');
        $this->actingAs($operator)->getJson("/api/admin/sessions/$id")->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.events.4.reason', 'STOP_ACK_TIMEOUT');
    }

    #[Test]
    public function payment_status_is_set_manually_by_staff_and_audited(): void
    {
        $h = $this->hall();
        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->json('session.id');
        $operator = $this->tenantUser('CLIENT_OPERATOR', $h['tenant']);

        $this->actingAs($operator)->postJson("/api/admin/sessions/$id/payment", ['status' => 'PAID'])->assertStatus(409); // not started
        $this->tabletStart($h['token'], $id)->assertOk();
        $this->actingAs($operator)->postJson("/api/admin/sessions/$id/payment", ['status' => 'PAID'])->assertOk()->assertJsonPath('data.paymentStatus', 'PAID');
        $this->actingAs($operator)->postJson("/api/admin/sessions/$id/payment", ['status' => 'VERIFIED'])->assertStatus(422);

        $log = $this->asSystem(fn () => AuditLog::query()->where('action', 'session.payment_marked')->sole());
        $this->assertEquals(['from' => 'UNPAID', 'to' => 'PAID'], $log->metadata); // JSON key order is engine-specific
        $this->assertSame($operator->id, $log->actor_id);
    }

    #[Test]
    public function spec_43_item_5_expired_client_cannot_start_sessions_and_keeps_data(): void
    {
        $h = $this->hall();
        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->json('session.id');
        $this->asSystem(fn () => $h['tenant']->forceFill(['subscription_expires_at' => now()->subMinute()])->save());

        $this->tabletStart($h['token'], $id)->assertStatus(402)->assertJsonPath('error.code', 'SUBSCRIPTION_INACTIVE');
        $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->assertStatus(402);
        $this->assertSame(1, DB::table('game_sessions')->where('tenant_id', $h['tenant']->id)->count()); // item 6: data intact
    }

    #[Test]
    public function tablets_only_reach_their_own_branch_and_tenant(): void
    {
        $a = $this->hall();
        $b = $this->hall();

        $this->tabletPost($a['token'], '/api/tablet/sessions/prepare', ['tableId' => $b['table']->public_id, 'durationMinutes' => 30])->assertNotFound();
        $idB = $this->tabletPost($b['token'], '/api/tablet/sessions/prepare', ['tableId' => $b['table']->public_id, 'durationMinutes' => 30])->json('session.id');
        $this->tabletStart($a['token'], $idB)->assertNotFound();
        $this->withToken($a['token'])->getJson("/api/tablet/sessions/$idB")->assertNotFound();

        // Another branch of the same tenant.
        $other = $this->asSystem(function () use ($a) {
            $branch = Branch::factory()->create(['tenant_id' => $a['tenant']->id]);

            return BilliardTable::factory()->create(['tenant_id' => $a['tenant']->id, 'branch_id' => $branch->id, 'number' => 1, 'pricing_plan_id' => $a['plan']->id]);
        });
        $this->tabletPost($a['token'], '/api/tablet/sessions/prepare', ['tableId' => $other->public_id, 'durationMinutes' => 30])->assertNotFound();
    }
}
