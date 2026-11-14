<?php

namespace App\Support;

/**
 * Extracts a sortable Hijri year from a free-text catalog date such as
 * «یک‌شنبه ۲۳ ربیع الاول ۱۲۳۶ق». Returns null when there is no year
 * («سده‌ی ۱۳»). A date whose only year is solar or Gregorian («۱۳۵۰ش», «۱۸۲۰م») gives the first Hijri year that
 * year spans (otherCalendar, step 14.181).
 */
class HijriYear
{
    public const MIN = 100;
    public const MAX = 1500;

    private const YEAR = '(?<!\d)(\d{3,4})(?!\d)';
    // Folio and page counts are not years
    private const FOLIO_WORD = '(?:برگ|ورق|صفحه|ص\\.|گ\\.)';
    private const MONTH = '(?:محرم|صفر|ربیع|ربيع|جمادی|جمادى|رجب|شعبان|رمضان|شوال|ذی\s*‌?\s*القعده|ذی\s*‌?\s*قعده|ذیقعده|ذوالقعده|ذی\s*‌?\s*الحجه|ذی\s*‌?\s*حجه|ذیحجه|ذوالحجه)';
    private const OTHER_MARK = '\s*(?:ه[ـ]?\.?\s*)?(?:م(?!\p{L})|میلادی|ش(?!\p{L})|خ(?!\p{L})|شمسی|خورشیدی|AD|CE)';
    // A year of another era, which the catalogue sets beside the Hijri one: «996 یزدجرد», «1250 یزدگردی», «1240 فصلی»
    private const OTHER_ERA = '\s*(?:یزدجرد|یزدگرد|فصلی)';
    // The months of the solar year and the signs that stand for them: «12 تیر 1331 مطابق با 10 شوال 1371», «4 اسد 1318»
    private const SOLAR_MONTH = '(?<!\p{L})(?:فروردین|اردیبهشت|اردی‌بهشت|خرداد|تیر|مرداد|امرداد|شهریور|مهر|آبان|آذر|دی|بهمن|اسفند|حمل|ثور|جوزا|سرطان|اسد|سنبله|میزان|عقرب|قوس|جدی|دلو|حوت)(?:\s*ماه)?';
    // What follows these is not the date of the copy: a later owner's year, or the year of the copy it was made from
    private const ASIDE = '(?:تملک|از\s+روی\s+(?:خط|نسخه)|از\s+نسخه‌ای)';
    // A solar year lies this many years before the Hijri year it is set beside (1304 and 1343, 1378 and 1429)
    private const SOLAR_GAP = [35, 55];

    public static function parse(?string $raw): ?int
    {
        return self::all($raw)[0] ?? self::otherCalendar($raw)[0] ?? null;
    }

    private const SOLAR_MARK = '\s*(?:ه[ـ]?\.?\s*)?(?:ش(?!\p{L})|خ(?!\p{L})|شمسی|خورشیدی)';
    private const GREGORIAN_MARK = '\s*(?:م(?!\p{L})|میلادی|AD|CE)';

    /**
     * The Hijri years a solar or Gregorian year spans, when a date names no Hijri year (step 14.181): «1370ش» (21
     * March 1991 to 20 March 1992) is 1411-1412, «1886م» 1303-1304, «1314/8/12ش» 1353-1354. The first such year in
     * the text counts; the text itself stays as the catalogue has it.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function otherCalendar(?string $raw): ?array
    {
        $text = strtr((string) $raw, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        if (! preg_match('#(?<![\d/])(\d{3,4})((?:/\d{1,2}){0,2})(' . self::SOLAR_MARK . '|' . self::GREGORIAN_MARK . ')#u', $text, $m)) {
            return null;
        }
        $year = (int) $m[1];
        if (preg_match('/^' . self::SOLAR_MARK . '$/u', $m[3])) {
            // a catalogue's solar dates are of the last century; «317/11/7ش» is 1317 written short, not year 317
            if ($year < 1200 || $year > 1500) {
                return null;
            }
            // Nowruz of solar year S falls on about 21 March of S + 621
            return [self::hijriOf($year + 621, 3, 21), self::hijriOf($year + 622, 3, 20)];
        }
        if ($year < 1000 || $year > 2100) {
            return null;
        }

        return [self::hijriOf($year, 1, 1), self::hijriOf($year, 12, 31)];
    }

    /** The Hijri year (tabular calendar) a Gregorian day falls in. */
    private static function hijriOf(int $y, int $m, int $d): int
    {
        // Julian day number of a Gregorian date
        $a = intdiv(14 - $m, 12);
        $yy = $y + 4800 - $a;
        $mm = $m + 12 * $a - 3;
        $jdn = $d + intdiv(153 * $mm + 2, 5) + 365 * $yy + intdiv($yy, 4) - intdiv($yy, 100) + intdiv($yy, 400) - 32045;

        return intdiv(30 * ($jdn - 1948440) + 10646, 10631);
    }

