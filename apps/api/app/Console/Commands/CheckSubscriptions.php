<?php

namespace App\Console\Commands;

use App\Domain\Subscriptions\Services\SubscriptionMonitor;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

final class CheckSubscriptions extends Command
{
    protected $signature = 'subscriptions:check';

    protected $description = 'Send subscription reminders (5/3/1/0 days) and record expiries (deduplicated)';

    public function handle(TenantContext $context, SubscriptionMonitor $monitor): int
    {
        $this->line(json_encode($context->runAsSystem(fn () => $monitor->check())));

        return self::SUCCESS;
    }
}
