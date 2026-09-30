<?php

namespace Tests\Feature\Devices;

use App\Domain\Devices\Enums\CommandType;
use App\Domain\Devices\Models\Device;
use App\Domain\Devices\Models\DeviceCommand;
use App\Domain\Devices\Services\DeviceCommandBus;
use App\Domain\Tables\Models\BilliardTable;
use App\Domain\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsProtocol;
use Tests\Concerns\BuildsSessionFixtures;
use Tests\Concerns\CreatesUsers;
use Tests\Support\DeviceSimulator;
use Tests\TestCase;

class DeviceProtocolTest extends TestCase
{
    use AssertsProtocol, BuildsSessionFixtures, CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['devices.registration_secret' => 'test-registration-secret-123']);
    }

    /** Registers + pairs a simulator to the hall's table (replacing the fixture device). */
    private function pairedSimulator(array $h): DeviceSimulator
    {
        $this->asSystem(fn () => $h['device']->forceFill(['status' => 'REVOKED', 'active_table_id' => null, 'active_hardware_id' => null])->save());
        $sim = new DeviceSimulator($this);
        $sim->register()->assertOk();
        $this->actingAs($this->tenantUser('CLIENT_OWNER', $h['tenant']))
            ->postJson('/api/admin/devices/pair', ['code' => $sim->pairingCode, 'tableId' => $h['table']->public_id])->assertCreated();
        $sim->pairingStatus()->assertOk()->assertJsonPath('status', 'PAIRED');
        $this->app['auth']->forgetGuards();

        return $sim;
    }

    #[Test]
    public function registration_and_pairing_handshake(): void
    {
        $h = $this->hall();
        $sim = new DeviceSimulator($this, 'A8F4C1B2D3E4');

        $this->postJson('/device/v1/register', ['hardwareId' => 'A8F4C1B2D3E4', 'firmwareVersion' => '1.0.0', 'registrationSecret' => 'wrong-secret-xxxxxxxx'])
            ->assertStatus(401)->assertJsonPath('error.code', 'DEVICE_UNAUTHORIZED');

        $reg = $sim->register()->assertOk()->assertJsonPath('deviceCode', 'ESP32-B2D3E4');
        $this->assertMatchesProtocol('device.register.response', $reg->json());
        $this->assertMatchesRegularExpression('/^\d{6}$/', $sim->pairingCode);
        $waiting = $sim->pairingStatus()->assertOk()->assertJsonPath('status', 'WAITING')->assertJsonMissingPath('token');
        $this->assertMatchesProtocol('device.pairing-status.response', $waiting->json());

        $owner = $this->tenantUser('CLIENT_OWNER', $h['tenant']);
        $table2 = $this->asSystem(fn () => BilliardTable::factory()->create(['tenant_id' => $h['tenant']->id, 'branch_id' => $h['branch']->id, 'number' => 2]));
        $this->actingAs($owner)->postJson('/api/admin/devices/pair', ['code' => '000000', 'tableId' => $table2->public_id])
            ->assertStatus(422)->assertJsonPath('error.code', 'PAIRING_CODE_INVALID');
        $this->actingAs($owner)->postJson('/api/admin/devices/pair', ['code' => $sim->pairingCode, 'tableId' => $table2->public_id])
            ->assertCreated()->assertJsonPath('data.code', 'ESP32-B2D3E4')->assertJsonPath('data.table.number', 2)->assertJsonPath('data.online', true);

        $paired = $sim->pairingStatus()->assertOk()->assertJsonPath('status', 'PAIRED');
        $this->assertMatchesProtocol('device.pairing-status.response', $paired->json());
        $this->assertNotNull($sim->token);
        $sim->pairingStatus()->assertJsonPath('status', 'PAIRED')->assertJsonMissingPath('token'); // exactly once

        $this->assertSame(hash('sha256', $sim->token), DB::table('devices')->where('hardware_id', 'A8F4C1B2D3E4')->value('token_hash'));
        $sim->poll()->assertOk();
    }

    #[Test]
    public function item_4_client_a_cannot_take_client_b_device(): void
    {
        $a = $this->hall();
        $b = $this->hall();
        $sim = new DeviceSimulator($this);
        $sim->register()->assertOk();
        $ownerA = $this->tenantUser('CLIENT_OWNER', $a['tenant']);
        $ownerB = $this->tenantUser('CLIENT_OWNER', $b['tenant']);
        $tableB2 = $this->asSystem(fn () => BilliardTable::factory()->create(['tenant_id' => $b['tenant']->id, 'branch_id' => $b['branch']->id, 'number' => 2]));
        $tableA2 = $this->asSystem(fn () => BilliardTable::factory()->create(['tenant_id' => $a['tenant']->id, 'branch_id' => $a['branch']->id, 'number' => 2]));

        $this->actingAs($ownerB)->postJson('/api/admin/devices/pair', ['code' => $sim->pairingCode, 'tableId' => $tableB2->public_id])->assertCreated();
        // The same code, or a foreign table id, is useless for A.
        $this->actingAs($ownerA)->postJson('/api/admin/devices/pair', ['code' => $sim->pairingCode, 'tableId' => $tableA2->public_id])
            ->assertStatus(422)->assertJsonPath('error.code', 'PAIRING_CODE_INVALID');
        $this->actingAs($ownerA)->postJson('/api/admin/devices/pair', ['code' => '123456', 'tableId' => $tableB2->public_id])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['tableId']]]);
        // Re-registering the paired hardware (e.g. from A's hall) is refused until B unpairs it.
        $sim->register()->assertStatus(409)->assertJsonPath('error.code', 'DEVICE_ALREADY_PAIRED');

        $codes = collect($this->actingAs($ownerA)->getJson('/api/admin/devices')->json('data'))->pluck('code');
        $this->assertNotContains($sim->deviceCode, $codes);
        $deviceB = $this->asSystem(fn () => Device::query()->where('hardware_id', $sim->hardwareId)->sole());
        $this->actingAs($ownerA)->postJson("/api/admin/devices/{$deviceB->public_id}/unpair")->assertNotFound();
    }

    #[Test]
    public function item_10_unauthorized_device_calls_are_rejected(): void
    {
        $h = $this->hall();
        $sim = $this->pairedSimulator($h);
        $other = $this->pairedSimulator($this->hall());

        $this->postJson('/device/v1/poll', ['ts' => 1, 'fw' => '1.0.0', 'state' => 'OFF'])->assertStatus(401)->assertJsonPath('error.code', 'DEVICE_UNAUTHORIZED');
        $this->postJson('/device/v1/poll', ['ts' => 1, 'fw' => '1.0.0', 'state' => 'OFF'], ['Authorization' => "Device {$sim->deviceCode}.".str_repeat('x', 43)])
            ->assertStatus(401)->assertJsonPath('error.code', 'REPAIR_REQUIRED');
        $this->postJson('/device/v1/poll', ['ts' => 1, 'fw' => '1.0.0', 'state' => 'OFF'], ['Authorization' => "Device {$other->deviceCode}.{$sim->token}"])
            ->assertStatus(401); // token of one device with the code of another

        // Device B cannot acknowledge (or learn about) device A's commands.
        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->json('session.id');
        $this->tabletStart($h['token'], $id)->assertOk();
        $commandId = $this->asSystem(fn () => DeviceCommand::query()->latest('id')->first()->public_id);
        $other->ack([['commandId' => $commandId, 'result' => 'OK']])->assertOk()->assertJsonPath('accepted', []);
        $this->assertSame('STARTING', DB::table('game_sessions')->where('public_id', $id)->value('status'));
        $this->assertSame([], $other->poll()->json('commands'));
    }

    #[Test]
    public function start_command_is_delivered_acknowledged_and_never_redelivered(): void
    {
        $h = $this->hall();
        $sim = $this->pairedSimulator($h);
        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->json('session.id');
        $this->tabletStart($h['token'], $id)->assertOk();

        $poll = $sim->poll()->assertOk();
        $this->assertMatchesProtocol('device.poll.response', $poll->json());
        $poll->assertJsonPath('commands.0.type', 'START_SESSION')->assertJsonPath('commands.0.payload.sessionId', $id);

        $ack = $sim->ack([['commandId' => $poll->json('commands.0.commandId'), 'result' => 'OK', 'state' => 'ON']])->assertOk();
        $this->assertMatchesProtocol('device.ack.response', $ack->json());
        $this->assertSame('ACTIVE', DB::table('game_sessions')->where('public_id', $id)->value('status'));
        $this->assertSame([], $sim->poll()->json('commands'));
        $sim->ack([['commandId' => $poll->json('commands.0.commandId'), 'result' => 'OK']])->assertJsonPath('accepted', [$poll->json('commands.0.commandId')]); // duplicate ACK is harmless

        $state = $sim->state()->assertOk()->assertJsonPath('session.sessionId', $id)->assertJsonPath('session.status', 'ACTIVE');
        $this->assertMatchesProtocol('device.state.response', $state->json());
    }

    #[Test]
    public function unacknowledged_commands_are_retried_three_times_then_failed_and_expired_ones_are_never_sent(): void
    {
        $h = $this->hall();
        $sim = $this->pairedSimulator($h);
        $cmd = $this->asSystem(fn () => app(DeviceCommandBus::class)->queue(
            Device::query()->where('hardware_id', $sim->hardwareId)->sole(), CommandType::PING, [], null, 120
        ));

        $this->assertCount(1, $sim->poll()->json('commands'));
        $this->assertCount(0, $sim->poll()->json('commands')); // waiting for ACK
        $this->travel(7)->seconds();
        $this->assertCount(1, $sim->poll()->json('commands')); // attempt 2
        $this->travel(7)->seconds();
        $this->assertCount(1, $sim->poll()->json('commands')); // attempt 3
        $this->travel(7)->seconds();
        $this->assertCount(0, $sim->poll()->json('commands'));
        $this->assertSame('FAILED', DB::table('device_commands')->where('id', $cmd->id)->value('status'));

        $late = $this->asSystem(fn () => app(DeviceCommandBus::class)->queue(
            Device::query()->where('hardware_id', $sim->hardwareId)->sole(), CommandType::PING, [], null, 5
        ));
        $this->travel(6)->seconds();
        $this->assertCount(0, $sim->poll()->json('commands'));
        $this->assertSame('EXPIRED', DB::table('device_commands')->where('id', $late->id)->value('status'));
    }

    #[Test]
    public function device_supplied_tenant_fields_are_ignored_and_online_status_is_real(): void
    {
        $h = $this->hall();
        $sim = $this->pairedSimulator($h);
        $other = $this->hall();

        $sim->poll(['tenantId' => $other['tenant']->id, 'tableId' => $other['table']->public_id])->assertOk();
        $device = $this->asSystem(fn () => Device::query()->where('hardware_id', $sim->hardwareId)->sole());
        $this->assertSame($h['tenant']->id, $device->tenant_id);
        $this->assertSame($h['table']->id, $device->table_id);

        $owner = $this->tenantUser('CLIENT_OWNER', $h['tenant']);
        $this->actingAs($owner)->getJson('/api/admin/devices')->assertJsonPath('data.0.online', true)->assertJsonPath('data.0.state', 'OFF');
        $this->travel(16)->seconds();
        $this->actingAs($owner)->getJson('/api/admin/devices')->assertJsonPath('data.0.online', false);
    }

    #[Test]
    public function spec_61_end_to_end_with_internet_loss_and_resync(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-05 07:00:00'));
        $h = $this->hall();
        $sim = $this->pairedSimulator($h);

        // Start a 10-minute test session from the tablet.
        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 10])
            ->assertCreated()->assertJsonPath('session.amount', 4000)->json('session.id');
        $this->tabletStart($h['token'], $id)->assertOk();

        // ESP32 receives the command, light turns on, session becomes ACTIVE.
        $received = $sim->pollAndApply();
        $this->assertSame('START_SESSION', $received[0]['type']);
        $this->assertSame('ON', $sim->light);
        $this->withToken($h['token'])->getJson('/api/tablet/tables')->assertJsonPath('tables.0.status', 'BUSY');

        // 5-minute warning window.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 07:05:30'));
        $sim->pollAndApply();
        $this->withToken($h['token'])->getJson('/api/tablet/tables')->assertJsonPath('tables.0.status', 'WARNING');

        // Internet disconnects: no polls. The device ends the session locally at endAt.
        $this->travelTo(CarbonImmutable::parse('2026-10-05 07:10:05'));
        $sim->tick();
        $this->assertSame('OFF', $sim->light);
        $this->artisan('sessions:finalize');
        $this->assertSame('COMPLETED', DB::table('game_sessions')->where('public_id', $id)->value('status'));

        // Reconnect: authoritative state says nothing is running; table available again.
        $sim->state()->assertOk()->assertJsonPath('session', null);
        $sim->pollAndApply();
        $this->withToken($h['token'])->getJson('/api/tablet/tables')->assertJsonPath('tables.0.status', 'AVAILABLE');
    }

    #[Test]
    public function early_stop_reaches_the_device_and_completes_on_ack(): void
    {
        $h = $this->hall();
        $sim = $this->pairedSimulator($h);
        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 60])->json('session.id');
        $this->tabletStart($h['token'], $id)->assertOk();
        $sim->pollAndApply();

        $this->actingAs($this->tenantUser('CLIENT_OPERATOR', $h['tenant']))->postJson("/api/admin/sessions/$id/stop")->assertOk();
        $this->app['auth']->forgetGuards();
        $received = $sim->pollAndApply();

        $this->assertSame('STOP_SESSION', $received[0]['type']);
        $this->assertSame('OFF', $sim->light);
        $this->assertSame('COMPLETED', DB::table('game_sessions')->where('public_id', $id)->value('status'));
    }

    #[Test]
    public function unpair_revokes_the_token_and_the_device_can_be_paired_elsewhere(): void
    {
        $h = $this->hall();
        $sim = $this->pairedSimulator($h);
        $device = $this->asSystem(fn () => Device::query()->where('hardware_id', $sim->hardwareId)->sole());
        $owner = $this->tenantUser('CLIENT_OWNER', $h['tenant']);

        $this->actingAs($owner)->postJson("/api/admin/devices/{$device->public_id}/unpair")->assertOk()->assertJsonPath('data.status', 'REVOKED');
        $this->app['auth']->forgetGuards();
        $sim->poll()->assertStatus(401)->assertJsonPath('error.code', 'REPAIR_REQUIRED');

        $other = $this->hall();
        $sim->register()->assertOk();
        $table2 = $this->asSystem(fn () => BilliardTable::factory()->create(['tenant_id' => $other['tenant']->id, 'branch_id' => $other['branch']->id, 'number' => 2]));
        $this->actingAs($this->tenantUser('CLIENT_OWNER', $other['tenant']))->postJson('/api/admin/devices/pair', ['code' => $sim->pairingCode, 'tableId' => $table2->public_id])->assertCreated();
        $this->assertSame(2, DB::table('devices')->where('hardware_id', $sim->hardwareId)->count()); // history row kept
    }

    #[Test]
    public function device_limit_is_enforced(): void
    {
        $h = $this->hall(['device_limit' => 1]);
        $sim = new DeviceSimulator($this);
        $sim->register()->assertOk();
        $table2 = $this->asSystem(fn () => BilliardTable::factory()->create(['tenant_id' => $h['tenant']->id, 'branch_id' => $h['branch']->id, 'number' => 2]));

        $this->actingAs($this->tenantUser('CLIENT_OWNER', $h['tenant']))->postJson('/api/admin/devices/pair', ['code' => $sim->pairingCode, 'tableId' => $table2->public_id])
            ->assertStatus(422)->assertJsonPath('error.code', 'LIMIT_REACHED');
    }

    #[Test]
    public function poll_stays_cheap(): void
    {
        $h = $this->hall();
        $sim = $this->pairedSimulator($h);
        $sim->poll()->assertOk();

        DB::enableQueryLog();
        $start = microtime(true);
        for ($i = 0; $i < 20; $i++) {
            $sim->poll()->assertOk();
        }
        $elapsedMs = (microtime(true) - $start) * 1000 / 20;
        $queries = count(DB::getQueryLog()) / 20;
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(6, $queries, "poll ran {$queries} queries on average");
        $this->assertLessThan(250, $elapsedMs, "poll took {$elapsedMs} ms on average");
    }

    #[Test]
    public function device_endpoints_keep_working_for_an_expired_client(): void
    {
        $h = $this->hall();
        $sim = $this->pairedSimulator($h);
        $this->asSystem(fn () => Tenant::query()->whereKey($h['tenant']->id)->update(['subscription_expires_at' => now()->subDay()]));

        $sim->poll()->assertOk();
        $sim->state()->assertOk();
    }
}
