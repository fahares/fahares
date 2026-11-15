<?php

namespace App\Support;

/**
 * A copy's date (copy_date_raw) as a range of Hijri years, with the catalogue's hedge word (backlog item 96).
 * The date is the first clause (split on «،» and «؛») with a year or a century in it, so «1122، … در 616» is 1122
 * and «18 ربیع الثانی، 1268» is 1268. In it:
 *   - a century «قرن 13» is 1201-1300, and «قرن 12 و 13» 1101-1300; «اوایل», «اواسط» and «اواخر» take its first,
 *     middle and last third, «نیمه اول» and «نیمه دوم» its halves; «ق 10» is «قرن 10», «قرن 12-11» is «قرن 11-12»,
 *     and a date that is only two centuries' numbers («10 و 11», «13-14», «اواخر 13 یا اوایل 14»), each 15 or less,
 *     is those centuries (step 14.73): no Hijri year is two digits, and no copy is dated by a bare year;
 *   - years are the Hijri ones HijriYear reads (not «1820م», «1350ش», folio counts): one year is itself, two or more
 *     («1082 و 1083ق», «1034 تا 1052») the span between the first and the last; «حدود 1086» is 1081-1091;
 *   - «پیش از 1100» has no start, «پس از 1100» no end;
 *   - a date with no Hijri year but a solar or Gregorian one («1370ش», «1886م») is the Hijri years that year spans
 *     (HijriYear::otherCalendar, step 14.181); the text keeps its own calendar.
 * The hedge word («محتملاً», «گویا», «(؟)») is kept as written, with its certainty.
 *
 * A date with no year that names the author's time takes its range from the author's death D (relative()):
 * «در حیات مؤلف» and «در عصر تألیف» D−50 to D, «در/از عصر مؤلف» D−50 to D+25, «نزدیک عصر مؤلف» D to D+75. It is an estimate, so its
 * certainty is at least probable, and the phrase is kept as the hedge.
 *
 * A date that is one three-digit year far before its work is read again as a year that lost a digit (reread()).
 *
 * What a range means is its kind (DateSpan, item 134, step 14.188). A century, «حدود», an open end, another
 * calendar's year, the author's time and a re-read year are one event at an unknown time: BETWEEN. Of two years:
 *   - «1250 یا 1270», «بین 1063 تا 1141», «حدود سال‌های 560 تا 590», or a hedge word: BETWEEN;
 *   - «آغاز 1054 و پایان 1055», and «تا» or «الی» up to SPAN_TO years apart: SPAN, the work lasted that long;
 *   - a dash or «و» up to SPAN_NEAR years apart («1267-1268ق», «903 و 904ق»): SPAN;
 *   - anything else (a wider dash or «و», three years or more, «974/977», a bracket): null, the text does not say.
 * One year has no kind.
 */
class CopyDateRange
{
    private const HEDGES = 'محتملاً|محتملا|احتمالاً|احتمالا|ظاهراً|ظاهرا|گویا|شاید';

    /** «قرن 13», «سده 13», and «ق 13» where the «ق» stands alone («1268ق» is a year). */
    private const CENTURY = '(?:قرن|سده|(?<![\p{L}\d])ق\.?(?=\s*\d))';

