<?php

namespace App\Support;

use App\Console\Commands\LinkCatalogVolumesCommand as Link;

/**
 * The photocopy number series of Markaz-e Ehya and Mar'ashi in Qom (backlog item 174). Each library numbers its
 * manuscripts in one series (described in its manuscript catalogue, and for Ehya in Mohaddes Armavi's catalogue too)
 * and its photocopies in another («فهرست نسخه‌های عکسی»), each from 1. Fankha often prints the photocopy's number
 * without «عکسی»: «645» of Ehya's photocopy catalogue is not the volume of the manuscripts «645/1» to «645/6».
 *
 * What tells them apart is the catalogue the entry cites first: «[عکسی ف: 2-279]» (also «عکسی - ف», «عکسی مرعشی: ف»,
 * «عکاسی ف», «عكسى مركز احياء: ف») is the photocopy catalogue. A copy of that series takes the mark «عکسی» in its
 * volume key («645-عکسی»), the key a shelfmark written with the mark already had in Mar'ashi («1390/1عکسی»). A
 * shelfmark of two numbers whose other number carries the mark («2007/5 و 2191/5 عکسی», with «[محدث ارموی مخ: …] و
 * [عکسی ف: …]») is keyed by its manuscript number, which is plain.
 */
class PhotoSeries
{
    /** The mark of the photocopy series in a volume key and beside a shown shelfmark. */
    public const MARK = 'عکسی';

    /** The libraries with a photocopy series, as the first line of a Fankha text names them. */
    private const LIBRARIES = ['مرکز احیاء', 'مرکز احیا', 'مرعشی'];

    /** Whether the copy's text is of a library with a photocopy series and its first cited catalogue is the photocopy one. */
    public static function photo(?string $rawText, ?string $shelfmark = null): bool
    {
        if (! self::library($rawText)) {
            return false;
        }
        // the mark sits on the shelfmark's other number: the keyed one is a manuscript number
        if ($shelfmark !== null && preg_match('/\d\s*و\s*/u', $shelfmark) && preg_match('/عک|عك/u', $shelfmark)) {
            return false;
        }
        foreach (CatalogCitations::brackets($rawText) as $bracket) {
            foreach (CatalogCitations::places($bracket) as $place) {
                return (bool) preg_match('/عکا?س[یى]/u', Link::normalize((string) $place['series']));
            }
        }

        return false;
    }

    /** Whether a copy's Fankha text is of Markaz-e Ehya or Mar'ashi: «38. قم؛ مرکز احیاء؛ شماره نسخه: …». */
    public static function library(?string $rawText): bool
    {
        if ($rawText === null || ! preg_match('/^\s*(?:\d+\.\s*)?[^؛\n]+؛\s*([^؛\n]+?)\s*؛/u', $rawText, $m)) {
            return false;
        }

        return in_array(strtr($m[1], ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک']), self::LIBRARIES, true);
    }
}
