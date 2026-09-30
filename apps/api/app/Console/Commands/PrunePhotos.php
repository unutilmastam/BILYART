<?php

namespace App\Console\Commands;

use App\Domain\Photos\Services\PhotoService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

final class PrunePhotos extends Command
{
    protected $signature = 'photos:prune';

    protected $description = 'Delete session photos older than each client\'s retention setting (audited)';

    public function handle(TenantContext $context, PhotoService $photos): int
    {
        $count = $context->runAsSystem(fn () => $photos->pruneExpired());
        $this->info("Deleted {$count} photos past retention.");

        return self::SUCCESS;
    }
}
