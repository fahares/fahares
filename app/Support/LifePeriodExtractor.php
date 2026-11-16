<?php

namespace App\Support;

/**
 * Reads a biography for the periods of a life it tells of (backlog item 139, step 14.220): «انتقل الى طهران و بقی فیها
 * نحوا من عشر سنین», «برای ادامه‌ی تحصیل به نجف رفته», «تولى القضاء بحلب سنة 730». What it finds is a proposal for a
 * reviewer, never a fact: the kind is guessed from one word, and the years are those that stand in the same clause.
 *
 * A clause gives a proposal when it has a cue word of a kind (CUES) and, after it, a city of the list (CopyPlaces),
 * alone or behind «ب», «ل», «ف», «و» («ببغداد», «بنجف»), within WINDOW words. The clause is what lies between two marks
 * of punctuation.
 * Births, deaths and burials («ولد ب…», «توفي ب…», «دفن») are no periods and their clauses are skipped.
 */
final class LifePeriodExtractor
{
    /** kind => words that say it, in Arabic and Persian, without vowel marks; matched as whole words, a leading «و» or «ف» allowed */
    public const CUES = [
        'office' => ['تولى', 'تولي', 'ولي', 'ناب', 'قاضيا', 'قضاوت', 'امام جمعه', 'امامت جمعه', 'شيخ الاسلام', 'نقابة', 'نقابت', 'الوزارة', 'وزارت', 'صدارت', 'حكومت', 'توليت'],
        'teaching' => ['درس', 'يدرس', 'التدريس', 'تدريس', 'للتدريس', 'مدرسا', 'مدرس'],
        'study' => ['قرا', 'تتلمذ', 'تلمذ', 'اخذ', 'تعلم', 'تفقه', 'حضر', 'تخرج', 'اشتغل', 'تحصيل', 'التحصيل', 'تحصيلات', 'فرا گرفت', 'فرا گرفته', 'درس خواند', 'درس خوانده', 'تلمذ نمود', 'شاگردي'],
        'travel' => ['رحل', 'ارتحل', 'سافر', 'زار', 'حج', 'توجه', 'قدم', 'ورد', 'مسافرت', 'سفر', 'عزيمت', 'مشرف'],
        'residence' => ['سكن', 'نزل', 'نزيل', 'اقام', 'استوطن', 'توطن', 'قطن', 'جاور', 'مجاورا', 'استقر', 'بقي', 'انتقل', 'هاجر', 'عاد', 'رجع', 'ساكن', 'مقيم', 'اقامت', 'سكونت', 'مهاجرت', 'بازگشت', 'بازگشته', 'مراجعت', 'رفت', 'رفته', 'آمد', 'آمده'],
    ];

    public const WINDOW = 7;

    /** Persian cue words, whose place stands before them. */
    private const PLACE_FIRST = ['مراجعت', 'رفت', 'رفته', 'آمد', 'آمده', 'بازگشت', 'بازگشته', 'اقامت', 'سكونت', 'مهاجرت', 'مسافرت', 'عزيمت', 'مشرف', 'ساكن', 'مقيم',
        'تحصيل', 'تحصيلات', 'فرا گرفت', 'فرا گرفته', 'درس خواند', 'درس خوانده', 'تلمذ نمود', 'تدريس', 'قضاوت', 'امامت جمعه', 'امام جمعه', 'صدارت', 'حكومت', 'وزارت', 'توليت', 'نقابت'];

    /** A clause with one of these tells of a birth, a death or a burial. */
    private const SKIP = ['ولد', 'مولده', 'توفي', 'وفاته', 'مات', 'دفن', 'قتل', 'استشهد', 'متولد', 'تولد', 'وفات', 'درگذشت', 'درگذشته', 'مدفون', 'شهيد'];

    /** A study said with a teaching word: «درس على فلان» is «he studied under». */
    private const STUDY_AFTER_TEACH = ['على', 'عند', 'لدى', 'نزد'];

    /**
     * What a sample of sixty proposals showed to go wrong (step 14.227), and the rule against each:
     *   - the clause tells of someone else, a father, a son, a forebear: a clause with a word of OTHERS is skipped;
     *   - «نزيل», «مقيم», «ساكن» after a name describe that name: they count in the first HEAD words of a clause only;
     *   - a long clause tells several things: the cue stands in its first CUE_BY words;
     *   - «حضر» and «اخذ» are a study only with what follows them (NEEDS): «حضر دفنه» is a burial;
     *   - «مدينة قم» is «the city of Qom»: «مدينه» counts as Medina only with its «ال»; «الشام» is a region and never a city;
     *   - a year far from the place is of something else: only the years within YEAR_NEAR words of it are taken.
     */
    private const OTHERS = ['ابوه', 'ابيه', 'ابايه', 'اباوه', 'والده', 'جده', 'اخوه', 'اخيه', 'ولده', 'ابنه', 'اولاده', 'احفاده', 'اجداده', 'تلامذه', 'تلميذه', 'تلاميذه',
        'پدرش', 'پدر', 'جدش', 'اجدادش', 'نياكانش', 'نياكانم', 'فرزند', 'فرزندش', 'برادرش', 'شاگردانش', 'شاگردان'];