    /**
     * Every Hijri year a date text names, in order (CopyDateRange takes its range from these). Left out, besides
     * other calendars and folio counts (step 14.175):
     *   - a year written short beside its full form in the same clause: «1012=112ق», «126 (گویا 1126)», «1045ق (145)»;
     *   - a three-digit number without «ق» more than 200 years before the first four-digit year of its clause:
     *     «138-1309ق», «شهر المسائل 111 و دی 1333»; no copy's one date spans two centuries and more;
     *   - a three-digit day before a month, with a year after it: «232 صفر 839ق», «104 (کذ) شوال 1294ق»;
     *   - a page, shelf or volume reference in a bracket: «(ص 103)», «الذریعة (186/25)», «(فهرست طوپ قاپی 455/3)»
     *     («ج 1008ق» is Jumada); a solar date with slashes: «317/11/7ش»;
     *   - a year of another era: «996 یزدجرد», «1240 فصلی», «1323 خ»; and the year the catalogue corrects:
     *     «1099 که صحیح آن 1299ق است» (step 14.186).
     * Left out as well (step 14.186): a year after a solar month that lies 35 to 55 years before another year of the
     * text («12 تیر 1331 مطابق با 10 شوال 1371»); and, when the text has another year, a year after «تملک», «از روی خط»
     * or «از نسخه‌ای» that follow a year (an owner's year, the year of the copy it was made from), and a year in a
     * bracket that goes on to call it a mistake («1271ق (در نسخه 1071 سهو القلم است)»).
     *
     * @return int[]
     */
    public static function all(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $text = strtr($raw, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        if (! preg_match_all('/' . self::YEAR . '/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $found = [];
        foreach ($matches[1] as [$digits, $offset]) {
            $before = substr($text, 0, $offset);
            $after = substr($text, $offset + strlen($digits));
            if (preg_match('/^' . self::OTHER_MARK . '/u', $after) || preg_match('/' . self::FOLIO_WORD . '\s*$/u', $before)
                || preg_match('/\(\s*(?:ص|ش|ج)\.?\s*$/u', $before)
                || preg_match('#^(?:/\d+)+' . self::OTHER_MARK . '#u', $after)
                || (preg_match('#\([^)]*$#u', $before) && preg_match('#^\s*/\s*\d+\s*\)#u', $after))
                || preg_match('/^' . self::OTHER_ERA . '/u', $after)
                || preg_match('/^\s*ق?\s*که\s+صحیح\s+آن/u', $after)
                || preg_match('#\(\s*\d+\s*/\s*$#u', $before)) {
                continue;
            }
            if (self::inRange((int) $digits)) {
                // the clause it stands in: what lies between the «،» or «؛» before it and the one after it
                $clause = preg_match_all('/[،؛]/u', $before, $m, PREG_OFFSET_CAPTURE) ? count($m[0]) : 0;
                // a year that falls when the text has another: after «تملک» or «از روی خط» that follow a year, or in a
                // bracket that goes on to call it a mistake («(در نسخه 1071 سهو القلم است)»; in «705ق (این تاریخ اشتباه و
                // احتمالاً 905 صحیح باشد)» the bracket corrects the year before it, and both stay)
                $weak = preg_match('/\d{3,4}.*' . self::ASIDE . '/us', $before)
                    || (preg_match('#\([^()]*$#u', $before) && preg_match('/^[^()]*(?:اشتباه|سهو)/u', $after));
                $solar = (bool) preg_match('/' . self::SOLAR_MONTH . '\s*$/u', $before);
                $found[] = ['year' => (int) $digits, 'weak' => (bool) $weak, 'solar' => $solar, 'clause' => $clause, 'day' => (bool) preg_match('/^\s*(?:\(\s*کذا?\s*\)\s*)?' . self::MONTH . '/u', $after),
                    // «880ق», and «845-1098ق» (a span the catalogue wrote; «138-1309ق» is not one)
                    'marked' => (bool) preg_match('/^\s*(?:ق|ه\.?\s*ق|هـ)(?!\p{L})/u', $after)
                        || ((int) $digits >= 400 && preg_match('/^\s*[-–]\s*\d{4}(?!\d)/u', $after))];
            }
        }

        if ($strong = array_values(array_filter($found, fn ($f) => ! $f['weak']))) {
            $found = $strong;
        }
        // a year after a solar month, beside the Hijri year of the same day
        $hijri = array_column(array_filter($found, fn ($f) => ! $f['solar']), 'year');
        $found = array_values(array_filter($found, fn ($f) => ! $f['solar']
            || ! array_filter($hijri, fn ($y) => $y - $f['year'] >= self::SOLAR_GAP[0] && $y - $f['year'] <= self::SOLAR_GAP[1])));

        $years = [];
        foreach ($found as $i => $f) {
            if ($f['year'] < 1000) {
                $later = array_slice($found, $i + 1);
                $same = array_filter($found, fn ($o) => $o['clause'] === $f['clause'] && $o['year'] >= 1000);
                $first = $same ? min(array_column($same, 'year')) : null;
                // a day before a month, a year written short, a stray number far before the clause's year
                if (($f['day'] && $later)
                    || array_filter($same, fn ($o) => in_array((string) $f['year'], self::withoutOneDigit($o['year']), true))
                    || (! $f['marked'] && $first !== null && $first - $f['year'] > 200)) {
                    continue;
                }
            }
            $years[] = $f['year'];
        }

        return $years;
    }

    /** @return string[] the year's digits with any one digit dropped: «1207» → «207», «107», «127», «120» */
    private static function withoutOneDigit(int $year): array
    {
        $digits = (string) $year;
        $variants = [];
        foreach (array_keys(str_split($digits)) as $i) {
            $variants[] = substr($digits, 0, $i) . substr($digits, $i + 1);
        }

        return array_values(array_unique($variants));
    }

    private static function inRange(int $year): bool
    {
        return $year >= self::MIN && $year <= self::MAX;
    }
}
