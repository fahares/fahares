<?php

namespace App\Support;

/**
 * A copy's extent read from its Fankha text (backlog item 97), with the rule parser v2 uses: each line is cut on «،»
 * and «؛», and a piece (without a closing «[ف: …]» citation) that starts with a number and «گ», «برگ» or «ص» is the count; a parenthesis or «N-N» right after
 * it is the leaves or pages it takes in a volume. «32گ (1پ-32پ)» is 32 leaves from 1 verso to 32 verso; «28ص (1-28)»
 * 28 pages; «86گ، 170ص» both. A page count is never turned into leaves.
 */
class CopyExtent
{
    private const DIGITS = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9'];

    /**
     * @return array{folios: ?int, pages: ?int, raw: ?string, range_from: ?string, range_to: ?string, range_from_number: ?int, range_to_number: ?int, counts: int}
     */
    public static function fromText(?string $text): array
    {
        $result = ['folios' => null, 'pages' => null, 'raw' => null, 'range_from' => null, 'range_to' => null, 'range_from_number' => null, 'range_to_number' => null, 'counts' => 0];
        $raws = [];
        $range = null;
        foreach (preg_split('/\R/u', (string) $text) as $line) {
            $line = trim(preg_replace('/<!--\s*page:\s*\d+\s*-->/u', ' ', $line));
            // A piece of an incipit hardly starts with «12گ»: the physical part may follow «آغاز: …؛» on one line
            if ($line === '') {
                continue;
            }
            $pieces = preg_split('/\s*[،؛]\s*/u', $line);
            foreach ($pieces as $i => $piece) {
                $piece = trim(strtr($piece, self::DIGITS));
                // «2گ [ف: 3-1233]»: the catalogue's citation can close the line
                $piece = trim(preg_replace('/\s*\[[^\[\]]*\]\s*\.?\s*$/u', '', $piece));
                if (! preg_match('/^(\d+)\s*(گ|برگ|ص)(?=\s|\(|$)\s*(?:\(([^()]*)\))?\s*\.?$/u', $piece, $m)) {
                    continue;
                }
                $count = (int) $m[1];
                $inner = $m[3] ?? '';
                // «12گ، 37-48»: the range in the next piece
                if ($inner === '' && isset($pieces[$i + 1]) && preg_match('/^\(?\s*\d+\s*[رپ]?\s*[-–]\s*\d+\s*[رپ]?\s*\)?$/u', trim(strtr($pieces[$i + 1], self::DIGITS)))) {
                    $inner = trim(strtr($pieces[$i + 1], self::DIGITS), '() ');
                    $piece .= ' (' . $inner . ')';
                }
                if ($m[2] === 'ص') {
                    $result['pages'] = $count;
                    $pageCounts = ($pageCounts ?? 0) + 1;
                } else {
                    $result['folios'] = $count;
                    $leafCounts = ($leafCounts ?? 0) + 1;
                }
                $raws[] = $piece;
                if ($inner !== '' && $range === null) {
                    $range = self::range($inner);
                }
            }
            if ($raws) {
                break;
            }
        }
        if (! $raws) {
            return $result;
        }
        $result['raw'] = mb_substr(implode('، ', $raws), 0, 120);
        // «216گ (21×31)، 25گ (15×23)»: two leaf counts, and no telling which is the copy's; «346ص، 244 گ» is one of each
        $result['counts'] = max($leafCounts ?? 0, $pageCounts ?? 0);
        if ($range) {
            [$result['range_from'], $result['range_to'], $result['range_from_number'], $result['range_to_number']] = $range;
        }

        return $result;
    }

    /** «34پ-43ر», «1-28», «271»: the ends as written (with «ر» or «پ») and as numbers. */
    private static function range(string $inner): ?array
    {
        if (! preg_match('/^(\d+)\s*([رپ]?)\s*(?:[-–]\s*(\d+)\s*([رپ]?))?$/u', trim($inner), $m)) {
            return null;
        }
        $from = (int) $m[1];
        $to = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $from;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
            [$m[2], $m[4]] = [$m[4] ?? '', $m[2]];
        }

        return [$from . ($m[2] ?? ''), $to . ($m[4] ?? ($m[2] ?? '')), $from, $to];
    }
}
