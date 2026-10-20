<?php

namespace App\Support;

/**
 * Splits a Fankha «خط:» value into the script names and the codicological notes that the corpus parser
 * left in it (backlog item 50). When the parser found no known script in a value, often because it was
 * written with the Arabic «ي/ى/ك», it kept the whole value as the script name, so «تحریری، بی‌کا»،
 * «مختلف، کاتب = مؤلف، بی‌تا» or a bare scribe name became script records. A value with a known script
 * keeps the parser's rule (only the known scripts count); the others lose their notes. The corpus parser
 * applies the same rules in split_script_notes().
 */
class ScriptNameCleaner
{
    /** Same list and order as KNOWN_SCRIPTS_LIST in the corpus parser: longer names first. */
    public const KNOWN_SCRIPTS = [
        'شکسته نستعلیق', 'نستعلیق', 'شکسته', 'تعلیق', 'رقعه', 'کوفی', 'ثلث',
        'ریحان', 'محقق', 'طومار', 'مغربی', 'لاتین', 'تایپی', 'نسخ',
    ];

    /** Variant spellings of a whole script name. */
    public const SPELLINGS = [
        'تحریری بدخط وناخوانا' => 'تحریری بدخط و ناخوانا',
    ];

    /** Words that mark a value as a script or a hand rather than a scribe's name. */
    public const SCRIPT_WORDS = [
        'تحریری', 'مختلف', 'گوناگون', 'سیاق', 'رقعی', 'رقاع', 'چاپی', 'چاپ', 'عبری', 'معتاد', 'پهلوی',
        'حمایلی', 'چلیپایی', 'چلیپا', 'مشق', 'دیوانی', 'فرنگی', 'ترکی', 'خط', 'قلم', 'قلم‌های',
        'زیبا', 'نازیبا', 'خوش', 'خوانا', 'ناخوانا', 'بدخط', 'ممتاز', 'پخته', 'جلی', 'خفی', 'درشت', 'متوسط',
        'عالی', 'ریز', 'کهن', 'معمول',
    ];

    /**
     * @return array{scripts: list<string>, bika: bool, bita: bool, autograph: bool, scribe: ?string, dropped: list<string>}
     */
    public static function clean(string $value): array
    {
        $s = self::normalize($value);
        $result = ['scripts' => [], 'bika' => false, 'bita' => false, 'autograph' => false, 'scribe' => null, 'dropped' => []];

        // «کا: <name>» runs to the end of the value, apart from trailing notes
        if (preg_match('/(?:^|،)\s*کا:\s*(.+)$/u', $s, $m, PREG_OFFSET_CAPTURE)) {
            $scribe = $m[1][0];
            $s = substr($s, 0, $m[0][1]);
            $scribe = self::stripNotes($scribe, $result);
            $result['scribe'] = self::trimPunctuation($scribe) ?: null;
        }

        $s = self::stripNotes($s, $result);

        if ($known = self::knownScriptsIn($s)) {
            $result['scripts'] = $known;

            return $result;
        }

        $segments = array_values(array_filter(array_map(
            [self::class, 'trimPunctuation'],
            preg_split('/،/u', $s)
        ), fn ($seg) => $seg !== ''));

        $words = [...self::SCRIPT_WORDS, ...self::KNOWN_SCRIPTS];
        $isScript = fn (string $seg) => (bool) array_intersect(preg_split('/\s+/u', $seg), $words);

        // A value without any script word is the scribe's name, written after «خط:» in the catalogue
        if ($segments && ! array_filter($segments, $isScript)) {
            $result['scribe'] ??= implode('، ', $segments);

            return $result;
        }

        $result['scripts'] = array_values(array_unique(array_map(fn ($seg) => self::SPELLINGS[$seg] ?? $seg, $segments)));

        return $result;
    }

    /** True when the value needs no change: one script, written as it should be. */
    public static function isClean(string $value): bool
    {
        $r = self::clean($value);

        return $r['scripts'] === [$value] && ! $r['bika'] && ! $r['bita'] && ! $r['autograph'] && $r['scribe'] === null && ! $r['dropped'];
    }

    public static function normalize(string $value): string
    {
        $s = preg_replace('/<!--\s*page:\s*\d+\s*-->/u', ' ', $value);
        // Arabic letters, and a misspelling that hides «نستعلیق» from the known-script match
        $s = str_replace('نستعیق', 'نستعلیق', strtr($s, ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک']));

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** Removes «بی‌کا»، «بی‌تا»، «کاتب = مؤلف» and similar notes, recording the flags they stand for. */
    private static function stripNotes(string $s, array &$result): string
    {
        // «بیکاتی 1241ق» is «بی‌کا، تا: 1241ق» run together
        $s = preg_replace_callback('/(?<!\p{L})بی[‌ ]?کا(?:تی\s*\d+\s*ق|(?!\p{L}))/u', function ($m) use (&$result) {
            $result['bika'] = true;
            if ($m[0] !== 'بی‌کا' && $m[0] !== 'بی کا' && $m[0] !== 'بیکا') {
                $result['dropped'][] = $m[0];
            }

            return '،';
        }, $s);
        $s = preg_replace_callback('/(?<!\p{L})بی[‌ ]?تا(?!\p{L})/u', function () use (&$result) {
            $result['bita'] = true;

            return '،';
        }, $s);

        // «کاتب = مؤلف»، with a hedge or a date sometimes attached; a hedged one sets no flag
        $s = preg_replace_callback('/(?:کاتب\s*)?=\s*(مؤلف|مترجم)(?:\s+مؤلف)?(\s*\((?:گویا|احتمالاً|احتمالا|ظاهراً)\))?(?:\s*:\s*تا\s*\d+\s*ق)?/u', function ($m) use (&$result) {
            if ($m[1] === 'مؤلف' && empty($m[2])) {
                $result['autograph'] = true;
            } else {
                $result['dropped'][] = trim($m[0]);
            }

            return '،';
        }, $s);

        foreach (['ظاهراً به خط مترجم', 'حواشی تحریری', 'هامش'] as $note) {
            if (preg_match('/(?:^|،)\s*' . preg_quote($note, '/') . '\s*(?=،|$)/u', $s)) {
                $result['dropped'][] = $note;
                $s = preg_replace('/(?:^|،)\s*' . preg_quote($note, '/') . '\s*(?=،|$)/u', '،', $s);
            }
        }

        return $s;
    }

    /** The known scripts in a segment, matched the way the corpus parser matches them. */
    private static function knownScriptsIn(string $seg): array
    {
        $found = [];
        foreach (self::KNOWN_SCRIPTS as $script) {
            if (str_contains($seg, $script)) {
                $found[] = $script;
                $seg = str_replace($script, ' ', $seg);
            }
        }

        return $found;
    }

    private static function trimPunctuation(string $s): string
    {
        // trim() works on bytes and would cut into the two-byte «،»
        return preg_replace('/^[\s،,:;؛.\-]+|[\s،,:;؛.\-]+$/u', '', preg_replace('/\s+/u', ' ', $s));
    }
}
