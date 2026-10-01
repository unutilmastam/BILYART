<?php

namespace Tests\Feature\Devices;

use App\Domain\Branches\Models\Branch;
use App\Domain\Devices\Models\Device;
use App\Domain\Tables\Models\BilliardTable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsProtocol;
use Tests\Concerns\BuildsSessionFixtures;
use Tests\Concerns\CreatesUsers;
use Tests\Support\DeviceSimulator;
use Tests\TestCase;

/** One ESP32 per branch drives several table lamps: one relay channel per table. */
class MultiChannelDeviceTest extends TestCase
{
    use AssertsProtocol, BuildsSessionFixtures, CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['devices.registration_secret' => 'test-registration-secret-123']);
    }

    private function table(array $h, int $number, ?Branch $branch = null): BilliardTable
    {
        return $this->asSystem(fn () => BilliardTable::factory()->create([
            'tenant_id' => $h['tenant']->id, 'branch_id' => ($branch ?? $h['branch'])->id, 'number' => $number, 'name' => "{$number}-stol", 'pricing_plan_id' => $h['plan']->id,
        ]));
    }

    private function device(DeviceSimulator $sim): Device
    {
        return $this->asSystem(fn () => Device::query()->where('hardware_id', $sim->hardwareId)->where('status', 'PAIRED')->sole());
    }

    /** Hall whose fixture device is replaced by a 4-channel simulator paired to the branch (no tables wired yet). */
    private function hallWithSimulator(): array
    {
        $h = $this->hall();
        $this->wire($h['table'], null, null);
        $this->asSystem(fn () => $h['device']->forceFill(['status' => 'REVOKED', 'active_hardware_id' => null])->save());
        $sim = new DeviceSimulator($this, null, 4);
        $sim->register()->assertOk();
        $this->actingAs($this->tenantUser('CLIENT_OWNER', $h['tenant']))
            ->postJson('/api/admin/devices/pair', ['code' => $sim->pairingCode, 'branchId' => $h['branch']->public_id])->assertCreated();
        $sim->pairingStatus()->assertOk();
        $this->app['auth']->forgetGuards();

        return [$h, $sim];
    }

    private function patchTable(array $h, BilliardTable $table, array $body, string $role = 'CLIENT_OWNER')
    {
        return $this->actingAs($this->tenantUser($role, $h['tenant']))->patchJson("/api/admin/tables/{$table->public_id}", $body);
    }

    #[Test]
    public function one_device_drives_several_tables_independently(): void
    {
        [$h, $sim] = $this->hallWithSimulator();
        $device = $this->device($sim);
        $t3 = $this->table($h, 3);
        $this->patchTable($h, $h['table'], ['deviceId' => $device->public_id, 'deviceChannel' => 1])->assertOk()
            ->assertJsonPath('data.device.code', $device->device_code)->assertJsonPath('data.device.channel', 1);
        $this->patchTable($h, $t3, ['deviceId' => $device->public_id, 'deviceChannel' => 3])->assertOk();
        $this->app['auth']->forgetGuards();

        $a = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->assertCreated()->json('session.id');
        $this->tabletStart($h['token'], $a)->assertOk();
        $b = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $t3->public_id, 'durationMinutes' => 60])->assertCreated()->json('session.id');
        $this->tabletStart($h['token'], $b)->assertOk();

        $received = $sim->pollAndApply();
        $this->assertSame([1, 3], collect($received)->pluck('payload.channel')->sort()->values()->all());
        foreach ($received as $c) {
            $this->assertMatchesProtocol('device.command', $c);
        }
        $this->assertSame(['ON', 'OFF', 'ON', 'OFF'], [$sim->light(1), $sim->light(2), $sim->light(3), $sim->light(4)]);
        $this->assertSame([$a, $b], [$sim->sessionOn(1), $sim->sessionOn(3)]);
        $this->assertSame(['ACTIVE', 'ACTIVE'], DB::table('game_sessions')->whereIn('public_id', [$a, $b])->orderBy('id')->pluck('status')->all());
        $this->assertSame([1, 3], DB::table('game_sessions')->whereIn('public_id', [$a, $b])->orderBy('id')->pluck('device_channel')->map(fn ($v) => (int) $v)->all());

        $state = $sim->state()->assertOk()->assertJsonCount(2, 'sessions')
            ->assertJsonPath('sessions.0.channel', 1)->assertJsonPath('sessions.0.sessionId', $a)
            ->assertJsonPath('sessions.1.channel', 3)->assertJsonPath('sessions.1.sessionId', $b);
        $this->assertMatchesProtocol('device.state.response', $state->json());

        // Stopping table 1 turns off channel 1 only.
        $this->actingAs($this->tenantUser('CLIENT_OPERATOR', $h['tenant']))->postJson("/api/admin/sessions/$a/stop")->assertOk();
        $this->app['auth']->forgetGuards();
        $stop = $sim->pollAndApply();
        $this->assertSame([['STOP_SESSION', 1]], collect($stop)->map(fn ($c) => [$c['type'], $c['payload']['channel']])->all());
        $this->assertSame(['OFF', 'ON'], [$sim->light(1), $sim->light(3)]);
        $this->assertSame('COMPLETED', DB::table('game_sessions')->where('public_id', $a)->value('status'));
        $this->assertSame('ACTIVE', DB::table('game_sessions')->where('public_id', $b)->value('status'));

        // Admin sees each channel with its table and the lamp state the device reported.
        $sim->poll()->assertOk();
        $this->actingAs($this->tenantUser('CLIENT_OWNER', $h['tenant']))->getJson('/api/admin/devices')->assertOk()
            ->assertJsonPath('data.0.channels.0.table.number', 1)->assertJsonPath('data.0.channels.0.state', 'OFF')
            ->assertJsonPath('data.0.channels.1.table', null)
            ->assertJsonPath('data.0.channels.2.table.number', 3)->assertJsonPath('data.0.channels.2.state', 'ON');
    }

    #[Test]
    public function wiring_rules_are_enforced_by_the_backend(): void
    {
        [$h, $sim] = $this->hallWithSimulator();
        $device = $this->device($sim);
        $t2 = $this->table($h, 2);

        // Channel must exist on this device (4 channels).
        $this->patchTable($h, $h['table'], ['deviceId' => $device->public_id, 'deviceChannel' => 5])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['deviceChannel']]]);
        $this->patchTable($h, $h['table'], ['deviceId' => $device->public_id])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['deviceChannel']]]);
        // One table per relay channel.
        $this->patchTable($h, $h['table'], ['deviceId' => $device->public_id, 'deviceChannel' => 2])->assertOk();
        $this->patchTable($h, $t2, ['deviceId' => $device->public_id, 'deviceChannel' => 2])->assertStatus(409);
        // A device of another branch cannot drive this table.
        $branch2 = $this->asSystem(fn () => Branch::factory()->create(['tenant_id' => $h['tenant']->id, 'name' => 'Yunusobod']));
        $far = $this->table($h, 7, $branch2);
        $this->patchTable($h, $far, ['deviceId' => $device->public_id, 'deviceChannel' => 1])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['deviceId']]]);
        // Operators cannot rewire lamps.
        $this->patchTable($h, $t2, ['deviceId' => $device->public_id, 'deviceChannel' => 3], 'CLIENT_OPERATOR')->assertForbidden();
        // Creating a table can wire it in the same request.
        $this->actingAs($this->tenantUser('CLIENT_MANAGER', $h['tenant']))->postJson('/api/admin/tables', [
            'branchId' => $h['branch']->public_id, 'number' => 9, 'deviceId' => $device->public_id, 'deviceChannel' => 4,
        ])->assertCreated()->assertJsonPath('data.device.channel', 4);
        // A rejected wiring on create rolls the whole create back.
        $this->actingAs($this->tenantUser('CLIENT_MANAGER', $h['tenant']))->postJson('/api/admin/tables', [
            'branchId' => $h['branch']->public_id, 'number' => 10, 'deviceId' => $device->public_id, 'deviceChannel' => 4,
        ])->assertStatus(409);
        $this->assertFalse(DB::table('billiard_tables')->where('tenant_id', $h['tenant']->id)->where('number', 10)->exists());

        // Unwire.
        $this->patchTable($h, $h['table'], ['deviceId' => null])->assertOk()->assertJsonPath('data.device', null);
        $this->assertNull(DB::table('billiard_tables')->where('id', $h['table']->id)->value('device_channel'));
    }

    #[Test]
    public function another_tenant_cannot_wire_to_or_see_a_device(): void
    {
        [$h, $sim] = $this->hallWithSimulator();
        $device = $this->device($sim);
        $other = $this->hall();

        $this->patchTable($other, $other['table'], ['deviceId' => $device->public_id, 'deviceChannel' => 2])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['deviceId']]]);
        $this->assertSame($other['device']->id, (int) DB::table('billiard_tables')->where('id', $other['table']->id)->value('device_id'));
        $this->actingAs($this->tenantUser('CLIENT_OWNER', $other['tenant']))->patchJson("/api/admin/devices/{$device->public_id}", ['branchId' => $other['branch']->public_id])
            ->assertNotFound();
        // A device's commands never carry another tenant's session; the DB FK also refuses a cross-tenant wiring.
        $this->expectException(QueryException::class);
        $this->asSystem(fn () => DB::table('billiard_tables')->where('id', $other['table']->id)->update(['device_id' => $device->id, 'device_channel' => 3]));
    }

    #[Test]
    public function running_sessions_block_rewiring_unpairing_and_moving(): void
    {
        [$h, $sim] = $this->hallWithSimulator();
        $device = $this->device($sim);
        $this->patchTable($h, $h['table'], ['deviceId' => $device->public_id, 'deviceChannel' => 1])->assertOk();
        $this->app['auth']->forgetGuards();
        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->json('session.id');
        $this->tabletStart($h['token'], $id)->assertOk();
        $sim->pollAndApply();

        $this->patchTable($h, $h['table'], ['deviceId' => $device->public_id, 'deviceChannel' => 2])->assertStatus(409);
        $this->actingAs($this->tenantUser('CLIENT_OWNER', $h['tenant']))->postJson("/api/admin/devices/{$device->public_id}/unpair")->assertStatus(409);
        $branch2 = $this->asSystem(fn () => Branch::factory()->create(['tenant_id' => $h['tenant']->id, 'name' => 'Chilonzor']));
        $this->actingAs($this->tenantUser('CLIENT_OWNER', $h['tenant']))->patchJson("/api/admin/devices/{$device->public_id}", ['branchId' => $branch2->public_id])
            ->assertStatus(409);
        $this->assertSame('PAIRED', DB::table('devices')->where('id', $device->id)->value('status'));

        // After the game: unwire, then the device can move to another branch.
        $this->actingAs($this->tenantUser('CLIENT_OPERATOR', $h['tenant']))->postJson("/api/admin/sessions/$id/stop")->assertOk();
        $this->app['auth']->forgetGuards();
        $sim->pollAndApply();
        $this->patchTable($h, $h['table'], ['deviceId' => null])->assertOk();
        $this->actingAs($this->tenantUser('CLIENT_OWNER', $h['tenant']))->patchJson("/api/admin/devices/{$device->public_id}", ['branchId' => $branch2->public_id])
            ->assertOk()->assertJsonPath('data.branch.name', 'Chilonzor');
    }

    #[Test]
    public function an_unwired_table_cannot_start_a_game(): void
    {
        [$h] = $this->hallWithSimulator();

        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->json('session.id');
        $this->withToken($h['token'])->getJson('/api/tablet/tables')->assertJsonPath('tables.0.status', 'DEVICE_OFFLINE');
        if ($id !== null) {
            $this->tabletStart($h['token'], $id)->assertStatus(409);
        }
        $this->assertFalse(DB::table('game_sessions')->whereIn('status', ['STARTING', 'ACTIVE'])->exists());
    }

    #[Test]
    public function a_command_for_an_unknown_channel_is_rejected_by_the_device_and_fails_the_start(): void
    {
        [$h, $sim] = $this->hallWithSimulator();
        $device = $this->device($sim);
        $this->patchTable($h, $h['table'], ['deviceId' => $device->public_id, 'deviceChannel' => 4])->assertOk();
        $this->app['auth']->forgetGuards();
        // The hardware is a 2-relay board although it was registered with 4 channels.
        unset($sim->channels[3], $sim->channels[4]);

        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->json('session.id');
        $this->tabletStart($h['token'], $id)->assertOk();
        $sim->pollAndApply();

        $this->assertSame('FAILED', DB::table('game_sessions')->where('public_id', $id)->value('status'));
    }
}
