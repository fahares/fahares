<?php

namespace App\Support\PageLinks;

use App\Support\BibliographyLinks;

/**
 * A source's references to a volume and page of itself in its page texts (step 13.155): al-Dhari'a's «مر في (161:7)
 * بعنوان خسرو و گل» (page 161 of volume 7), «ذكرناه في (ج 3 - ص 378)», «راجع: آداب الخط 104:19». The forms, the
 * words that tell a reference is to the source itself and those that give the entry's title come from
 * config('page_links.<source>.references'). Most such numbers in al-Dhari'a cite other books («لباب الألباب (ج 2 - ص
 * 282)»), so a reference without the cue right before it, or right after another reference, is not read.
 *
 * A source may refer so to another one, by a mark of its own that needs no cue (step 14.103): Tabaqat's «(ذ 243:16)»
 * and «(ذ 4 رقم 483)», page and volume or volume and entry number of al-Dhari'a. With order «fit» a pair is read in the
 * one order that is a page al-Dhari'a has; when both are, only an entry number tells which (EntryResolver).
 */
class References
{
    /**
     * The page's references, in order: where they stand in the page's text and their words, the volume and page, the
     * title they give (or null), and the words before them.
     *
     * @return list<array{offset: int, span: string, volume: ?int, page: ?int, title: ?string, before: string, number: ?int, places: list<array{0: int, 1: ?int}>}>
     */
    public static function in(PageText $page, array $config): array
    {
        [$plain, $at] = $page->plain();
        $found = [];
        // chain_forms are read only right after another reference: «(ذ 15 رقم 382 و 1275)», «ذ 150:4 و 495», «(ذ 56:2 و 221:11)»
        $forms = array_merge(array_map(fn ($f) => [$f, false], $config['forms']), array_map(fn ($f) => [$f, true], $config['chain_forms'] ?? []));
        foreach ($forms as [$form, $chain]) {
            preg_match_all('/' . $form . '/u', $plain, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
            foreach ($matches as $m) {
                $start = mb_strlen(substr($plain, 0, $m[0][1]));
                $found[$start] ??= ['start' => $start, 'length' => mb_strlen($m[0][0]), 'm' => $m, 'chain' => $chain];
            }
        }
        ksort($found);

        $refs = [];
        $lastEnd = -1;
        foreach ($found as $start => $f) {
            if ($start < $lastEnd) {
                continue;
            }
            $m = $f['m'];
            $number = isset($m['number'][0]) ? (int) $m['number'][0] : null;
            $places = isset($m['bare'][0]) ? [] : self::places($config, $m, $number);
            $before = preg_replace('/\s+/u', ' ', mb_substr($plain, max(0, $start - 120), min(120, $start)));
            $after = preg_replace('/\s+/u', ' ', mb_substr($plain, $start + $f['length'], 120));
            // «(199:3) و (311:5)», «مر في (161:7) بعنوان خسرو و گل و في (232:18)»: right after another reference
            $chained = $refs && end($refs)['end'] > $start - 90 && preg_match('/^\s*\)?\s*(?:،?\s*بعنوان\s+[^().\d\n؛]{1,60}?)?\s*(?:و|،)\s*(?:في)?\s*\(?\s*$/u',
                mb_substr($plain, end($refs)['end'], $start - end($refs)['end']));
            $cued = ! $f['chain'] && (($config['cues'] ?? null) === null || preg_match('/' . $config['cues'] . '/u', $before));
            if ($chained && isset($m['bare'][0])) {
                // a bare number goes on from the reference before it: another entry number of its volume, or a page
                $last = end($refs);
                // «ذ 113:18 و 120 رقم 991»: a page of that volume, with its own entry number
                if ($last['number'] !== null && $number === null) {
                    $number = (int) $m['bare'][0];
                    $places = array_values(array_unique(array_map(fn ($p) => [$p[0], null], $last['places']), SORT_REGULAR));
                } elseif ($last['volume'] !== null && ($p = BibliographyLinks::place($config['bibliography'], $last['volume'], (int) $m['bare'][0], true))) {
                    $places = [$p];
                }
            }
            if (! $places || (count($places) > 1 && $number === null) || ! ($chained || $cued)) {
                continue;
            }

            // where the reference stands in the text with the marks: it must hold none
            $from = $at[$start];
            $to = $at[$start + $f['length'] - 1] + 1;
            $span = mb_substr($page->page->text, $from, $to - $from);
            if (preg_match('/[\x{E020}-\x{E022}]/u', $span)) {
                continue;
            }
            $title = (isset($config['title_after']) && preg_match('/' . $config['title_after'] . '/u', $after, $t))
                || (isset($config['title_before']) && preg_match('/' . $config['title_before'] . '/u', $before, $t))
                ? trim($t['title']) : null;
            $one = count($places) === 1 ? $places[0] : [null, null];
            $refs[] = ['offset' => $from, 'span' => $span, 'volume' => $one[0], 'page' => $one[1], 'title' => $title,
                'before' => $before, 'number' => $number, 'places' => $places, 'end' => $start + $f['length']];
            $lastEnd = $start + $f['length'];
        }

        return array_map(fn ($r) => array_diff_key($r, ['end' => 1]), $refs);
    }

    /**
     * The volume and page a match may be, as BibliographyLinks::place() reads them: one place, or with order «fit» each
     * order that fits; a volume with an entry number and no page has the page null.
     *
     * @return list<array{0: int, 1: ?int}>
     */
    private static function places(array $config, array $m, ?int $number): array
    {
        $key = $config['bibliography'];
        if (isset($m['volume'][0]) && ! isset($m['page'][0])) {
            $volume = (int) $m['volume'][0];

            return $number !== null && BibliographyLinks::place($key, $volume, 1, true) ? [[$volume, null]] : [];
        }
        if (isset($m['volume'][0])) {
            return ($p = BibliographyLinks::place($key, (int) $m['volume'][0], (int) $m['page'][0], true)) ? [$p] : [];
        }
        [$first, $second] = [(int) $m['first'][0], (int) $m['second'][0]];
        if (($config['order'] ?? null) !== 'fit') {
            return ($p = BibliographyLinks::place($key, $first, $second)) ? [$p] : [];
        }
        $fits = array_values(array_filter([[$second, $first], [$first, $second]], fn ($vp) => BibliographyLinks::place($key, $vp[0], $vp[1], true) !== null));

        return $first === $second ? array_slice($fits, 0, 1) : $fits;
    }
}