    private const HEAD = 3;

    /** How many words before a Persian verb its place may stand: «بنجف شتافته و بادامه تحصیل پرداخته». */
    private const BACK = 4;

    private const HEADED = ['نزيل', 'مقيم', 'ساكن', 'مجاورا'];

    public const CUE_BY = 18;

    private const NEEDS = ['حضر' => ['علي', 'عند', 'درس', 'بحث', 'ابحاث', 'مجلس', 'في'], 'اخذ' => ['عن', 'العلم', 'الفقه', 'في']];

    public const YEAR_NEAR = 5;

    /**
     * @return list<array{kind: string, city: string, place: string, years: list<int>, snippet: string}>
     */
    public static function extract(?string $text): array
    {
        $found = [];
        foreach (preg_split('/[.،؛,;:()\[\]«»\n]+/u', (string) $text) as $clause) {
            $clause = trim(preg_replace('/\s+/u', ' ', $clause));
            if (mb_strlen($clause) < 8) {
                continue;
            }
            $plain = ' ' . self::plain($clause) . ' ';
            if (self::hasWord($plain, self::SKIP) !== null || self::hasWord($plain, self::OTHERS) !== null) {
                continue;
            }
            foreach (self::CUES as $kind => $words) {
                $at = self::hasWord($plain, $words);
                if ($at === null) {
                    continue;
                }
                [$pos, $word] = $at;
                $cue = self::bare($word);
                $wordsBefore = substr_count(trim(mb_substr($plain, 0, $pos)), ' ') + (trim(mb_substr($plain, 0, $pos)) === '' ? 0 : 1);
                $next = array_slice(explode(' ', trim(mb_substr($plain, $pos + mb_strlen($word)))), 0, 3);
                if ($wordsBefore >= self::CUE_BY || (in_array($cue, self::HEADED, true) && $wordsBefore >= self::HEAD)
                    || (isset(self::NEEDS[$cue]) && ! array_intersect($next, self::NEEDS[$cue]))) {
                    continue;
                }
                // «درس في النجف» is «he studied», «درّس» «he taught»: the bare word is a study unless it is written with its mark
                if ($kind === 'teaching' && $cue === 'درس' && ! preg_match('/د\s*ر\x{0651}\s*س/u', $clause)) {
                    $kind = 'study';
                }
                if ($kind === 'teaching' && preg_match('/^\s*(?:' . implode('|', array_map([self::class, 'plain'], self::STUDY_AFTER_TEACH)) . ')\s/u', mb_substr($plain, $pos + mb_strlen($word)))) {
                    $kind = 'study';
                }
                // the city stands within WINDOW words after the cue: a long clause tells of more than one thing
                $city = self::cityAfter(' ' . implode(' ', array_slice(explode(' ', trim(mb_substr($plain, $pos))), 0, self::WINDOW + 1)) . ' ');
                // in Persian the place comes before the verb: «بقم مراجعت کرده», «در اصفهان اقامت گزید»
                if ($city === null && in_array(self::bare($word), array_map([self::class, 'plain'], self::PLACE_FIRST), true)) {
                    $before = explode(' ', trim(mb_substr($plain, 0, $pos)));
                    $city = self::cityAfter(' ' . implode(' ', array_slice($before, -self::BACK)) . ' ', true);
                }
                if ($city === null) {
                    continue;
                }
                $key = $kind . '|' . $city[0];
                $years = self::yearsNear($plain, $city[1]);
                // one proposal per kind and city: the first, or a later one that brings years
                if (! isset($found[$key]) || ($found[$key]['years'] === [] && $years !== [])) {
                    $found[$key] = ['kind' => $kind, 'city' => $city[0], 'place' => $city[1], 'years' => $years, 'snippet' => mb_substr($clause, 0, 300)];
                }
                break;
            }
        }

        return array_values($found);
    }

