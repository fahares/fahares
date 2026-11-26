<?php

namespace App\Support;

use App\Models\Announcement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use IntlCalendar;
use InvalidArgumentException;

/**
 * Backlog item 136, part 4 (step 14.414): the advance notice of a planned maintenance. It is set with
 * `php artisan fahares:maintenance-notice` and lives in the cache (the database store, so a deploy keeps
 * it) until the maintenance should be over. Every page then shows a strip above its header
 * (x-maintenance-notice), and the site's Telegram channel gets a post through a notice that is not shown
 * on the site itself (Announcement with on_site off).
 */
class MaintenanceNotice
{
    public const CACHE_KEY = 'maintenance.notice';

    public const TITLE = 'نگهداری برنامه‌ریزی‌شدۀ سرور فهارس';

    /** @return array{at:int, until:int, note:string, announcement_id:?int}|null */
    public static function current(): ?array
    {
        $notice = Cache::get(self::CACHE_KEY);

        return is_array($notice) && ($notice['until'] ?? 0) > now()->getTimestamp() ? $notice : null;
    }

    /**
     * The start in Tehran time: a Solar Hijri «1405/09/06 02:00» (Persian or Latin digits, «/» or «-»),
     * or only «02:00» for its next coming.
     */
    public static function parseStart(string $text, ?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now();
        $latin = strtr(trim($text), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $latin, $m)) {
            $at = $now->setTimezone(Jalali::TIMEZONE)->setTime((int) $m[1], (int) $m[2]);
            if ($at->lte($now)) {
                $at = $at->addDay();
            }

            return $at->utc();
        }
        if (! preg_match('#^(1[34]\d\d)[/-](\d{1,2})[/-](\d{1,2})\s+(\d{1,2}):(\d{2})$#', $latin, $m)) {
            throw new InvalidArgumentException('زمان را به شکل «1405/09/06 02:00» یا «02:00» بدهید (به وقت تهران).');
        }
        [$y, $mo, $d, $h, $i] = array_map('intval', array_slice($m, 1));
        if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31 || $h > 23 || $i > 59) {
            throw new InvalidArgumentException("تاریخ یا ساعت «{$text}» درست نیست.");
        }
        $cal = IntlCalendar::createInstance(Jalali::TIMEZONE, 'fa_IR@calendar=persian');
        $cal->clear();
        $cal->set($y, $mo - 1, $d, $h, $i, 0);

        return CarbonImmutable::createFromTimestamp(intdiv((int) $cal->getTime(), 1000))->utc();
    }

    /** «شنبه ۶ آذر ۱۴۰۵، ساعت ۰۲:۰۰» */
    public static function when(int $timestamp): string
    {
        $date = CarbonImmutable::createFromTimestamp($timestamp);

        return Jalali::format($date, 'EEEE d MMMM y') . '، ساعت ' . Jalali::format($date, 'HH:mm');
    }

    public static function minutes(array $notice): int
    {
        return (int) round(($notice['until'] - $notice['at']) / 60);
    }

    /** The sentence the strip and the Telegram post share */
    public static function sentence(array $notice): string
    {
        $minutes = Fa::digits((string) self::minutes($notice));

        return 'سرور فهارس ' . self::when($notice['at']) . ' به وقت تهران، حدود ' . $minutes
            . ' دقیقه برای ارتقا یا نگهداری در دسترس نخواهد بود.';
    }

    /** Sets (or moves) the notice; returns it, with the Telegram notice's id when one is made. */
    public static function set(CarbonImmutable $at, int $minutes, string $note = '', bool $telegram = false): array
    {
        if ($minutes < 1 || $minutes > 24 * 60) {
            throw new InvalidArgumentException('مدت باید میان ۱ دقیقه و ۲۴ ساعت باشد.');
        }
        if ($at->lte(CarbonImmutable::now())) {
            throw new InvalidArgumentException('زمان آغاز گذشته است.');
        }
        $notice = [
            'at' => $at->getTimestamp(),
            'until' => $at->addMinutes($minutes)->getTimestamp(),
            'note' => trim($note),
            'announcement_id' => self::current()['announcement_id'] ?? null,
        ];

        if ($telegram) {
            $a = $notice['announcement_id'] ? Announcement::find($notice['announcement_id']) : null;
            $a ??= new Announcement(['style' => 'card', 'audience' => 'all', 'on_site' => false, 'telegram' => true]);
            $a->fill([
                'title' => self::TITLE,
                'body' => trim(self::sentence($notice) . "\n" . $notice['note']),
                'is_active' => true,
                'starts_at' => now(),
                'ends_at' => CarbonImmutable::createFromTimestamp($notice['until']),
                'days' => 1,
            ])->save();
            $notice['announcement_id'] = $a->id;
        }

        Cache::put(self::CACHE_KEY, $notice, CarbonImmutable::createFromTimestamp($notice['until']));

        return $notice;
    }

    public static function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
