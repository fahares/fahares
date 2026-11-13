<?php

namespace App\Helpers;

class SearchHelper
{
    /**
     * Highlights search terms in text with Persian/Arabic support.
     * Preserves diacritics and letters while safely escaping HTML.
     *
     * @param string|null $text
     * @param string|null $query
     * @return string
     */
    public static function highlight(?string $text, ?string $query): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $escapedText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        if ($query === null || trim($query) === '') {
            return $escapedText;
        }

        // Split query into tokens of at least 2 characters (or 1 if no tokens >= 2)
        $tokens = array_filter(
            preg_split('/[\s\-_,\.\،\؛\:\(\)\[\]«»]+/u', trim($query)),
            fn($t) => mb_strlen($t) >= 2
        );

        if (empty($tokens)) {
            $tokens = array_filter(
                preg_split('/\s+/u', trim($query)),
                fn($t) => mb_strlen($t) >= 1
            );
        }

        if (empty($tokens)) {
            return $escapedText;
        }

        $patterns = [];
        $diacritics = '[\x{064B}-\x{065F}\x{0670}]*';

        foreach ($tokens as $token) {
            $chars = preg_split('//u', $token, -1, PREG_SPLIT_NO_EMPTY);
            $patternParts = [];

            foreach ($chars as $ch) {
                if (in_array($ch, ['ی', 'ي', 'ى', 'ئ'], true)) {
                    $patternParts[] = '[یيىئ]';
                } elseif (in_array($ch, ['ک', 'ك'], true)) {
                    $patternParts[] = '[کك]';
                } elseif (in_array($ch, ['ا', 'آ', 'أ', 'إ', 'ٱ'], true)) {
                    $patternParts[] = '[اآأإٱ]';
                } elseif (in_array($ch, ['ه', 'ة'], true)) {
                    $patternParts[] = '[هة]';
                } elseif (in_array($ch, ['و', 'ؤ'], true)) {
                    $patternParts[] = '[وؤ]';
                } else {
                    $patternParts[] = preg_quote($ch, '/');
                }
            }

            $patterns[] = implode($diacritics, $patternParts) . $diacritics;
        }

        // Sort patterns by length descending so longer tokens match first
        usort($patterns, fn($a, $b) => mb_strlen($b) - mb_strlen($a));

        $regex = '/(' . implode('|', $patterns) . ')/iu';

        return self::marked(preg_replace($regex, "\x01$1\x02", $escapedText));
    }

    /** Letters that join the letter after them, and the letters that join the one before */
    private const JOINS_NEXT = '[\x{0626}\x{0628}\x{062A}-\x{062E}\x{0633}-\x{063A}\x{0641}-\x{0647}\x{0649}\x{064A}\x{067E}\x{0686}\x{06A9}\x{06AF}\x{06BE}\x{06C1}\x{06CC}]';

    private const JOINS_PREVIOUS = '[\x{0622}-\x{063A}\x{0641}-\x{064A}\x{0671}\x{067E}\x{0686}\x{0698}\x{06A9}\x{06AF}\x{06BE}\x{06C1}\x{06CC}]';

    /**
     * Escaped text whose matches stand between \x01 and \x02, as <mark> tags. A match is often a part of a word
     * («آتشک» in «آتشکده»): a tag between two joined letters breaks their joining, so a zero-width joiner goes on
     * each side of such a cut and both letters keep their joined forms. The mark has no padding and no weight of its
     * own, which would open a gap in the word (step 14.158).
     */
    public static function marked(string $escaped, string $class = 'search-mark'): string
    {
        $zwj = "\u{200D}";
        $cut = '(?<=' . self::JOINS_NEXT . ')%s(?=' . self::JOINS_PREVIOUS . ')';
        $escaped = preg_replace('/' . sprintf($cut, '\x01') . '/u', $zwj . "\x01" . $zwj, $escaped);
        $escaped = preg_replace('/' . sprintf($cut, '\x02') . '/u', $zwj . "\x02" . $zwj, $escaped);

        return str_replace(["\x01", "\x02"], ['<mark class="' . $class . '">', '</mark>'], $escaped);
    }
}

if (!function_exists('highlight_search')) {
    function highlight_search(?string $text, ?string $query): string
    {
        return \App\Helpers\SearchHelper::highlight($text, $query);
    }
}
