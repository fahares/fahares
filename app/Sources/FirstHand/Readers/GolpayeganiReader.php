<?php

namespace App\Sources\FirstHand\Readers;

use App\Sources\FirstHand\CopyLine;

/**
 * The copy line of «فهرست نسخ خطی کتابخانه عمومی آیت‌الله گلپایگانی» (backlog item 147, step 14.250):
 *
 *   ۸۶۴۶/۳-۵۸/۹۶ is the shelfmark, after «نسخه شماره:» on the line «۱۴۲۸۷) نسخه شماره: …»;
 *   «آغاز:» and «انجام:» lines may follow;
 *   the last line is the copy: notes, script, scribe or «بی کا», date or «بی تا», «جلد:…», «۴برگ», a leaf range
 *   «۷۷-۸۰», «۷ سطر» or «سطور مختلف», and «۱۳×۱۸سم», in that order, cut on «،».
 */
class GolpayeganiReader implements Reader
{
    public function read(string $text): CopyLine
    {
        $line = new CopyLine();
        $text = CopyLine::normalize($text);
        $rows = preg_split('/\R/u', $text);

        // the shelfmark is the run of numbers, «/», «-» and spaces; a copy line run onto the same row is left out
        if (preg_match('/نسخه شماره\s*:\s*([\d\/\-\s]*\d)/u', $rows[0] ?? '', $m)) {
            $line->set('shelfmark', trim($m[1]), $m[0]);
        }
        $last = '';
        foreach (array_slice($rows, 1) as $row) {
            if (! preg_match('/^(آغاز|انجام)\s*:/u', $row) && trim($row) !== '') {
                $last = $row;
            }
        }
        if ($last === '') {
            return $line;
        }

        $pieces = array_values(array_filter(array_map('trim', preg_split('/\s*،\s*/u', $last)), fn ($p) => $p !== ''));
        $scriptAt = null;
        foreach ($pieces as $i => $piece) {
            if (CopyLine::scriptsAt($piece) && ($scriptAt === null || $scriptAt === $i - 1)) {
                // «نسخ، نستعلیق»: scripts in neighbouring pieces are one hand's list
                $scripts = array_merge($line->values['scripts'] ?? [], CopyLine::scriptsAt($piece));
                $raw = isset($line->raw['scripts']) ? $line->raw['scripts'] . '، ' . $piece : $piece;
                $line->set('scripts', array_values(array_unique($scripts)), $raw);
                $scriptAt = $i;
            }
            if (preg_match('/^(\d+)\s*برگ$/u', $piece, $m)) {
                $line->set('folios', (int) $m[1], $piece);
            } elseif (preg_match('/^(\d+)\s*سطر$/u', $piece, $m)) {
                $line->set('lines', (int) $m[1], $piece);
            } elseif (preg_match('/^(\d+(?:\.\d+)?)\s*[×x*]\s*(\d+(?:\.\d+)?)\s*سم$/u', $piece, $m)) {
                $line->set('dimensions', self::size($m[1], $m[2]), $piece);
            }
        }

        // after the script: the scribe («بی کا» for none), then the date («بی تا» for none)
        $from = $scriptAt === null ? null : $scriptAt + 1;
        if ($from !== null && isset($pieces[$from])) {
            $scribe = $pieces[$from];
            if (preg_match('/^(بی\s*کا|بدون کاتب)(?!\p{L})/u', $scribe)) {
                $line->set('bika', true, $scribe);
            } elseif (CopyLine::isAutographWord($scribe)) {
                $line->set('autograph', true, $scribe);
            } elseif (! preg_match('/\d|^بی\s*تا|^جلد|^قرن|^بدون جلد/u', $scribe) && mb_strlen($scribe) <= 80 && ! CopyLine::isNote($scribe)) {
                $line->set('scribe', $scribe, $scribe);
            } else {
                $from--; // no scribe piece: the date follows the script
            }
            $date = $pieces[$from + 1] ?? null;
            if ($date !== null && preg_match('/^بی\s*تا$/u', $date)) {
                $line->set('bita', true, $date);
            } elseif ($date !== null && CopyLine::isDate($date)) {
                $line->set('copy_date', $date, $date);
            }
        }

        return $line;
    }

    /** «۱۱×۲۳» → [11, 23], the smaller first: catalogues write width and height in either order. */
    public static function size(string $a, string $b): array
    {
        $pair = [(float) $a, (float) $b];
        sort($pair);

        return $pair;
    }
}
