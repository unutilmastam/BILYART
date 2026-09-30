<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduler — driven by one cPanel cron entry every minute: `php artisan schedule:run`
| (docs/DEPLOYMENT.md §4). withoutOverlapping() protects against slow runs on shared hosting.
*/

Schedule::command('idempotency:prune')->hourly()->withoutOverlapping();
Schedule::command('sessions:finalize')->everyMinute()->withoutOverlapping(5);
Schedule::command('photos:prune')->dailyAt('01:30')->withoutOverlapping();
