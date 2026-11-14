<?php

namespace App\Support;

/**
 * The day of the week of a Hijri date, to test a year against a date that names its weekday («جمعه 18 ذیحجه 119؟»;
 * step 14.184). The calendar is the tabular one (30-year cycle, leap years 2, 5, 7, 10, 13, 16, 18, 21, 24, 26, 29),
 * which a sighted month may differ from by a day, so near() allows one day either way.
 */
class HijriWeekday
{
    /** Index 0 is Monday, as the Julian day number modulo 7 gives it. */
    private const DAYS = ['دو' => 0, 'سه' => 1, 'چهار' => 2, 'پنج' => 3, 'جمعه' => 4, '' => 5, 'یک' => 6];

    private const MONTHS = [
        1 => 'محرم', 2 => 'صفر',
        3 => 'ربیع\s*(?:ال)?(?:اول|اولی)', 4 => 'ربیع\s*(?:ال)?(?:ثانی|آخر|اخر)',
        5 => 'جمادی\s*(?:ال)?(?:اول|اولی)', 6 => 'جمادی\s*(?:ال)?(?:ثانی|ثانیه|آخر|اخر|آخره|اخره|اخری)',
        7 => 'رجب', 8 => 'شعبان', 9 => 'رمضان', 10 => 'شوال',
        11 => 'ذ[یو]\s*(?:ال)?قعده', 12 => 'ذ[یو]\s*(?:ال)?حجه',
    ];

    /** The weekday (0 Monday … 6 Sunday) of a day of a Hijri month in the tabular calendar. */
    public static function of(int $year, int $month, int $day): int
    {
        return ($day + 29 * ($month - 1) + intdiv($month, 2) + ($year - 1) * 354 + intdiv(3 + 11 * $year, 30) + 1948439) % 7;
    }

    /**
     * The weekday, day and month a date text states, or null when it lacks one of them: «جمعه 18 ذیحجه 119؟» is
     * Friday, 18, 12. «غره» is the first day; «سلخ» and «اواخر» name no day.
     *
     * @return array{weekday: int, day: int, month: int}|null
     */
    public static function stated(?string $raw): ?array
    {
        $text = strtr((string) $raw, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            'ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ة' => 'ه', "\u{200c}" => ' ']);
        if (preg_match('/(?<!\p{L})(?:(یک|دو|سه|چهار|پنج)\s*شنبه|(جمعه|آدینه)|شنبه)(?!\p{L})/u', $text, $w)) {
            $weekday = ! empty($w[2]) ? self::DAYS['جمعه'] : self::DAYS[$w[1] ?? ''];
        } else {
            return null;
        }
        foreach (self::MONTHS as $month => $name) {
            if (preg_match('/(?:(?<!\d)(\d{1,2})|(غره))\s*(?:ماه\s*)?(?:شهر\s*)?' . $name . '(?!\p{L})/u', $text, $m)) {
                $day = ! empty($m[2]) ? 1 : (int) $m[1];

                return $day >= 1 && $day <= 30 ? ['weekday' => $weekday, 'day' => $day, 'month' => $month] : null;
            }
        }

        return null;
    }

    /** Whether a year gives the stated date its weekday, or the day before or after it. */
    public static function near(array $stated, int $year): bool
    {
        $apart = abs(self::of($year, $stated['month'], $stated['day']) - $stated['weekday']);

        return min($apart, 7 - $apart) <= 1;
    }
}
