<?php

namespace App\Support;

/**
 * Noor's marks in a page's text (step 13.92): a span opens with U+E020, its kind and U+E021, and closes with U+E022.
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
}
