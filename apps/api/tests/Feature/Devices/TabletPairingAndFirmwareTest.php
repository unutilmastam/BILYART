<?php

namespace Tests\Feature\Devices;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
        $bin = "\xE9".random_bytes(4095);
        $file = UploadedFile::fake()->createWithContent('fw.bin', $bin);

        $this->actingAs($this->tenantUser())->post('/api/super/firmware', ['version' => '1.1.0', 'file' => $file], ['Accept' => 'application/json'])->assertStatus(403);
        $up = $this->actingAs($admin)->post('/api/super/firmware', ['version' => '1.1.0', 'notes' => 'OTA test', 'file' => $file], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.sha256', hash('sha256', $bin))->assertJsonPath('data.isPublished', false);
        $this->actingAs($admin)->post('/api/super/firmware', ['version' => '1.2.0', 'file' => UploadedFile::fake()->createWithContent('x.bin', str_repeat('A', 2048))], ['Accept' => 'application/json'])
            ->assertStatus(422);

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

        $download = $this->get('/device/v1/firmware/1.1.0', $sim->auth())->assertOk()->assertHeader('X-Firmware-Sha256', hash('sha256', $bin));
        $this->assertSame($bin, $download->streamedContent());
        $this->get('/device/v1/firmware/1.1.0')->assertStatus(401);
    }
}