    /** The text without vowel marks, with one form of «ی», «ک», «ا» and «ه», for matching. */
    public static function plain(string $text): string
    {
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = strtr($text, ['ی' => 'ي', 'ى' => 'ي', 'ئ' => 'ي', 'ک' => 'ك', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ؤ' => 'و', 'ء' => '', "\u{200C}" => ' ',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** @return array{0: int, 1: string}|null the place of the first of the words in the text, a leading «و» or «ف» allowed */
    private static function hasWord(string $plain, array $words): ?array
    {
        $best = null;
        foreach ($words as $word) {
            $word = self::plain($word);
            if (preg_match('/(?<= )[وف]?' . preg_quote($word, '/') . '(?= )/u', $plain, $m, PREG_OFFSET_CAPTURE)) {
                $pos = mb_strlen(substr($plain, 0, $m[0][1]));
                if ($best === null || $pos < $best[0]) {
                    $best = [$pos, $m[0][0]];
                }
            }
        }

        return $best;
    }

    /** A cue as it was matched, without the «و» or «ف» that may lead it. */
    private static function bare(string $word): string
    {
        foreach (self::CUES as $words) {
            foreach ($words as $cue) {
                if (in_array($word, [self::plain($cue), 'و' . self::plain($cue), 'ف' . self::plain($cue)], true)) {
                    return self::plain($cue);
                }
            }
        }

        return $word;
    }

    /** @var array<int, array{0: string, 1: string}>|null plain name => city, longest first */
    private static ?array $names = null;

    /** @return array{0: string, 1: string}|null the first city of the list in the text and the word that names it */
    private static function cityAfter(string $plain, bool $last = false): ?array
    {
        if (self::$names === null) {
            $names = [];
            foreach (CopyPlaces::cities() as $city => $info) {
                foreach (array_merge([$city], $info['aliases'] ?? []) as $name) {
                    $names[self::plain($name)] = [self::plain($name), $city];
                }
            }
            usort($names, fn ($a, $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));
            self::$names = array_values($names);
        }
        $hits = [];
        foreach (self::$names as [$name, $city]) {
            // alone, or behind a one-letter particle: «ببغداد», «بنجف», «لاصفهان», «فدمشق»; «ال» before the name: «بالنجف»
            if (preg_match_all('/(?<= )[بلفوك]?(?:ال)?' . preg_quote($name, '/') . '(?= )/u', $plain, $all, PREG_OFFSET_CAPTURE)) {
                foreach ($all[0] as [$word, $at]) {
                    // «المشهد» is any shrine unless it is «الرضوي»: «المشهد المقدس الكاظمي» is Kazimayn
                    if ($name === 'مشهد' && str_contains($word, 'ال') && ! preg_match('/^(?: المقدس)? الرضو/u', substr($plain, $at + strlen($word)))) {
                        continue;
                    }
                    // «بمدينة قم» is «in the city of Qom»: Medina is «المدينة»
                    if ($name === 'مدينه' && ! str_contains($word, 'ال')) {
                        continue;
                    }
                    // «الشام» is Syria, a region: «رحل الى الشام» names no city (the copy places read it as Damascus)
                    if ($name === 'شام') {
                        continue;
                    }
                    // «هاجر من يزد الى اصفهان»: the place he left is not where the period was
                    if (preg_match('/ (?:من|از) $/u', substr($plain, 0, $at))) {
                        continue;
                    }
                    // the longest name wins at one place («كرمانشاه» over «كرمان»)
                    $hits[$at] ??= [$city, $word];
                }
            }
        }
        if (! $hits) {
            return null;
        }
        ksort($hits);

        if (! $last) {
            return reset($hits);
        }
        // before a Persian verb the nearest place is the last one; of two names side by side the first is the place and
        // the second where it lies: «بجهرم شیراز مهاجرت کرد»
        $places = array_keys($hits);
        $at = end($places);
        $before = prev($places);
        if ($before !== false && trim(substr($plain, $before + strlen($hits[$before][1]), $at - $before - strlen($hits[$before][1]))) === '') {
            return $hits[$before];
        }

        return $hits[$at];
    }

    /** The Hijri years within YEAR_NEAR words of the place: a year further off in the clause is of something else. */
    private static function yearsNear(string $plain, string $placeWord): array
    {
        $words = explode(' ', trim($plain));
        $at = array_search($placeWord, $words, true);
        if ($at === false) {
            return [];
        }

        return self::years(implode(' ', array_slice($words, max(0, $at - self::YEAR_NEAR), 2 * self::YEAR_NEAR + 1)));
    }

    /** The Hijri years a text names: «سنة 1205», «1311 ق», «في 730 ه»; a year with «م» is another calendar's. */
    private static function years(string $clause): array
    {
        $text = self::plain($clause);
        preg_match_all('/(?<![\d\/])(\d{3,4})(?![\d\/])(\s*(?:م|ميلادي|ش|شمسي)(?!\p{L}))?/u', $text, $m, PREG_SET_ORDER);
        $years = [];
        foreach ($m as $match) {
            $year = (int) $match[1];
            if (empty($match[2]) && $year >= 100 && $year <= HijriYear::MAX) {
                $years[] = $year;
            }
        }

        return array_values(array_unique($years));
    }
}
