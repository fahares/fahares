<?php

namespace App\Support;

use App\Suggestions\ProtectedColumns;

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
            $pieces = array_map(fn ($p) => str_replace("\u{E000}", '،', $p), preg_split('/\s*[،؛]\s*/u', self::separate($line)));
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

    /**
     * A line with the «،» Fankha leaves out around a count put back (step 14.168), so that the count starts a piece of
     * its own: after the binding or the paper («جلد: تیماج مشکی 33ص»، «کاغذ: شرقی 13گ (24-36)»), and before the
     * size, the format or the lines («103ص (172-275) اندازه: …»، «5ص (24-32) سطر»). A «،» inside the count's
     * parenthesis («27ص (16-43، 11و12)») is kept from the split as U+E000.
     */
    private static function separate(string $line): string
    {
        $line = preg_replace_callback('/\([^()]*\)/u', fn ($m) => str_replace('،', "\u{E000}", $m[0]), $line);
        $count = '[\d۰-۹]+\s*(?:گ|برگ|ص)(?=\s|\(|[،؛]|$)(?:\s*\([^()]*\))?';
        // the count glued to the binding or paper: a piece that starts with «جلد» or «کاغذ» and has no other number
        $line = preg_replace('/((?:^|[،؛])\s*(?:جلد|کاغذ)[^،؛\d۰-۹\[\]()]*?)\s+(' . $count . ')(?=\s*(?:[،؛]|\[|$|اندازه|قطع|[\d۰-۹]+\s*سطر|سطر))/u', '$1، $2', $line);
        // the size, format or lines glued after the count
        $line = preg_replace('/((?:^|[،؛])\s*' . $count . ')\s+(?=اندازه|قطع|(?:[\d۰-۹]+(?:\s*-\s*[\d۰-۹]+)?\s*)?سطر)/u', '$1، ', $line);

        return $line;
    }

    /**
     * The extent columns a copy's text gives (fahares:fill-extents and the corpus apply commands write them through
     * here), and what happens to its folios. The leaf count changes only where the text has one count and no reviewed
     * correction protects it; with no count in the text, or two of one kind, the copy is left as it is (columns null).
     *
     * @param  object  $row  the copy's id, folios and raw_text
     * @return array{kind: ?string, columns: ?array, site: ?int, raw: ?string}
     */
    public static function columns(object $row, ProtectedColumns $guard): array
    {
        $e = self::fromText($row->raw_text);
        $site = $row->folios === null ? null : (int) $row->folios;
        if ($e['raw'] === null) {
            return ['kind' => $site === null ? 'no_extent' : 'kept:no_count_in_text', 'columns' => null, 'site' => $site, 'raw' => null];
        }
        // Two counts of one kind: nothing of the extent is certain, so the copy is left as it is
        if ($e['counts'] > 1) {
            return ['kind' => 'kept:two_counts', 'columns' => null, 'site' => $site, 'raw' => $e['raw']];
        }
        $folios = $site;
        $kind = null;
        if ($e['counts'] === 1 && $e['folios'] !== $site) {
            $kind = match (true) {
                $site === null => 'folios_filled',
                $e['folios'] === null && ($e['pages'] === $site || intdiv((int) $e['pages'] + 1, 2) === $site) => 'pages_out_of_folios',
                default => 'folios_corrected',
            };
            if ($guard->strip($row->id, ['folios' => 1]) === []) {
                $kind = 'kept:protected';
            } else {
                $folios = $e['folios'];
            }
        }

        return ['kind' => $kind, 'site' => $site, 'raw' => $e['raw'], 'columns' => ['folios' => $folios, 'pages' => $e['pages'], 'extent_raw' => $e['raw'],
            'range_from' => $e['range_from'], 'range_to' => $e['range_to'], 'range_from_number' => $e['range_from_number'], 'range_to_number' => $e['range_to_number']]];
    }

    /** The stored extent columns of a copy row, typed as columns() gives them. */
    public static function stored(object $row): array
    {
        $int = fn ($v) => $v === null ? null : (int) $v;

        return ['folios' => $int($row->folios), 'pages' => $int($row->pages), 'extent_raw' => $row->extent_raw, 'range_from' => $row->range_from,
            'range_to' => $row->range_to, 'range_from_number' => $int($row->range_from_number), 'range_to_number' => $int($row->range_to_number)];
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
