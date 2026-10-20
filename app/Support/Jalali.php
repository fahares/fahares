<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeInterface;
use IntlDateFormatter;

/**
 * Solar Hijri (Jalali) dates for display, in Tehran time with Persian digits.
 * Storage stays UTC (config/app.php); only the display is converted here.
 * Used in Blade as @jdate($date) / @jdate($date, 'long') and @jago($date).
 */
class Jalali
{
    public const TIMEZONE = 'Asia/Tehran';

    /** Named ICU patterns, so every page shows the same shape for the same purpose. */
    public const FORMATS = [
        'date' => 'y/MM/dd',                 // ۱۴۰۵/۰۷/۲۸
        'datetime' => 'y/MM/dd، HH:mm',        // ۱۴۰۵/۰۷/۲۸، ۱۶:۰۰
        'seconds' => 'y/MM/dd، HH:mm:ss',
        'long' => 'd MMMM y',                // ۲۸ مهر ۱۴۰۵
        'long_time' => 'd MMMM y، HH:mm',
        'year' => 'y',
    ];

    /** @var array<string, IntlDateFormatter> */
    private static array $formatters = [];

    /** A date in the named format (or a raw ICU pattern); empty string for null. */
    public static function format(?DateTimeInterface $date, string $format = 'date'): string
    {
        if ($date === null) {
            return '';
        }

        $pattern = self::FORMATS[$format] ?? $format;
        $formatter = self::$formatters[$pattern] ??= new IntlDateFormatter(
            'fa_IR@calendar=persian',
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            self::TIMEZONE,
            IntlDateFormatter::TRADITIONAL,
            $pattern,
        );

        return Fa::digits($formatter->format($date));
    }

    /** Relative time in Persian: «۳ ساعت پیش». */
    public static function ago(?DateTimeInterface $date): string
    {
        if ($date === null) {
            return '';
        }

        $carbon = $date instanceof CarbonInterface ? $date->copy() : \Illuminate\Support\Carbon::instance($date);

        return Fa::digits($carbon->locale('fa')->diffForHumans());
    }
}
