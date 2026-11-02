<?php

namespace App\Support\PageLinks;

use App\Support\BibliographyLinks;

/**
 * A source's references to a volume and page of itself in its page texts (step 13.155): al-Dhari'a's «مر في (161:7)
 * بعنوان خسرو و گل» (page 161 of volume 7), «ذكرناه في (ج 3 - ص 378)», «راجع: آداب الخط 104:19». The forms, the
 * words that tell a reference is to the source itself and those that give the entry's title come from
 * config('page_links.<source>.references'). Most such numbers in al-Dhari'a cite other books («لباب الألباب (ج 2 - ص
 * 282)»), so a reference without the cue right before it, or right after another reference, is not read.
 */
class References
{
    /**
     * The page's references, in order: where they stand in the page's text and their words, the volume and page, the
     * title they give (or null), and the words before them.
     *
     * @return list<array{offset: int, span: string, volume: int, page: int, title: ?string, before: string}>
     */
    public static function in(PageText $page, array $config): array
    {
        [$plain, $at] = $page->plain();
        $found = [];
        foreach ($config['forms'] as $form) {
            preg_match_all('/' . $form . '/u', $plain, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);
            foreach ($matches as $m) {
                $start = mb_strlen(substr($plain, 0, $m[0][1]));
                $found[$start] ??= ['start' => $start, 'length' => mb_strlen($m[0][0]), 'm' => $m];
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
            $place = isset($m['volume'][0])
                ? BibliographyLinks::place($config['bibliography'], (int) $m['volume'][0], (int) $m['page'][0], true)
                : BibliographyLinks::place($config['bibliography'], (int) $m['first'][0], (int) $m['second'][0]);
            $before = preg_replace('/\s+/u', ' ', mb_substr($plain, max(0, $start - 120), min(120, $start)));
            $after = preg_replace('/\s+/u', ' ', mb_substr($plain, $start + $f['length'], 120));
            // «(199:3) و (311:5)», «مر في (161:7) بعنوان خسرو و گل و في (232:18)»: right after another reference
            $chained = $refs && end($refs)['end'] > $start - 90 && preg_match('/^\s*\)?\s*(?:،?\s*بعنوان\s+[^().\d\n؛]{1,60}?)?\s*(?:و|،)\s*(?:في)?\s*\(?\s*$/u',
                mb_substr($plain, end($refs)['end'], $start - end($refs)['end']));
            if (! $place || ! ($chained || preg_match('/' . $config['cues'] . '/u', $before))) {
                continue;
            }

            // where the reference stands in the text with the marks: it must hold none
            $from = $at[$start];
            $to = $at[$start + $f['length'] - 1] + 1;
            $span = mb_substr($page->page->text, $from, $to - $from);
            if (preg_match('/[\x{E020}-\x{E022}]/u', $span)) {
                continue;
            }
            $title = preg_match('/' . $config['title_after'] . '/u', $after, $t) || preg_match('/' . $config['title_before'] . '/u', $before, $t)
                ? trim($t['title']) : null;
            $refs[] = ['offset' => $from, 'span' => $span, 'volume' => $place[0], 'page' => $place[1], 'title' => $title,
                'before' => $before, 'end' => $start + $f['length']];
            $lastEnd = $start + $f['length'];
        }

        return array_map(fn ($r) => array_diff_key($r, ['end' => 1]), $refs);
    }
}
