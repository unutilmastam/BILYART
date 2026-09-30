<?php

namespace App\Console\Commands;

use App\Domain\Backups\BackupService;
use Illuminate\Console\Command;

/** docs/BACKUP.md. Destructive: replaces all business data with the backup's. */
final class BackupRestore extends Command
{
    protected $signature = 'backup:restore {file : Path on the private disk, e.g. backups/db/20261005-220000.blyb} {--force : Required confirmation}';

    protected $description = 'Restore a database backup (same engine and migrations); replaces current data';

    public function handle(BackupService $backups): int
    {
        if (! $this->option('force')) {
            $this->error('This replaces ALL data. Re-run with --force to confirm. Take a fresh backup first (php artisan backup:run).');

            return self::FAILURE;
        }
        $this->call('down');
        try {
            $counts = $backups->restore((string) $this->argument('file'));
            $this->info('Restored: '.json_encode($counts));
        } finally {
            $this->call('up');
        }

        return self::SUCCESS;
    }
}
