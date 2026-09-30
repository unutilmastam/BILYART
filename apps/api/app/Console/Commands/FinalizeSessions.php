<?php

namespace App\Console\Commands;

use App\Domain\Sessions\Services\SessionFinalizer;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

final class FinalizeSessions extends Command
{
    protected $signature = 'sessions:finalize';

    protected $description = 'Complete ended sessions, expire reservations, fail unacknowledged starts, record warnings';

    public function handle(TenantContext $context, SessionFinalizer $finalizer): int
    {
        $result = $context->runAsSystem(fn () => $finalizer->finalizeDue());
        $this->line(json_encode($result));

        return self::SUCCESS;
    }
}
