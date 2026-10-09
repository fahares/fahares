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

        return preg_replace_callback($regex, function ($m) {
            return '<mark class="bg-amber-200/70 dark:bg-amber-500/30 text-stone-900 dark:text-amber-100 px-1 py-0.5 rounded font-bold">' . $m[0] . '</mark>';
        }, $escapedText);
    }
}

if (!function_exists('highlight_search')) {
    function highlight_search(?string $text, ?string $query): string
    {
        return \App\Helpers\SearchHelper::highlight($text, $query);
    }
}
