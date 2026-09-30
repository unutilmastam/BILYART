<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Idempotency keys are kept 48 h (DATABASE.md §5). Stale in-flight rows (crashed requests) are released after 10 min. */
final class PruneIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = 'Delete idempotency keys older than 48 hours and stuck in-flight keys';

    public function handle(): int
    {
        $old = DB::table('idempotency_keys')->where('created_at', '<', now()->subHours(48))->delete();
        $stuck = DB::table('idempotency_keys')->whereNull('response_code')->where('created_at', '<', now()->subMinutes(10))->delete();
        $this->info("Pruned {$old} expired and {$stuck} stuck keys.");

        return self::SUCCESS;
    }
}