    private const DIGITS = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];

    /**
     * @return array{from: ?int, to: ?int, certainty: ?string, hedge: ?string, kind: ?string}
     */
    public static function parse(?string $raw): array
    {
        $none = ['from' => null, 'to' => null, 'certainty' => null, 'hedge' => null, 'kind' => null];
        $text = trim(strtr((string) $raw, self::DIGITS));
        if ($text === '') {
            return $none;
        }
        $clause = null;
        foreach (preg_split('/\s*[،؛]\s*/u', $text) as $part) {
            if (preg_match('/' . self::CENTURY . '\s*\d{1,2}(?!\d)/u', $part) || self::bareCenturies($part) !== null || HijriYear::all($part) !== []) {
                $clause = $part;
                break;
            }
        }
        if ($clause === null) {
            // a solar or Gregorian date with no Hijri year: the Hijri years it spans (step 14.181)
            $other = HijriYear::otherCalendar($text);
            if ($other === null) {
                return $none;
            }
            $hedge = preg_match('/(?:^|\s|\()(' . self::HEDGES . ')(?=\s|\)|$)/u', $text, $h) ? $h[1] : (str_contains($text, '؟') ? '؟' : null);

            return ['from' => $other[0], 'to' => $other[1], 'certainty' => PersonNameMatcher::certainty($hedge), 'hedge' => $hedge,
                'kind' => $other[0] === $other[1] ? null : DateSpan::BETWEEN];
        }
        $hedge = null;
        if (preg_match('/(?:^|\s|\()(' . self::HEDGES . ')(?=\s|\)|$)/u', $clause, $m)) {
            $hedge = $m[1];
        } elseif (str_contains($clause, '؟')) {
            $hedge = '؟';
        }
        $century = self::centuries($clause) ?? self::bareCenturies($clause);
        [$from, $to] = $century ?? self::years($clause) ?? [null, null];
        if ($from === null && $to === null) {
            return $none;
        }
        $kind = match (true) {
            $from === $to => null,
            $century !== null, $from === null, $to === null, $hedge !== null => DateSpan::BETWEEN,
            default => self::kindOfYears($clause),
        };

        return ['from' => $from, 'to' => $to, 'certainty' => PersonNameMatcher::certainty($hedge), 'hedge' => $hedge, 'kind' => $kind];
    }

    /** «تا» and «الی» say a duration when the years are this far apart or less; a dash and «و» when this far or less. */
    public const SPAN_TO = 40;

    public const SPAN_NEAR = 10;

    /** What the range of a clause with two years or more means (the class comment): SPAN, BETWEEN or null. */
    private static function kindOfYears(string $clause): ?string
    {
        $years = HijriYear::all($clause);
        if (count($years) < 2) {
            // «حدود 1086»: one year, about
            return DateSpan::BETWEEN;
        }
        [$first, $last] = [(string) $years[0], (string) end($years)];
        $at = mb_strpos($clause, $first);
        $end = mb_strrpos($clause, $last);
        $gap = $at !== false && $end !== false && $end > $at ? mb_substr($clause, $at + mb_strlen($first), $end - $at - mb_strlen($first)) : '';
        $width = max($years) - min($years);
        $word = fn (string $words, string $in) => (bool) preg_match('/(?<!\p{L})(?:' . $words . ')(?!\p{L})/u', $in);

        return match (true) {
            $word('یا', $gap), $word('بین|مابین|میان|حدود|حوالی', $clause) => DateSpan::BETWEEN,
            count(array_unique($years)) > 2 => null,
            (bool) preg_match('/آغاز|شروع|ابتدا/u', $clause) && (bool) preg_match('/پایان|انجام|اتمام|فراغ|ختم|انتها/u', $clause) => DateSpan::SPAN,
            $word('تا|الی|لغایت', $gap) => $width <= self::SPAN_TO ? DateSpan::SPAN : null,
            (bool) preg_match('/[-–]/u', $gap), $word('و', $gap) => $width <= self::SPAN_NEAR ? DateSpan::SPAN : null,
            default => null,
        };
    }

    /** The hedge of a re-read date starts with this word, followed by the year the catalogue wrote («بازخوانی 125»). */
    public const REREAD = 'بازخوانی';

    /** A re-read year is no later than this, and the catalogue's year is this far or more before the work's floor. */
    private const REREAD_LAST = 1400;

    private const REREAD_GAP = 100;

    /**
     * A date that is one three-digit year, far before the work it is a copy of, read again as a year that lost a
     * digit (item 128, step 14.183): «سال 125؟» for a work of 1106 is 1125, 1205 or 1250, so 1125-1250. The readings
     * are the year with a 1 before it and with a 0 put in after each digit; those from the floor (the work's
     * composition, or its author's birth) to REREAD_LAST are kept, and of those the ones that give the date its
     * weekday when it names one (HijriWeekday), if any does; the range runs from the first to the last. The
     * date is a guess, so it is doubtful, and its hedge says what was done. Null when the date is anything else, when
     * the year is not REREAD_GAP years before the floor, or when no reading fits: the date then stands as written.
     *
     * @return array{from: int, to: int, certainty: string, hedge: string, kind: ?string}|null
     */
    public static function reread(?string $raw, ?int $floor): ?array
    {
        $year = $floor ? self::shortYear($raw) : null;
        if ($year === null || $floor - $year < self::REREAD_GAP) {
            return null;
        }
        $digits = (string) $year;
        $readings = [1000 + $year];
        foreach ([1, 2, 3] as $at) {
            $readings[] = (int) (substr($digits, 0, $at) . '0' . substr($digits, $at));
        }
        $fit = array_values(array_filter($readings, fn (int $r) => $r >= $floor && $r <= self::REREAD_LAST));
        if (! $fit) {
            return null;
        }
        // «جمعه 18 ذیحجه 119؟»: of 1019, 1109, 1119 and 1190 that day is a Friday or a day from it in the first two (step 14.184)
        $stated = HijriWeekday::stated($raw);
        if ($stated && ($onDay = array_values(array_filter($fit, fn (int $r) => HijriWeekday::near($stated, $r))))) {
            $fit = $onDay;
        }

        return ['from' => min($fit), 'to' => max($fit), 'certainty' => 'doubtful', 'hedge' => self::REREAD . ' ' . $year,
            'kind' => min($fit) === max($fit) ? null : DateSpan::BETWEEN];
    }

    /**
     * The year of a date that is one year of three digits («محرم 125», «سال 125؟»), else null. A year tied to another
     * number («91-119», «رجب 73 و 196») is part of something this does not read.
     */
    public static function shortYear(?string $raw): ?int
    {
        $own = self::parse($raw);
        if ($own['from'] === null || $own['from'] !== $own['to'] || $own['from'] >= 1000) {
            return null;
        }
        $text = strtr((string) $raw, self::DIGITS);
        $year = $own['from'];
        $tie = '\s*(?:-|–|و|تا)\s*';

        return preg_match('/' . self::CENTURY . '\s*\d{1,2}(?!\d)/u', $text) || preg_match("/\d{$tie}{$year}(?!\d)|(?<!\d){$year}{$tie}\d/u", $text) ? null : $year;
    }

    /** Whether a stored hedge is that of a re-read date, and the year the catalogue wrote. */
    public static function rereadYear(?string $hedge): ?int
    {
        return $hedge !== null && preg_match('/^' . self::REREAD . ' (\d{3})$/u', $hedge, $m) ? (int) $m[1] : null;
    }

    /** «حیات», «عصر», «نزدیک عصر»: the span around the author's death each takes, in years before and after it. */
    private const RELATIVE = ['life' => [50, 0], 'time' => [50, 25], 'near' => [0, 75]];

    private const AUTHOR = 'مؤلف|مولف|مصنف|ناظم|سراینده|نگارنده|شاعر|شارح';

    /**
     * The range of a date that names the author's time («نزدیک عصر مؤلف», «در حیات مؤلف», «ظاهراً از عصر ناظم»),
     * from the author's death year; null when the date has a year or a century of its own, or names no such time.
     *
     * @return array{from: int, to: int, certainty: string, hedge: string, kind: string}|null
     */
    public static function relative(?string $raw, ?int $authorDeath): ?array
    {
        $text = trim(strtr((string) $raw, self::DIGITS));
        if (! $authorDeath || ! self::namesAuthorTime($text)) {
            return null;
        }
        $own = self::parse($text);
        if ($own['from'] !== null || $own['to'] !== null) {
            return null;
        }
        $author = '(?:' . self::AUTHOR . ')';
        if (preg_match('/(?:(?:در\s*)?(?:زمان\s*)?حیات\s*' . $author . '|(?:عصر|زمان|هنگام)\s*تألیف)/u', $text, $m)) {
            $kind = 'life';
        } elseif (preg_match('/نزدیک\s*(?:به\s*)?(?:عصر|زمان|روزگار|عهد)\s*' . $author . '/u', $text, $m)) {
            $kind = 'near';
        } elseif (preg_match('/(?:(?:در|از)\s*)?(?:عصر|زمان|روزگار|عهد)\s*' . $author . '/u', $text, $m)) {
            $kind = 'time';
        } else {
            return null;
        }
        [$before, $after] = self::RELATIVE[$kind];
        $hedge = preg_match('/(?:^|\s|\()(' . self::HEDGES . ')(?=\s|\)|$)/u', $text, $h) ? $h[1] : (str_contains($text, '؟') ? '؟' : null);

        return ['from' => $authorDeath - $before, 'to' => $authorDeath + $after,
            'certainty' => PersonNameMatcher::certainty($hedge) ?? 'probable', 'hedge' => trim(($hedge && $hedge !== '؟' ? $hedge . ' ' : '') . $m[0]),
            'kind' => DateSpan::BETWEEN];
    }

    /** Whether a date may name the author's time, so that its range needs the author's death year. */
    public static function namesAuthorTime(?string $raw): bool
    {
        return (bool) preg_match('/(?:عصر|زمان|روزگار|عهد|حیات|هنگام)\s*(?:' . self::AUTHOR . '|تألیف)/u', (string) $raw);
    }

    /** @return array{0: int, 1: int}|null */
    private static function centuries(string $clause): ?array
    {
        if (! preg_match('/(اوایل|اوائل|اوان|اواسط|میانه|اواخر|پایان|نیمه\s*(?:اول|نخست|دوم))?\s*' . self::CENTURY . '\s*(\d{1,2})(?!\d)(?:\s*(?:و|-|–|تا|یا)\s*(اوایل|اوائل|اواسط|اواخر)?\s*(?:قرن|سده|ق\.?)?\s*(\d{1,2})(?!\d))?/u', $clause, $m)) {
            return null;
        }
        $first = (int) $m[2];
        $last = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : $first;
        // «قرن 12-11»: the later century first
        if ($last < $first && $last >= $first - 1) {
            [$first, $last] = [$last, $first];
        }
        if ($first < 1 || $last > 15 || $last < $first) {
            return null;
        }
        $from = ($first - 1) * 100 + 1;
        $to = $last * 100;
        // a span: «اواخر قرن 12 تا اوایل 13», «ق 12 یا اوائل قرن 13» keep the part each end names (step 14.73)
        if ($first !== $last) {
            $from += ['اواسط' => 33, 'میانه' => 33, 'اواخر' => 66, 'پایان' => 66, 'نیمه دوم' => 50][preg_replace('/\s+/u', ' ', $m[1] ?? '')] ?? 0;
            $to -= ['اوایل' => 67, 'اوائل' => 67, 'اواسط' => 34, 'اواخر' => 0][$m[3] ?? ''] ?? 0;
        }
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

    /**
     * «10 و 11», «13-14», «اواخر 13 یا اوایل 14»: a date that is only two centuries' numbers, in order and next to each
     * other or one apart, each 15 or less (step 14.73). A part word on either keeps that part of its century.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function bareCenturies(string $clause): ?array
    {
        $part = '(اوایل|اوائل|اواسط|اواخر)?\s*';
        // only a hedge word or «در» may come before them: «رمضان 10 و 11» is two days
        if (! preg_match('/^\(?\s*(?:(?:' . self::HEDGES . '|در|حدود|حدوداً)\s*)*' . $part . '(\d{1,2})\s*(?:و|-|–|تا|یا)\s*' . $part . '(\d{1,2})\s*(?:ق|قمری|هجری)?\s*\)?$/u', trim($clause), $m)) {
            return null;
        }
        [$first, $last] = [(int) $m[2], (int) $m[4]];
        if ($first < 1 || $last > 15 || $last <= $first || $last - $first > 2) {
            return null;
        }
        $from = ($first - 1) * 100 + 1;
        $to = $last * 100;
        $from += ['اوایل' => 0, 'اوائل' => 0, 'اواسط' => 33, 'اواخر' => 66][$m[1]] ?? 0;
        $to -= ['اوایل' => 67, 'اوائل' => 67, 'اواسط' => 34, 'اواخر' => 0][$m[3]] ?? 0;

        return [$from, $to];
    }

    /** @return array{0: ?int, 1: ?int}|null */
    private static function years(string $clause): ?array
    {
        $years = HijriYear::all($clause);
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
