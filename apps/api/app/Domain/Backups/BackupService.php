<?php

namespace App\Domain\Backups;

use App\Domain\Platform\Models\SystemSetting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Portable logical backups (spec §44). Works on shared hosting without
 * mysqldump/pg_dump or exec(): every table is exported as JSON lines in
 * foreign-key order, zipped with a manifest (row counts + sha256), then
 * encrypted with BackupCrypto. Restore = same engine, same migrations.
 */
final class BackupService
{
    /** Business tables in creation (FK-safe) order. A test asserts nothing is missing. */
    public const TABLES = [
        'tenants', 'users', 'subscription_payments', 'subscription_payment_requests', 'subscriptions', 'subscription_events', 'system_settings', 'firmware_releases',
        'branches', 'working_hours', 'branch_closed_days', 'user_branch_access',
        'pricing_plans', 'devices', 'billiard_tables', // tables point to their ESP32 (device_id)
        'device_pairings', 'tablets', 'tablet_pairings',
        'game_sessions', 'session_photos', 'session_events', 'cash_collections', 'cash_notes',
        'device_commands', 'device_heartbeats',
        'telegram_integrations', 'telegram_chats', 'notifications', 'notification_logs',
        'audit_logs',
    ];

    /** Infrastructure / ephemeral tables that are deliberately not backed up. */
    public const EXCLUDED = ['migrations', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'web_sessions', 'idempotency_keys'];

    /** Generated columns are recomputed by the database. */
    private const SKIP_COLUMNS = ['table_lock'];

    public function __construct(private readonly BackupCrypto $crypto) {}

