<?php

namespace App\Helpers;

class TextNormalizer
{
    /**
     * Normalize Arabic/Persian text for unified search and indexing.
     *
     * @param string|null $text
     * @return string
     */
    public static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // 1. Unified character replacements
        $text = str_replace(
            [
                'ك', // Arabic Kaf -> Persian Keheh
                'ي', // Arabic Yeh with two dots -> Persian Yeh
                'ى', // Alef Maqsura -> Persian Yeh
                'ئ', // Yeh with Hamza -> Persian Yeh
                'أ', // Alef with Hamza Above -> Plain Alef
                'إ', // Alef with Hamza Below -> Plain Alef
                'آ', // Alef with Madda -> Plain Alef
                'ٱ', // Alef Wasla -> Plain Alef
                'ؤ', // Waw with Hamza -> Plain Waw
                'ة', // Teh Marbuta -> Heh
                'ٰ', // Superscript Alef -> Plain Alef
                'ۥ', // Small Waw
                'ۦ', // Small Yeh
                'ـ', // Kashida / Tatweel -> Remove
                "\u{200c}", // ZWNJ -> Space
                "\u{200d}", // ZWJ -> Remove
                "\u{00a0}", // NBSP -> Space
                "\u{fe8e}", // Arabic Letter Alef Isolated
                "\u{fe8d}", // Arabic Letter Alef Final
            ],
            [
                'ک',
                'ی',
                'ی',
                'ی',
                'ا',
                'ا',
                'ا',
                'ا',
                'و',
                'ه',
                'ا',
                '',
                '',
                '',
                ' ',
                '',
                ' ',
                'ا',
                'ا',
            ],
            $text
        );

        // 2. Remove Arabic diacritics / tashkeel & tanwin (َ ِ ُ ً ٍ ٌ ّ ْ ٔ ٓ) and standalone hamza (ء)
        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0621}]/u', '', $text);

        // 3. Normalize Arabic eastern digits to Persian
        $text = str_replace(
            ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            $text
        );

        // 4. Collapse multiple whitespace and trim
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Expand text for indexing to include variants for words originally with ة (Teh Marbuta)
     * e.g. "بهجة" produces "بهجه" and also attaches "بهجت".
     *
     * @param string|null $text
     * @return string
     */
    public static function expandVariants(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        $normalized = self::normalize($text);
        if ($normalized === '') {
            return '';
        }

        // Detect words in original text ending with ة, and generate their "ت" counterpart
        $words = preg_split('/\s+/u', $text);
        $tVariants = [];

        foreach ($words as $word) {
            $cleanWord = preg_replace('/[^\p{Arabic}\p{L}]/u', '', $word);
            if ($cleanWord === '') {
                continue;
            }

            if (mb_substr($cleanWord, -1) === 'ة') {
                $base = mb_substr($cleanWord, 0, -1);
                $normBase = self::normalize($base);
                if (mb_strlen($normBase) >= 2) {
                    $tVariants[] = $normBase . 'ت';
                }
            }
        }

        if (!empty($tVariants)) {
            $tVariants = array_unique($tVariants);
            return $normalized . ' ' . implode(' ', $tVariants);
        }

        return $normalized;
    }
}
