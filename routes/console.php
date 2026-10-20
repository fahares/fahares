<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run every minute by a Coolify scheduled task (`php artisan schedule:run`): the queue holds
// notifications, and this drains it without a long-running worker process
Schedule::command('queue:work --stop-when-empty --tries=3 --max-time=50')
    ->everyMinute()
    ->withoutOverlapping(5);
Schedule::command('queue:prune-failed --hours=720')->daily();

// Figures of the insights page (/insights): heavy queries, so built once a night, not per view
Schedule::command('fahares:build-insights')->dailyAt('03:30')->withoutOverlapping(30);
