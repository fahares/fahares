<?php

namespace App\Support\PageLinks;

/**
 * The shelfmark a library's mark is followed by in a page's text (step 13.155): «(سپهسالار: 404)», «(المجلس 1012)»,
 * «(إلهيات: 799 د)», «(الرضوية: 468 أدبيات)», «(الملك: 5116/5)», on the same line or the next, or inside the mark
 * itself («إلهيات: 196 ج»). Words after the number that are the sentence's (config page_links.*.copies.skip_words:
 * «(المجلس 1012) كتابته 1266») are not a collection's mark.
 */
class Shelfmarks
{
    private const AFTER = '/^([\s:،\-]*)(\d{1,6}(?:\s*\/\s*\d{1,4})?)(?!\d)(?:(\s*-?\s*)(\p{L}{1,10}))?(?![\p{L}\d])/u';

    /**
     * The number, the mark of a collection after it (or ''), and, when they stand after the mark, where they start and
     * the words they are; null when no number follows the mark.
     *
     * @param  array{text: string, end: int}  $mark  as PageText::marks() gives it
     * @return ?array{number: string, suffix: string, offset: ?int, span: ?string}
     */
    public static function after(string $text, array $mark, array $skipWords = []): ?array
    {
        if (preg_match('/[:\s]\s*(\d{1,6}(?:\/\d{1,4})?)(?:\s*-?\s*(\p{L}{1,10}))?\s*$/u', $mark['text'], $m)) {
            return ['number' => $m[1], 'suffix' => self::suffix($m[2] ?? '', $skipWords), 'offset' => null, 'span' => null];
        }
        $rest = mb_substr($text, $mark['end'] + 1, 60);
        if (! preg_match(self::AFTER, $rest, $m)) {
            return null;
        }
        $suffix = self::suffix($m[4] ?? '', $skipWords);
        $span = $m[2] . ($suffix !== '' ? $m[3] . $m[4] : '');

        return [
            'number' => preg_replace('/\s+/u', '', $m[2]), 'suffix' => $suffix,
            'offset' => $mark['end'] + 1 + mb_strlen($m[1]), 'span' => $span,
        ];
    }

    private static function suffix(string $word, array $skipWords): string
    {
        return in_array($word, $skipWords, true) ? '' : $word;
    }
}
