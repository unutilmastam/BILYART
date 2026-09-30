<?php

namespace App\Console\Commands;

use App\Domain\Backups\BackupService;
use Illuminate\Console\Command;

final class BackupVerify extends Command
{
    protected $signature = 'backup:verify {file? : Path on the private disk (default: latest)}';

    protected $description = 'Decrypt a backup and check every table against its manifest (read-only)';

    public function handle(BackupService $backups): int
    {
        $file = $this->argument('file') ?? $backups->latest();
        if ($file === null) {
            $this->error('No backup found.');

            return self::FAILURE;
        }
        try {
            $this->line(json_encode($backups->verify($file)));
        } catch (\Throwable $e) {
            $this->error('VERIFY FAILED: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
