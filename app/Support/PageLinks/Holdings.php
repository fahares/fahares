<?php

namespace App\Support\PageLinks;

/**
 * The lines of a bibliography's page text that name a copy in a library (step 14.34): Monzavi's «دانشگاه 2705/12 ش (1)
 * 3701»، «آستان قدس ش 9421/1»، «مجلس 448/19»: one of the library forms config('page_links.*.holdings.libraries') gives,
 * at the start of a line or after «؛», then the page and volume of the library's printed catalogue («page/volume»),
 * the copy's number after «ش» (a «(1)» before it, the catalogue's part, left out), or both. A form followed by other
 * words («آستان قدس، الفبایی 34») names another catalogue and is not read.
 */
class Holdings
{
    /**
     * The copies named, in order: where the words start in the text (in characters) and the words, the library form,
     * and the catalogue's volume and page and the copy's number when given.
     *
     * @param  list<string>  $forms
     * @return list<array{offset: int, span: string, form: string, volume: ?int, page: ?int, shelfmark: ?string}>
     */
    public static function in(string $text, array $forms): array
    {
        if (! $forms) {
            return [];
        }
        usort($forms, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        $d = '[0-9۰-۹]';
        $pattern = '/(?:^|(?<=؛))[ \t]*\K(?<form>' . implode('|', array_map(fn ($f) => preg_quote($f, '/'), $forms)) . ')(?![\p{L}\p{M}\x{200C}])'
            . "(?:[ \\t]+(?<page>{$d}{1,5})[ \\t]*\\/[ \\t]*(?<volume>{$d}{1,3})(?![0-9۰-۹\\/]))?"
            . "(?:[ \\t]*،?[ \\t]+ش[ \\t]+(?:\\([ \\t]*{$d}{1,3}[ \\t]*\\)[ \\t]*)?(?<shelf>{$d}{1,6}(?:[ \\t]*\\/[ \\t]*{$d}{1,4})?)(?![0-9۰-۹]))?/mu";
        preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
        $found = [];
        foreach ($matches as $m) {
            $page = $m['page'][0] ?? null;
            $shelf = $m['shelf'][0] ?? null;
            if ($page === null && $shelf === null) {
                continue;
            }
            $found[] = [
                'offset' => mb_strlen(substr($text, 0, $m[0][1])),
                'span' => rtrim($m[0][0]),
                'form' => $m['form'][0],
                'volume' => $page === null ? null : self::int($m['volume'][0]),
                'page' => $page === null ? null : self::int($page),
                'shelfmark' => $shelf === null ? null : self::ascii(preg_replace('/\s+/u', '', $shelf)),
            ];
        }

        return $found;
    }

    /** The number a shelfmark starts with, in Latin digits («629/1» → 629; «9421» → 9421), or null. */
    public static function base(?string $shelfmark): ?int
    {
        return preg_match('/^\D{0,3}?([0-9]+)/u', self::ascii((string) $shelfmark), $m) ? (int) $m[1] : null;
    }

    public static function ascii(string $s): string
    {
        return strtr($s, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }

    private static function int(string $digits): int
    {
        return (int) self::ascii($digits);
    }
}
