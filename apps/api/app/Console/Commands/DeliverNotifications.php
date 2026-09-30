<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Services\NotificationService;
use Illuminate\Console\Command;

final class DeliverNotifications extends Command
{
    protected $signature = 'notifications:deliver';

    protected $description = 'Send pending Telegram notifications';

    public function handle(NotificationService $notifications): int
    {
        $this->line('sent '.$notifications->deliverPending());

        return self::SUCCESS;
    }
}
