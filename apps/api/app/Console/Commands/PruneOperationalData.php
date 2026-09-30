<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Retention (DATABASE.md §5): heartbeats 7 d, device commands 90 d, notifications 180 d. Audit logs are kept. */
final class PruneOperationalData extends Command
{
    protected $signature = 'platform:prune';

    protected $description = 'Delete expired operational rows (heartbeats, old device commands, old notifications)';

    public function handle(): int
    {
        $result = [
            'heartbeats' => DB::table('device_heartbeats')->where('received_at', '<', now()->subDays(7))->delete(),
            'commands' => DB::table('device_commands')->where('created_at', '<', now()->subDays(90))->whereNotIn('status', ['PENDING', 'SENT'])->delete(),
            'notifications' => DB::table('notifications')->where('created_at', '<', now()->subDays(180))->delete(),
        ];
        $this->line(json_encode($result));

        return self::SUCCESS;
    }
}
