<?php

namespace App\Console\Commands;

use App\Domain\Devices\Services\DeviceMonitor;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

final class MonitorDevices extends Command
{
    protected $signature = 'devices:monitor';

    protected $description = 'Detect devices going offline/online and notify once per episode';

    public function handle(TenantContext $context, DeviceMonitor $monitor): int
    {
        $this->line(json_encode($context->runAsSystem(fn () => $monitor->check())));

        return self::SUCCESS;
    }
}
