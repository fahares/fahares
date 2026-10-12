<?php

namespace App\Support;

/**
 * Extracts a sortable Hijri year from a free-text catalog date such as
 * «یک‌شنبه ۲۳ ربیع الاول ۱۲۳۶ق». Returns null when there is no year
 * («سده‌ی ۱۳») or the only year is Gregorian or solar («۱۸۲۰م», «۱۳۵۰ش»).
 */
class HijriYear
{
    public const MIN = 100;
    public const MAX = 1500;

    private const YEAR = '(?<!\d)(\d{3,4})(?!\d)';
    // Folio and page counts are not years
    private const FOLIO_WORD = '(?:برگ|ورق|صفحه|ص\\.|گ\\.)';
    private const OTHER_MARK = '\s*(?:ه[ـ]?\.?\s*)?(?:م(?!\p{L})|میلادی|ش(?!\p{L})|شمسی|خورشیدی|AD|CE)';

    public static function parse(?string $raw): ?int
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $text = strtr($raw, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        if (! preg_match_all('/' . self::YEAR . '/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $years = [];
        // Ranges and alternatives («۱۰۸۲ و ۱۰۸۳ق», «۱۰۳۴ تا ۱۰۵۲ق») sort by their first year
        foreach ($matches[1] as [$digits, $offset]) {
            $before = substr($text, 0, $offset);
            $after = substr($text, $offset + strlen($digits));
            if (preg_match('/^' . self::OTHER_MARK . '/u', $after) || preg_match('/' . self::FOLIO_WORD . '\\s*$/u', $before)) {
                continue;
            }
            if (self::inRange((int) $digits)) {
                $years[] = (int) $digits;
            }
        }

        if (empty($years)) {
            return null;
        }

        // A year written without its zero, followed by its reading: «۱۲۷ (=۱۲۰۷ق)», «۱۲۸ (شاید ۱۰۲۸ق)»
        if ($years[0] < 1000) {
            foreach (array_slice($years, 1) as $year) {
                if ($year >= 1000 && in_array((string) $years[0], self::withoutOneZero($year), true)) {
                    return $year;
                }
            }
        }

        return $years[0];
    }

    /** @return string[] the year's digits with any single 0 dropped */
    private static function withoutOneZero(int $year): array
    {
        $digits = (string) $year;
        $variants = [];
        foreach (str_split($digits) as $i => $digit) {
            if ($digit === '0') {
                $variants[] = substr($digits, 0, $i) . substr($digits, $i + 1);
            }
        }

        return $variants;
    }

    private static function inRange(int $year): bool
    {
        return $year >= self::MIN && $year <= self::MAX;
    }
}
