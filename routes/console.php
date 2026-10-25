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

// The admin queues «بازبینی اشخاص» (item 78): new namesake groups and mixed records after the day's
// merges, splits and imports; after the insights build, so the two heavy jobs do not overlap
Schedule::command('fahares:refresh-person-reviews')->dailyAt('04:15')->withoutOverlapping(30);

// Notifications (backlog item 25): the daily digest in the morning, Tehran time, and the weekly reminder of
// suggestions left unreviewed on Saturday morning
Schedule::command('notifications:digest')->dailyAt('07:30')->timezone('Asia/Tehran')->withoutOverlapping(30);
Schedule::command('notifications:stale-suggestions')->saturdays()->at('08:00')->timezone('Asia/Tehran');