    /** @return array{file: string, size: int, rows: int} path relative to the local disk */
    public function run(): array
    {
        $work = $this->tempDir();
        try {
            $manifest = [
                'format' => 1,
                'createdAt' => now()->toIso8601ZuluString(),
                'driver' => DB::connection()->getDriverName(),
                'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(),
                'tables' => [],
            ];
            $rows = 0;
            foreach (self::TABLES as $table) {
                $file = "$work/$table.jsonl";
                $fh = fopen($file, 'wb');
                $count = 0;
                foreach (DB::table($table)->orderBy($this->orderColumn($table))->cursor() as $row) {
                    $data = array_diff_key((array) $row, array_flip(self::SKIP_COLUMNS));
                    fwrite($fh, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
                    $count++;
                }
                fclose($fh);
                $manifest['tables'][$table] = ['rows' => $count, 'sha256' => hash_file('sha256', $file)];
                $rows += $count;
            }
            file_put_contents("$work/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT));

            $zipPath = "$work/backup.zip";
            $zip = new ZipArchive;
            $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFile("$work/manifest.json", 'manifest.json');
            foreach (self::TABLES as $table) {
                $zip->addFile("$work/$table.jsonl", "$table.jsonl");
            }
            $zip->close();

            $name = config('backup.path').'/db/'.now()->format('Ymd-His').'.blyb';
            $encrypted = "$work/out.blyb";
            $this->crypto->encryptFile($zipPath, $encrypted);
            $this->disk()->put($name, fopen($encrypted, 'rb'));
            $size = (int) filesize($encrypted);

            $this->remember('backup.last_run', ['at' => now()->toIso8601ZuluString(), 'file' => $name, 'size' => $size, 'rows' => $rows, 'ok' => true]);

            return ['file' => $name, 'size' => $size, 'rows' => $rows];
        } catch (\Throwable $e) {
            $this->remember('backup.last_run', ['at' => now()->toIso8601ZuluString(), 'ok' => false, 'error' => class_basename($e)]);
            throw $e;
        } finally {
            $this->rmrf($work);
        }
    }

    /** Decrypts and checks every table file against the manifest. Does not touch the database. */
    public function verify(string $file): array
    {
        $work = $this->tempDir();
        try {
            $manifest = $this->unpack($file, $work);
            foreach ($manifest['tables'] as $table => $meta) {
                $path = "$work/$table.jsonl";
                if (! is_file($path) || hash_file('sha256', $path) !== $meta['sha256'] || $this->lines($path) !== $meta['rows']) {
                    throw new RuntimeException("Table {$table} does not match the manifest.");
                }
            }
            $result = ['at' => now()->toIso8601ZuluString(), 'file' => $file, 'ok' => true, 'tables' => count($manifest['tables']), 'createdAt' => $manifest['createdAt']];
            $this->remember('backup.last_verify', $result);

            return $result;
        } catch (\Throwable $e) {
            $this->remember('backup.last_verify', ['at' => now()->toIso8601ZuluString(), 'file' => $file, 'ok' => false, 'error' => $e->getMessage()]);
            throw $e;
        } finally {
            $this->rmrf($work);
        }
    }

    /** Replaces all business data with the backup's (same engine and migrations required). */
    public function restore(string $file): array
    {
        $work = $this->tempDir();
        try {
            $manifest = $this->unpack($file, $work);
            $driver = DB::connection()->getDriverName();
            if ($manifest['driver'] !== $driver) {
                throw new RuntimeException("Backup was made on {$manifest['driver']}, this database is {$driver}.");
            }
            $current = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
            if ($manifest['migrations'] !== $current) {
                throw new RuntimeException('Migrations differ from the backup. Restore into a database at the same version.');
            }

            $restored = [];
            DB::transaction(function () use ($work, &$restored): void {
                foreach (array_reverse(self::TABLES) as $table) {
                    DB::table($table)->delete();
                }
                foreach (self::TABLES as $table) {
                    $count = 0;
                    $batch = [];
                    $fh = fopen("$work/$table.jsonl", 'rb');
                    while (($line = fgets($fh)) !== false) {
                        $batch[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                        if (count($batch) === 200) {
                            DB::table($table)->insert($batch);
                            $count += 200;
                            $batch = [];
                        }
                    }
                    fclose($fh);
                    if ($batch !== []) {
                        DB::table($table)->insert($batch);
                        $count += count($batch);
                    }
                    $restored[$table] = $count;
                }
            });
            $this->resetSequences();

            return $restored;
        } finally {
            $this->rmrf($work);
        }
    }

    /** Keeps the newest N daily files plus N weekly (Sunday) files. */
    public function prune(): int
    {
        $files = collect($this->disk()->files(config('backup.path').'/db'))->filter(fn ($f) => str_ends_with($f, '.blyb'))->sort()->values();
        $keep = $files->slice(-config('backup.keep_daily'))->all();
        $sundays = $files->filter(fn ($f) => CarbonImmutable::createFromFormat('Ymd', substr(basename($f), 0, 8))->isSunday())->slice(-config('backup.keep_weekly'))->all();
        $deleted = 0;
        foreach ($files as $f) {
            if (! in_array($f, $keep, true) && ! in_array($f, $sundays, true)) {
                $this->disk()->delete($f);
                $deleted++;
            }
        }

        return $deleted;
    }

    /** Weekly encrypted archive of private session photos (storage/app/private/tenants). */
    public function photos(): ?array
    {
        $files = $this->disk()->allFiles('tenants');
        $work = $this->tempDir();
        try {
            $zipPath = "$work/photos.zip";
            $zip = new ZipArchive;
            $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            foreach ($files as $f) {
                $zip->addFromString($f, (string) $this->disk()->get($f));
            }
            $zip->addFromString('manifest.json', json_encode(['createdAt' => now()->toIso8601ZuluString(), 'files' => count($files)]));
            $zip->close();
            $name = config('backup.path').'/photos/'.now()->format('Ymd-His').'.blyb';
            $this->crypto->encryptFile($zipPath, "$work/out.blyb");
            $this->disk()->put($name, fopen("$work/out.blyb", 'rb'));

            $all = collect($this->disk()->files(config('backup.path').'/photos'))->sort()->values();
            foreach ($all->slice(0, max(0, $all->count() - config('backup.keep_photos'))) as $old) {
                $this->disk()->delete($old);
            }

            return ['file' => $name, 'files' => count($files)];
        } finally {
            $this->rmrf($work);
        }
    }

    public function latest(): ?string
    {
        return collect($this->disk()->files(config('backup.path').'/db'))->filter(fn ($f) => str_ends_with($f, '.blyb'))->sort()->last();
    }

    private function unpack(string $file, string $work): array
    {
        if (! $this->disk()->exists($file)) {
            throw new RuntimeException('Backup file not found.');
        }
        file_put_contents("$work/in.blyb", $this->disk()->readStream($file));
        $this->crypto->decryptFile("$work/in.blyb", "$work/backup.zip");
        $zip = new ZipArchive;
        if ($zip->open("$work/backup.zip") !== true) {
            throw new RuntimeException('Backup archive is unreadable.');
        }
        $zip->extractTo($work);
        $zip->close();
        $manifest = json_decode((string) file_get_contents("$work/manifest.json"), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== 1) {
            throw new RuntimeException('Unknown backup format.');
        }

        return $manifest;
    }

    private function resetSequences(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return; // MySQL/MariaDB continue after MAX(id) automatically
        }
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'id')) {
                continue;
            }
            $seq = DB::selectOne("SELECT pg_get_serial_sequence(?, 'id') AS s", [$table])?->s ?? null;
            if ($seq) {
                DB::statement("SELECT setval(?, COALESCE((SELECT MAX(id) FROM \"{$table}\"), 0) + 1, false)", [$seq]);
            }
        }
    }

    private function orderColumn(string $table): string
    {
        return match ($table) {
            'system_settings' => 'key',
            'user_branch_access' => 'user_id',
            default => 'id',
        };
    }

    private function lines(string $path): int
    {
        $n = 0;
        $fh = fopen($path, 'rb');
        while (fgets($fh) !== false) {
            $n++;
        }
        fclose($fh);

        return $n;
    }

    private function remember(string $key, array $value): void
    {
        SystemSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    private function tempDir(): string
    {
        $dir = storage_path('app/private/tmp/'.bin2hex(random_bytes(8)));
        mkdir($dir, 0700, true);

        return $dir;
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
