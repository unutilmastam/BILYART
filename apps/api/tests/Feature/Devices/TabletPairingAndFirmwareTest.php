<?php

namespace Tests\Feature\Devices;

use App\Domain\Devices\Models\DeviceCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsProtocol;
use Tests\Concerns\BuildsSessionFixtures;
use Tests\Concerns\CreatesUsers;
use Tests\Support\DeviceSimulator;
use Tests\TestCase;

class TabletPairingAndFirmwareTest extends TestCase
{
    use AssertsProtocol, BuildsSessionFixtures, CreatesUsers, RefreshDatabase;

    #[Test]
    public function tablet_pairing_binds_the_tablet_to_a_branch_chosen_by_the_admin(): void
    {
        $h = $this->hall();
        $reg = $this->postJson('/api/tablet/register', ['appVersion' => '1.0.0', 'deviceModel' => 'Samsung SM-X200'])->assertCreated();
        $this->assertMatchesProtocol('tablet.register.response', $reg->json());
        $poll = ['Authorization' => 'PollToken '.$reg->json('pollToken')];

        $this->getJson('/api/tablet/pairing-status', $poll)->assertOk()->assertJsonPath('status', 'WAITING');

        $owner = $this->tenantUser('CLIENT_OWNER', $h['tenant']);
        $this->actingAs($owner)->postJson('/api/admin/tablets/pair', ['code' => $reg->json('pairingCode'), 'branchId' => $h['branch']->public_id, 'name' => 'Kirish'])
            ->assertCreated()->assertJsonPath('data.branch.name', 'Markaz')->assertJsonPath('data.name', 'Kirish');
        $this->app['auth']->forgetGuards();

        $paired = $this->getJson('/api/tablet/pairing-status', $poll)->assertOk()->assertJsonPath('status', 'PAIRED');
        $this->assertMatchesProtocol('tablet.pairing-status.response', $paired->json());
        $token = $paired->json('token');
        $this->getJson('/api/tablet/pairing-status', $poll)->assertJsonMissingPath('token');

        $this->withToken($token)->getJson('/api/tablet/bootstrap')->assertOk()->assertJsonPath('branch.name', 'Markaz');

        // Another tenant's admin cannot use the (already used) code, nor a foreign branch.
        $foreign = $this->tenantUser('CLIENT_OWNER');
        $this->actingAs($foreign)->postJson('/api/admin/tablets/pair', ['code' => $reg->json('pairingCode'), 'branchId' => $h['branch']->public_id])->assertStatus(422);

        // Revoke → the token stops working.
        $list = collect($this->actingAs($owner)->getJson('/api/admin/tablets')->json('data'));
        $target = $list->firstWhere('name', 'Kirish')['id'];
        $this->actingAs($owner)->postJson("/api/admin/tablets/$target/revoke")->assertOk()->assertJsonPath('data.status', 'REVOKED');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/tablet/bootstrap')->assertStatus(401);
    }

    #[Test]
    public function expired_or_unknown_poll_tokens_are_handled(): void
    {
        $reg = $this->postJson('/api/tablet/register', ['appVersion' => '1.0.0'])->assertCreated();
        $this->travel(16)->minutes();
        $this->getJson('/api/tablet/pairing-status', ['Authorization' => 'PollToken '.$reg->json('pollToken')])->assertJsonPath('status', 'EXPIRED');
        $this->getJson('/api/tablet/pairing-status', ['Authorization' => 'PollToken '.str_repeat('z', 43)])->assertStatus(401);
    }

