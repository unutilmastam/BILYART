<?php

namespace Tests\Feature\Ops;

use App\Domain\Backups\BackupCrypto;
use App\Domain\Backups\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BuildsSessionFixtures;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/** Spec §44: automated, encrypted, verifiable, restorable backups — proven on each DB engine. */
class BackupTest extends TestCase
{
    use BuildsSessionFixtures, CreatesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['backup.encryption_key' => base64_encode(str_repeat('k', 32))]);
    }

    private function fingerprint(): array
    {
        $out = [];
        foreach (BackupService::TABLES as $table) {
            $rows = DB::table($table)->get()->map(fn ($r) => array_diff_key((array) $r, ['table_lock' => 1]))->sortBy(fn ($r) => json_encode($r))->values();
            $out[$table] = [$rows->count(), md5(json_encode($rows))];
        }

        return $out;
    }

    private function populate(): array
    {
        $h = $this->hall();
        $this->tenantUser('CLIENT_OPERATOR', $h['tenant']);
        $id = $this->tabletPost($h['token'], '/api/tablet/sessions/prepare', ['tableId' => $h['table']->public_id, 'durationMinutes' => 30])->json('session.id');
        $this->tabletPost($h['token'], "/api/tablet/sessions/$id/start")->assertOk();
        $this->actingAs($this->superAdmin())->postJson("/api/super/tenants/{$h['tenant']->public_id}/payments", ['amount' => 500000, 'method' => 'CASH', 'days' => 30, 'note' => "Naqd — o'zbekcha matn"])->assertCreated();

        return $h;
    }

    #[Test]
    public function every_table_is_either_backed_up_or_deliberately_excluded(): void
    {
        $tables = collect(Schema::getTables())->pluck('name')->all();
        $unknown = array_diff($tables, BackupService::TABLES, BackupService::EXCLUDED);

        $this->assertSame([], array_values($unknown), 'New table not covered by backups: '.implode(', ', $unknown));
    }

    #[Test]
    public function backup_then_restore_brings_back_identical_data(): void
    {
        $this->populate();
        $before = $this->fingerprint();

        $result = app(BackupService::class)->run();
        $this->assertGreaterThan(10, $result['rows']);
        $raw = Storage::disk('local')->get($result['file']);
        $this->assertStringStartsWith('BLYBK1', $raw);
        $this->assertStringNotContainsString('Naqd', $raw); // encrypted, not just zipped

        // Damage the data, then restore.
        DB::table('session_events')->delete();
        DB::table('audit_logs')->delete();
        DB::table('subscription_payments')->update(['amount' => 1]);
        $this->hall(); // extra rows that must disappear

        app(BackupService::class)->restore($result['file']);
        $this->assertSame($before, $this->fingerprint());

        // New rows still get fresh ids after a restore (sequences/auto-increment continue).
        $this->hall();
        $this->assertGreaterThan(1, DB::table('tenants')->count());
    }

    #[Test]
    public function verify_detects_tampering_and_wrong_keys(): void
    {
        $this->populate();
        $file = app(BackupService::class)->run()['file'];

        $this->artisan('backup:verify', ['file' => $file])->assertSuccessful();

        $bytes = Storage::disk('local')->get($file);
        $bytes[200] = $bytes[200] === 'A' ? 'B' : 'A';
        Storage::disk('local')->put('backups/db/tampered.blyb', $bytes);
        $this->artisan('backup:verify', ['file' => 'backups/db/tampered.blyb'])->assertFailed();

        Storage::disk('local')->put('backups/db/truncated.blyb', substr(Storage::disk('local')->get($file), 0, 100));
        $this->artisan('backup:verify', ['file' => 'backups/db/truncated.blyb'])->assertFailed();

        $this->app->instance(BackupCrypto::class, new BackupCrypto(str_repeat('x', 32)));
        $this->expectException(\RuntimeException::class);
        app(BackupService::class)->verify($file);
    }

    #[Test]
    public function restore_requires_force_and_refuses_another_engine(): void
    {
        $file = app(BackupService::class)->run()['file'];
        $this->artisan('backup:restore', ['file' => $file])->assertFailed();

        $crypto = app(BackupCrypto::class);
        $tmp = tempnam(sys_get_temp_dir(), 'b');
        $crypto->decryptFile(Storage::disk('local')->path($file), $tmp);
        $zip = new \ZipArchive;
        $zip->open($tmp);
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $manifest['driver'] = 'sqlsrv';
        $zip->addFromString('manifest.json', json_encode($manifest));
        $zip->close();
        $crypto->encryptFile($tmp, Storage::disk('local')->path('backups/db/other.blyb'));

        $this->expectExceptionMessage('Backup was made on sqlsrv');
        app(BackupService::class)->restore('backups/db/other.blyb');
    }

    #[Test]
    public function retention_keeps_recent_daily_and_sunday_weekly_files(): void
    {
        config(['backup.keep_daily' => 3, 'backup.keep_weekly' => 2]);
        foreach (['20260901', '20260906', '20260913', '20260920', '20260925', '20260926', '20260927', '20260928'] as $d) {
            Storage::disk('local')->put("backups/db/{$d}-220000.blyb", 'x');
        }
        app(BackupService::class)->prune();

        // 3 newest (26, 27, 28) + 2 newest Sundays (20, 27).
        $left = collect(Storage::disk('local')->files('backups/db'))->map(fn ($f) => substr(basename($f), 0, 8))->sort()->values()->all();
        $this->assertSame(['20260920', '20260926', '20260927', '20260928'], $left);
    }

    #[Test]
    public function photos_are_archived_encrypted(): void
    {
        Storage::disk('local')->put('tenants/1/sessions/01ABCDEFGHJKMNPQRSTVWXYZ00/01ABCDEFGHJKMNPQRSTVWXYZ01.jpg', 'jpegbytes');
        $result = app(BackupService::class)->photos();

        $this->assertSame(1, $result['files']);
        $this->assertStringNotContainsString('jpegbytes', Storage::disk('local')->get($result['file']));
    }
}
