<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Services\NotificationService;
use App\Domain\Telegram\Services\TelegramService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

final class TelegramDailyReports extends Command
{
    protected $signature = 'telegram:daily-reports';

    protected $description = 'Queue each branch\'s daily report once its report time has passed (deduplicated) and deliver pending messages';

    public function handle(TenantContext $context, TelegramService $telegram, NotificationService $notifications): int
    {
        $queued = $context->runAsSystem(fn () => $telegram->queueDailyReports());
        $sent = $notifications->deliverPending();
        $this->line("queued {$queued}, sent {$sent}");

        return self::SUCCESS;
    }
}
