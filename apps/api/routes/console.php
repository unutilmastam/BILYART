<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduler — driven by one cPanel cron entry every minute: `php artisan schedule:run`
| (docs/DEPLOYMENT.md §4). withoutOverlapping() protects against slow runs on shared hosting.
*/

Schedule::command('idempotency:prune')->hourly()->withoutOverlapping();
