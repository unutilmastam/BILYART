<?php

namespace Tests\Feature\Photos;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Photos\Models\SessionPhoto;
use App\Domain\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\AssertsProtocol;
use Tests\Concerns\BuildsSessionFixtures;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Spec §22–23, §43 items 11–12, §58–59. */
class PhotoTest extends TestCase
{
    use AssertsProtocol, BuildsSessionFixtures, CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function jpeg(int $w = 640, int $h = 480, string $extra = '', bool $withExif = false): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 30, 120, 60));
        ob_start();
        imagejpeg($img, null, 90);
        $bytes = (string) ob_get_clean();
        if ($withExif) {
            $payload = "Exif\0\0GPSLatitude=41.311081;SECRET-LOCATION";
            $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
            $bytes = substr($bytes, 0, 2).$segment.substr($bytes, 2);
        }

        return $bytes.$extra;
    }

    private function upload(string $token, string $sessionId, string $bytes, string $name = 'photo.jpg')
    {
        $path = tempnam(sys_get_temp_dir(), 'ph');
        file_put_contents($path, $bytes);

        return $this->withToken($token)->post("/api/tablet/sessions/$sessionId/photo", [
            'photo' => new UploadedFile($path, $name, null, null, true),
        ], ['Idempotency-Key' => (string) Str::uuid(), 'Accept' => 'application/json']);
    }

    private function reserved(array $h): string
    {
        return $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->json('session.id');
    }

    #[Test]
    public function the_photo_is_re_encoded_stored_privately_and_enables_start(): void
    {
        $h = $this->hall([], ['photo_required' => true]);
        $id = $this->reserved($h);

        $res = $this->upload($h['token'], $id, $this->jpeg(withExif: true, extra: '<?php system($_GET["c"]); ?>'), 'evil.php.jpg')
            ->assertOk()->assertJsonPath('session.hasPhoto', true);
        $this->assertMatchesProtocol('tablet.session', $res->json());

        $photo = $this->asSystem(fn () => SessionPhoto::query()->sole());
        $this->assertMatchesRegularExpression("#^tenants/{$h['tenant']->id}/sessions/{$id}/[0-9A-Z]{26}\\.jpg$#", $photo->storage_path);
        $stored = Storage::disk('local')->get($photo->storage_path);
        $this->assertStringStartsWith("\xFF\xD8", $stored);
        $this->assertStringNotContainsString('<?php', $stored);
        $this->assertStringNotContainsString('SECRET-LOCATION', $stored);
        $this->assertSame(hash('sha256', $stored), $photo->sha256);
        $this->assertSame([640, 480], [$photo->width, $photo->height]);
        $this->assertStringNotContainsString(public_path(), Storage::disk('local')->path($photo->storage_path));

        $this->tabletPost($h['token'], "/api/tablet/sessions/$id/start")->assertOk();
        $this->upload($h['token'], $id, $this->jpeg())->assertStatus(409); // only while RESERVED
    }

    #[Test]
    public function non_images_and_out_of_range_images_are_rejected(): void
    {
        $h = $this->hall();
        $id = $this->reserved($h);

        $this->upload($h['token'], $id, '<?php echo 1; ?>', 'x.jpg')->assertStatus(422)->assertJsonPath('error.code', 'PHOTO_INVALID');
        $this->upload($h['token'], $id, "GIF89a\x01\x00\x01\x00", 'x.gif')->assertStatus(422);
        $this->upload($h['token'], $id, $this->jpeg(200, 200))->assertStatus(422);
        $this->upload($h['token'], $id, $this->jpeg(3000, 400))->assertStatus(422);
        $this->upload($h['token'], $id, str_repeat('A', 3 * 1024 * 1024))->assertStatus(422);
        $this->assertSame(0, DB::table('session_photos')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[Test]
    public function retaking_before_start_replaces_the_photo_and_its_file(): void
    {
        $h = $this->hall();
        $id = $this->reserved($h);
        $this->upload($h['token'], $id, $this->jpeg())->assertOk();
        $first = $this->asSystem(fn () => SessionPhoto::query()->sole()->storage_path);

        $this->upload($h['token'], $id, $this->jpeg(800, 600))->assertOk();
        $photo = $this->asSystem(fn () => SessionPhoto::query()->sole());
        $this->assertNotSame($first, $photo->storage_path);
        $this->assertFalse(Storage::disk('local')->exists($first));
        $this->assertSame(800, $photo->width);
    }

    #[Test]
    public function item_11_only_authorized_users_of_the_same_tenant_can_view_and_views_are_audited(): void
    {
        $h = $this->hall();
        $id = $this->reserved($h);
        $this->upload($h['token'], $id, $this->jpeg())->assertOk();
        $photoId = $this->asSystem(fn () => SessionPhoto::query()->sole()->public_id);

        $manager = $this->tenantUser('CLIENT_MANAGER', $h['tenant']);
        $this->actingAs($manager)->get("/api/admin/photos/$photoId")->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(1, $this->asSystem(fn () => AuditLog::query()->where('action', 'photo.viewed')->count()));

        // Other tenant, operator (default), super admin, guest.
        $this->actingAs($this->tenantUser('CLIENT_OWNER'))->getJson("/api/admin/photos/$photoId")->assertNotFound();
        $operator = $this->tenantUser('CLIENT_OPERATOR', $h['tenant']);
        $this->actingAs($operator)->getJson("/api/admin/photos/$photoId")->assertStatus(403);
        $this->actingAs($this->superAdmin())->getJson("/api/admin/photos/$photoId")->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->getJson("/api/admin/photos/$photoId")->assertStatus(401);

        // The tenant may allow operators.
        $this->asSystem(fn () => Tenant::query()->whereKey($h['tenant']->id)->update(['settings' => json_encode(['operators_can_view_photos' => true])]));
        $this->actingAs($operator)->get("/api/admin/photos/$photoId")->assertOk();
    }

    #[Test]
    public function item_12_deleted_photos_are_gone_and_the_deletion_is_audited(): void
    {
        $h = $this->hall();
        $id = $this->reserved($h);
        $this->upload($h['token'], $id, $this->jpeg())->assertOk();
        $photo = $this->asSystem(fn () => SessionPhoto::query()->sole());
        $owner = $this->tenantUser('CLIENT_OWNER', $h['tenant']);

        $this->actingAs($this->tenantUser('CLIENT_OPERATOR', $h['tenant']))->deleteJson("/api/admin/photos/{$photo->public_id}")->assertStatus(403);
        $this->actingAs($owner)->deleteJson("/api/admin/photos/{$photo->public_id}")->assertNoContent();

        $this->assertFalse(Storage::disk('local')->exists($photo->storage_path));
        $this->actingAs($owner)->getJson("/api/admin/photos/{$photo->public_id}")->assertNotFound();
        $this->actingAs($owner)->deleteJson("/api/admin/photos/{$photo->public_id}")->assertNotFound();
        $row = $this->asSystem(fn () => $photo->fresh());
        $this->assertSame('MANUAL', $row->delete_reason);
        $this->assertSame($owner->id, $row->deleted_by);
        $this->assertSame(1, $this->asSystem(fn () => AuditLog::query()->where('action', 'photo.deleted')->count()));
    }

    #[Test]
    public function retention_job_deletes_old_photos_per_tenant_setting(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-01 10:00:00'));
        $a = $this->hall([], ['photo_required' => false, 'photo_retention_days' => 7]);
        $b = $this->hall(); // default 30 days
        $this->upload($a['token'], $this->reserved($a), $this->jpeg())->assertOk();
        $this->upload($b['token'], $this->reserved($b), $this->jpeg())->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00:00'));
        $this->artisan('photos:prune')->assertSuccessful();

        $rows = $this->asSystem(fn () => SessionPhoto::query()->orderBy('tenant_id')->get(['tenant_id', 'deleted_at', 'delete_reason']));
        $this->assertNotNull($rows->firstWhere('tenant_id', $a['tenant']->id)->deleted_at);
        $this->assertSame('RETENTION', $rows->firstWhere('tenant_id', $a['tenant']->id)->delete_reason);
        $this->assertNull($rows->firstWhere('tenant_id', $b['tenant']->id)->deleted_at);
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    #[Test]
    public function another_tablet_cannot_upload_to_a_foreign_session(): void
    {
        $a = $this->hall();
        $b = $this->hall();
        $id = $this->reserved($a);

        $this->upload($b['token'], $id, $this->jpeg())->assertNotFound();
    }
}
