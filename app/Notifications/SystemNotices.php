<?php

namespace App\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * The admins' technical alerts (backlog item 25): failed queue jobs, search index errors and the end of heavy
 * commands. Repeated failures are gathered: one alert per kind, told again after growing waits
 * (config alert_backoff_hours) with the count since the last one, and the search index's recovery is told once.
 */
class SystemNotices
{
    public static function queueFailed(string $job, Throwable $e): void
    {
        // An alert that itself fails can cause at most one more alert per wait (see throttled)
        self::throttled('system.queue_failed', fn (int $count) => new Notice(
            'system.queue_failed',
            'شکست کار صف',
            array_filter([
                'کار: ' . class_basename($job),
                'خطا: ' . Str::limit($e->getMessage(), 300),
                $count > 1 ? "شمار شکست‌ها از اعلان پیشین: {$count}" : null,
                'فهرست کارهای شکست‌خورده: php artisan queue:failed',
            ]),
            route('admin.dashboard'),
        ));
    }

    public static function searchFailed(Throwable $e): void
    {
        self::throttled('system.search_failed', fn (int $count) => new Notice(
            'system.search_failed',
            'خطای نمایه‌ی جستجو',
            array_filter([
                class_basename($e) . ': ' . Str::limit($e->getMessage(), 300),
                $count > 1 ? "شمار خطاها از اعلان پیشین: {$count}" : null,
                'جستجو و همگام‌سازی آثار با Meilisearch ممکن است از کار افتاده باشد.',
            ]),
            route('admin.dashboard'),
        ));
    }

    /** Whether the search index alerted and has been healthy since: then tells the admins once and starts afresh. */
    public static function checkSearchRecovered(): void
    {
        $type = 'system.search_failed';
        $last = Cache::get("notice-last:{$type}");
        if (! Cache::has("notice-level:{$type}") || ($last && $last > now()->subMinutes(10)->getTimestamp())) {
            return;
        }
        try {
            $healthy = app(\Meilisearch\Client::class)->isHealthy();
        } catch (Throwable) {
            $healthy = false;
        }
        if (! $healthy) {
            return;
        }
        foreach (['level', 'sent', 'count', 'last'] as $key) {
            Cache::forget("notice-{$key}:{$type}");
        }
        NotificationPreferences::broadcast($type, new Notice($type, 'جستجو دوباره کار می‌کند', ['نمایه‌ی جستجو (Meilisearch) پاسخ می‌دهد و در ده دقیقه‌ی گذشته خطایی نداشته است.'], route('admin.dashboard')));
    }

    /**
     * A dry run is not announced, nor a successful run shorter than heavy_command_min_minutes: the one who ran
     * it watched it end. A failure always is.
     */
    public static function commandFinished(string $command, int $exitCode, float $seconds): void
    {
        $ok = $exitCode === 0;
        if (str_contains($command, '--dry-run') || ($ok && $seconds < 60 * config('notifications.heavy_command_min_minutes', 0))) {
            return;
        }
        $minutes = $seconds >= 90 ? round($seconds / 60) . ' دقیقه' : round($seconds) . ' ثانیه';
        NotificationPreferences::broadcast('system.command_finished', new Notice(
            'system.command_finished',
            ($ok ? 'پایان دستور: ' : 'شکست دستور: ') . Str::before($command, ' '),
            [$command, 'مدت: ' . $minutes, $ok ? 'بی‌خطا پایان یافت.' : "کد خروج {$exitCode}؛ گزارش را در لاگ سرور ببینید."],
            route('admin.dashboard'),
        ));
    }

    /** Whether an artisan command is one of the heavy ones announced on production. */
    public static function isHeavy(?string $command): bool
    {
        return $command && Str::is(config('notifications.heavy_commands', []), $command);
    }

    private static function throttled(string $type, callable $make): void
    {
        try {
            $count = Cache::increment("notice-count:{$type}");
            Cache::put("notice-last:{$type}", now()->getTimestamp(), now()->addDays(3));
            $waits = config('notifications.alert_backoff_hours') ?: [1];
            $level = (int) Cache::get("notice-level:{$type}", 0);
            $hours = $waits[min($level, count($waits) - 1)];
            if (! Cache::add("notice-sent:{$type}", true, now()->addHours($hours))) {
                return;
            }
            Cache::put("notice-level:{$type}", $level + 1, now()->addHours($hours + 24));
            Cache::forget("notice-count:{$type}");
            NotificationPreferences::broadcast($type, $make((int) $count));
        } catch (Throwable) {
            // An alert about a broken cache or mailer would only fail the same way
        }
    }
}
