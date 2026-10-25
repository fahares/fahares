<?php

namespace App\Listeners;

use App\Notifications\SystemNotices;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;

/** Announces the end of long commands run on production, such as re-indexing or catalogs:* (backlog item 25). */
class NotifyHeavyCommandFinished
{
    private static array $started = [];

    public function handleStarting(CommandStarting $event): void
    {
        if (SystemNotices::isHeavy($event->command)) {
            self::$started[$event->command] = microtime(true);
        }
    }

    public function handleFinished(CommandFinished $event): void
    {
        if (! SystemNotices::isHeavy($event->command) || ! app()->isProduction()) {
            return;
        }
        $seconds = microtime(true) - (self::$started[$event->command] ?? microtime(true));
        $line = method_exists($event->input, '__toString') ? (string) $event->input : '';
        SystemNotices::commandFinished($line ?: $event->command, $event->exitCode, $seconds);
    }
}
