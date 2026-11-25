<?php

namespace App\Support;

/**
 * Noor's marks in a page's text (step 13.95): a span opens with U+E020, its kind and U+E021, and closes with U+E022.
 * The kinds are «ayah 18:46» (a Quran verse, sura and verse), «hadith», «latin» (a passage in Latin letters), and in
 * al-Dhari'a the names Noor tags («WB» a book's title, «FB» a person, «XB» a year, «QB» a place, «OB» where a copy is
 * …). The Noor readers in scripts/sources write them; the page text sets them, and whatever reads a page for its
 * words (segmenting it into entries, taking an entry's text) strips them.
 */
class PageMarks
{
    public const PATTERN = '/\x{E020}[^\x{E021}]*\x{E021}|\x{E022}/u';

    public static function strip(string $text): string
    {
        return preg_replace(self::PATTERN, '', $text);
    }

    /**
     * Every mark of a page's text in order: its ordinal, kind, words, and the plain words before and after it. A mark
     * left open, or one holding another, is skipped (sources:link-dharia-titles read them so, step 13.129).
     *
     * @return list<array{ordinal: int, kind: string, text: string, before: string, after: string, after_raw: string}>
     */
    public static function marksOf(string $text): array
    {
        $marks = [];
        preg_match_all('/\x{E020}([^\x{E021}]*)\x{E021}/u', $text, $opens, PREG_OFFSET_CAPTURE);
        foreach ($opens[0] as $n => [$open, $at]) {
            $kind = $opens[1][$n][0];
            $start = $at + strlen($open);
            $close = strpos($text, "\u{E022}", $start);
            $inner = $close === false ? '' : substr($text, $start, $close - $start);
            if ($close === false || str_contains($inner, "\u{E020}")) {
                continue;
            }
            $marks[] = [
                'ordinal' => $n,
                'kind' => $kind,
                'text' => trim($inner),
                'before' => mb_substr(self::strip(substr($text, 0, $at)), -120),
                'after' => mb_substr(self::strip(substr($text, $close + strlen("\u{E022}"))), 0, 80),
                'after_raw' => substr($text, $close + strlen("\u{E022}"), 400),
            ];
        }

        return $marks;
    }
}
