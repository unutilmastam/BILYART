<?php

use App\Domain\Health\HealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

/*
| Scheduler — driven by one cPanel cron entry every minute: `php artisan schedule:run`
| (docs/DEPLOYMENT.md §4). withoutOverlapping() protects against slow runs on shared hosting.
*/

// Heartbeat read by /health/messaging: proves cron + schedule:run are alive.
Schedule::call(fn () => Cache::forever(HealthService::SCHEDULER_KEY, now()->getTimestamp()))->everyMinute()->name('scheduler-heartbeat');

Schedule::command('idempotency:prune')->hourly()->withoutOverlapping();
Schedule::command('sessions:finalize')->everyMinute()->withoutOverlapping(5);
Schedule::command('photos:prune')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('telegram:daily-reports')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('notifications:deliver')->everyMinute()->withoutOverlapping(5);
Schedule::command('devices:monitor')->everyMinute()->withoutOverlapping(5);
Schedule::command('subscriptions:check')->hourly()->withoutOverlapping();
Schedule::command('platform:prune')->dailyAt('02:30');
// Times are UTC: 22:00 UTC = 03:00 Asia/Tashkent.
Schedule::command('backup:run')->dailyAt('22:00')->withoutOverlapping(60);
Schedule::command('backup:run --photos')->weeklyOn(6, '23:00')->withoutOverlapping(120);
Schedule::command('backup:verify')->dailyAt('23:30');
