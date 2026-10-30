<?php

namespace App\Support;

/**
 * A copy's date (copy_date_raw) as a range of Hijri years, with the catalogue's hedge word (backlog item 96).
 * The date is the first clause (split on «،» and «؛») with a year or a century in it, so «1122، … در 616» is 1122
 * and «18 ربیع الثانی، 1268» is 1268. In it:
 *   - a century «قرن 13» is 1201-1300, and «قرن 12 و 13» 1101-1300; «اوایل», «اواسط» and «اواخر» take its first,
 *     middle and last third, «نیمه اول» and «نیمه دوم» its halves;
 *   - years are the Hijri ones HijriYear reads (not «1820م», «1350ش», folio counts): one year is itself, two or more
 *     («1082 و 1083ق», «1034 تا 1052») the span between the first and the last; «حدود 1086» is 1081-1091;
 *   - «پیش از 1100» has no start, «پس از 1100» no end.
 * The hedge word («محتملاً», «گویا», «(؟)») is kept as written, with its certainty.
 */
class CopyDateRange
{
    private const HEDGES = 'محتملاً|محتملا|احتمالاً|احتمالا|ظاهراً|ظاهرا|گویا|شاید';

    private const DIGITS = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];

    /**
     * @return array{from: ?int, to: ?int, certainty: ?string, hedge: ?string}
     */
    public static function parse(?string $raw): array
    {
        $none = ['from' => null, 'to' => null, 'certainty' => null, 'hedge' => null];
        $text = trim(strtr((string) $raw, self::DIGITS));
        if ($text === '') {
            return $none;
        }
        $clause = null;
        foreach (preg_split('/\s*[،؛]\s*/u', $text) as $part) {
            if (preg_match('/(?:قرن|سده)\s*\d{1,2}(?!\d)/u', $part) || HijriYear::parse($part) !== null) {
                $clause = $part;
                break;
            }
        }
        if ($clause === null) {
            return $none;
        }
        $hedge = null;
        if (preg_match('/(?:^|\s|\()(' . self::HEDGES . ')(?=\s|\)|$)/u', $clause, $m)) {
            $hedge = $m[1];
        } elseif (str_contains($clause, '؟')) {
            $hedge = '؟';
        }
        [$from, $to] = self::centuries($clause) ?? self::years($clause) ?? [null, null];
        if ($from === null && $to === null) {
            return $none;
        }

        return ['from' => $from, 'to' => $to, 'certainty' => PersonNameMatcher::certainty($hedge), 'hedge' => $hedge];
    }

    /** @return array{0: int, 1: int}|null */
    private static function centuries(string $clause): ?array
    {
        if (! preg_match('/(اوایل|اوائل|اوان|اواسط|میانه|اواخر|پایان|نیمه\s*(?:اول|نخست|دوم))?\s*(?:قرن|سده)\s*(\d{1,2})(?!\d)(?:\s*(?:و|-|–|تا)\s*(?:قرن\s*)?(\d{1,2})(?!\d))?/u', $clause, $m)) {
            return null;
        }
        $first = (int) $m[2];
        $last = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $first;
        if ($first < 1 || $last > 15 || $last < $first) {
            return null;
        }
        $from = ($first - 1) * 100 + 1;
        $to = $last * 100;
        if ($first === $last) {
            switch (preg_replace('/\s+/u', ' ', $m[1] ?? '')) {
                case 'اوایل': case 'اوائل': case 'اوان': $to = $from + 32; break;
                case 'اواسط': case 'میانه': [$from, $to] = [$from + 33, $from + 65]; break;
                case 'اواخر': case 'پایان': $from = $to - 33; break;
                case 'نیمه اول': case 'نیمه نخست': $to = $from + 49; break;
                case 'نیمه دوم': $from += 50; break;
            }
        }

        return [$from, $to];
    }

    /** @return array{0: ?int, 1: ?int}|null */
    private static function years(string $clause): ?array
    {
        $years = [];
        $rest = $clause;
        // HijriYear reads the first year of what it is given; each later one is read from what follows it
        while (($year = HijriYear::parse($rest)) !== null) {
            $years[] = $year;
            $at = strpos($rest, (string) $year);
            if ($at === false) {
                break;
            }
            $rest = substr($rest, $at + strlen((string) $year));
        }
        if (! $years) {
            return null;
        }
        $first = $years[0];
        if (count($years) === 1) {
            if (preg_match('/(?:پیش|قبل)\s+از\s+\S*' . $first . '/u', $clause)) {
                return [null, $first];
            }
            if (preg_match('/(?:پس|بعد)\s+از\s+\S*' . $first . '/u', $clause)) {
                return [$first, null];
            }
            if (preg_match('/(?:حدود|حوالی|نزدیک)\s+(?:به\s+)?\S*' . $first . '/u', $clause)) {
                return [$first - 5, $first + 5];
            }

            return [$first, $first];
        }

        return [min($years), max($years)];
    }
}