    #[Test]
    public function firmware_is_uploaded_by_super_admin_and_downloadable_only_when_published_by_paired_devices(): void
    {
        Storage::fake('local');
        config(['devices.registration_secret' => 'test-registration-secret-123']);
        $admin = $this->superAdmin();
        $bin = $this->image('1.1.0');
        $file = UploadedFile::fake()->createWithContent('fw.bin', $bin);

        $this->actingAs($this->tenantUser())->post('/api/super/firmware', ['version' => '1.1.0', 'file' => $file], ['Accept' => 'application/json'])->assertStatus(403);
        $up = $this->actingAs($admin)->post('/api/super/firmware', ['version' => '1.1.0', 'notes' => 'OTA test', 'file' => $file], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.sha256', hash('sha256', $bin))->assertJsonPath('data.isPublished', false);
        $this->actingAs($admin)->post('/api/super/firmware', ['version' => '1.2.0', 'file' => UploadedFile::fake()->createWithContent('x.bin', str_repeat('A', 2048))], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['file']]]);
        // The version typed in the panel must be the one compiled into the binary.
        $this->actingAs($admin)->post('/api/super/firmware', ['version' => '1.3.0', 'file' => UploadedFile::fake()->createWithContent('y.bin', $this->image('1.0.0-ci.31'))], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['version']]]);
        $this->actingAs($admin)->post('/api/super/firmware', ['version' => '1.3.0', 'file' => UploadedFile::fake()->createWithContent('z.bin', "\xE9".random_bytes(4095))], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonStructure(['error' => ['fields' => ['file']]]);

        $h = $this->hall();
        $this->asSystem(fn () => $h['device']->forceFill(['status' => 'REVOKED', 'active_table_id' => null, 'active_hardware_id' => null])->save());
        $sim = new DeviceSimulator($this);
        $sim->register()->assertOk();
        $this->actingAs($this->tenantUser('CLIENT_OWNER', $h['tenant']))->postJson('/api/admin/devices/pair', ['code' => $sim->pairingCode, 'tableId' => $h['table']->public_id])->assertCreated();
        $this->app['auth']->forgetGuards();
        $sim->pairingStatus();

        $this->get('/device/v1/firmware/1.1.0', $sim->auth())->assertNotFound(); // not published yet
        $this->actingAs($admin)->postJson("/api/super/firmware/{$up->json('data.id')}/publish")->assertOk();
        $this->app['auth']->forgetGuards();

        $download = $this->get('/device/v1/firmware/1.1.0', $sim->auth())->assertOk()->assertHeader('X-Firmware-Sha256', hash('sha256', $bin))
            ->assertHeader('Content-Length', (string) strlen($bin)); // the firmware checks the size before flashing
        $this->assertSame($bin, $download->streamedContent());
        $this->get('/device/v1/firmware/1.1.0')->assertStatus(401);
    }

    #[Test]
    public function a_published_release_is_rolled_out_as_ota_commands_to_idle_devices_only(): void
    {
        Storage::fake('local');
        $admin = $this->superAdmin();
        $bin = $this->image('1.2.0');
        $release = $this->actingAs($admin)->post('/api/super/firmware', ['version' => '1.2.0', 'file' => UploadedFile::fake()->createWithContent('fw.bin', $bin)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        // Not published yet → nothing can be rolled out.
        $this->actingAs($admin)->postJson("/api/super/firmware/{$release}/rollout")->assertStatus(409);
        $this->actingAs($admin)->postJson("/api/super/firmware/{$release}/publish")->assertOk();

        $idle = $this->hall();
        $playing = $this->hall();
        $current = $this->hall();
        $this->asSystem(function () use ($playing, $current): void {
            $current['device']->forceFill(['firmware_version' => '1.2.0'])->save();
            DB::table('game_sessions')->insert([
                'public_id' => (string) Str::ulid(), 'tenant_id' => $playing['tenant']->id, 'branch_id' => $playing['branch']->id, 'table_id' => $playing['table']->id,
                'status' => 'ACTIVE', 'duration_minutes' => 60, 'start_at' => now(), 'end_at' => now()->addHour(), 'price_per_hour_snapshot' => 20000,
                'rounding_step_snapshot' => 1000, 'amount' => 20000, 'payment_status' => 'UNPAID', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->actingAs($admin)->postJson("/api/super/firmware/{$release}/rollout")->assertOk()
            ->assertJsonPath('data.queued', 1)->assertJsonPath('data.skippedBusy', 1)->assertJsonPath('data.alreadyCurrent', 1);
        // Repeating does not queue a second OTA for the same device.
        $this->actingAs($admin)->postJson("/api/super/firmware/{$release}/rollout")->assertOk()->assertJsonPath('data.queued', 0);
        $this->actingAs($this->tenantUser('CLIENT_OWNER', $idle['tenant']))->postJson("/api/super/firmware/{$release}/rollout")->assertStatus(403);

        $cmd = $this->asSystem(fn () => DeviceCommand::query()->where('type', 'OTA')->sole());
        $this->assertSame($idle['device']->id, $cmd->device_id);
        $this->assertEquals(['version' => '1.2.0', 'sha256' => hash('sha256', $bin), 'size' => strlen($bin)], $cmd->payload);
        $this->assertMatchesProtocol('device.command', ['commandId' => $cmd->public_id, 'type' => 'OTA', 'expiresAt' => $cmd->expires_at->getTimestamp(), 'payload' => $cmd->payload]);
    }

    /** A fake ESP32 app image: magic byte + the version marker the real build embeds. */
    private function image(string $version): string
    {
        return "\xE9".random_bytes(2000)."BLYFWVER:{$version}\n".random_bytes(2000);
    }
}
