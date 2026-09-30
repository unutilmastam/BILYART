<?php

namespace App\Console\Commands;

use App\Domain\Backups\BackupService;
use Illuminate\Console\Command;

final class BackupRun extends Command
{
    protected $signature = 'backup:run {--photos : Back up private session photos instead of the database}';

    protected $description = 'Encrypted backup of the database (default) or of private photos; prunes old files';

    public function handle(BackupService $backups): int
    {
        if ($this->option('photos')) {
            $this->line(json_encode($backups->photos()));

            return self::SUCCESS;
        }
        $result = $backups->run();
        $pruned = $backups->prune();
        $this->info("Backup {$result['file']} ({$result['rows']} rows, {$result['size']} bytes); pruned {$pruned}.");

        return self::SUCCESS;
    }
}
