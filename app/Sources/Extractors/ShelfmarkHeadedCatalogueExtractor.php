<?php

namespace App\Sources\Extractors;

use App\Console\Commands\MatchCatalogEditionCommand as Format;

/**
 * Catalogues of one library whose every entry is one copy headed by its shelfmark on a line of its own, «۶۵۴۷» or
 * «(۸۸۴۹)», «۱۰۱۱۹ مجموعه» (the Majlis catalogue, vols 20, 29 and 32 as their Zarnegar text layer gives them, backlog
 * item 147). A composite volume lists its treatises «۱. القصیدة الدالیة (شعر ـ عربی)» and closes, as every entry does,
 * with the copy's own description: «خط: …», «کاغذ: …», then the binding, the size and leaves, and «(ثبت ۶۱۹۶۴)».
 *
 * The heading is the entry text, with the one treatise a shelfmark «6548/3» names; the description is the copy text.
 * A copy stands on every page from its heading's to the next heading's, since Fankha cites the page of a treatise.
 */
class ShelfmarkHeadedCatalogueExtractor implements EntryTextExtractor
{
    /** A shelfmark heading: the number alone, in brackets or not, with «مجموعه» after it at times. */
    private const HEADING = '/^\(?\s*(\d{2,6})\s*\)?\s*(?:مجموعه\s*:?)?\s*$/u';

    /** A treatise of a composite volume, «۳. الایساغوجی …», «۱. [ادعیه] «۱ پ ـ ۱۸ پ» …». */
    private const ITEM = '/^\s*(\d{1,3})\s*\.\s*\S/u';

    /** Where the copy's description begins: its script, paper, or (vol 29) the hand of its first part. */
    private const COPY = '/^\s*(?:(?:خط|نوع\s*خط|خط\s*مجموعه|خطوط|کاغذ)\s*:|بخش\s*(?:اول|نخست|یکم)\s)/u';

    /** How far the next heading's number may run ahead of the last one: a stray number line is not a heading. */
    private const STEP = 60;

    /** @var array<string, array> the reading of each volume's pages */
    private static array $read = [];

    public function extract(array $pages, string $shelfmark, int $page, array $hints = []): ?array
    {
        if (! $pages || ! preg_match('/(\d+)(?:\s*\/\s*(\d+))?/', Format::digits($shelfmark), $m)) {
            return null;
        }
        $key = md5(implode(',', array_keys($pages)) . '|' . array_sum(array_map('strlen', $pages)));
        $read = self::$read[$key] ??= self::read($pages);
        $part = isset($m[2]) ? (int) $m[2] : null;
        $entry = null;
        foreach ($read['entries'][(int) $m[1]] ?? [] as $e) {
            if ($e['page_start'] <= $page && $e['page_last'] >= $page) {
                $entry = $e;

                break;
            }
        }
        if (! $entry) {
            return null;
        }
        $lines = $read['lines'];
        $copy = $entry['copy'] ?? $entry['end'];
        $body = range($entry['line'], $copy - 1);
        $at = $entry['line'];
        if ($part !== null && ($item = $entry['items'][$part] ?? null)) {
            $first = $entry['items'] ? min(array_column($entry['items'], 'from')) : $copy;
            $body = [...range($entry['line'], $first - 1), ...range($item['from'], min($item['to'], $copy) - 1)];
            $at = $item['from'];
        } elseif (! empty($hints['strict_part']) && $part !== null && $entry['items']) {
            return null;
        }
        $last = $lines[$entry['end'] - 1]['page'];

        return [
            'entry_text' => self::join($lines, $body),
            'copy_text' => $copy < $entry['end'] ? self::join($lines, range($copy, $entry['closed'] - 1)) : null,
            'page_start' => $entry['page_start'],
            'page_end' => $last > $entry['page_start'] ? $last : null,
            'shelfmark_page' => $lines[$at]['page'],
            'entry_number' => null,
        ];
    }

    /**
     * The volume's lines and its entries by shelfmark: each with its first line, the line after its last, its
     * treatises (number => from, to), the line its copy description begins at, and its pages.
     *
     * @return array{lines: list<array{page: int, text: string}>, entries: array<int, list<array>>}
     */
    public static function read(array $pages): array
    {
        $lines = [];
        foreach ($pages as $page => $text) {
            foreach (preg_split('/\R/u', (string) $text) as $line) {
                if (trim($line) !== '') {
                    $lines[] = ['page' => (int) $page, 'text' => trim($line)];
                }
            }
        }
        $heads = [];
        $last = null;
        foreach ($lines as $i => $line) {
            if (preg_match(self::HEADING, Format::digits($line['text']), $h)) {
                $n = (int) $h[1];
                if ($last === null || ($n > $last && $n - $last <= self::STEP)) {
                    $heads[] = [$i, $n];
                    $last = $n;
                }
            }
        }
        $entries = [];
        foreach ($heads as $k => [$from, $n]) {
            $end = $heads[$k + 1][0] ?? count($lines);
            $items = [];
            $copy = null;
            $open = null;
            for ($i = $from + 1; $i < $end; $i++) {
                $digits = Format::digits($lines[$i]['text']);
                if (preg_match(self::ITEM, $digits, $it) && ! isset($items[(int) $it[1]])) {
                    if ($open !== null) {
                        $items[$open]['to'] = $i;
                    }
                    $open = (int) $it[1];
                    $items[$open] = ['from' => $i, 'to' => $end];
                    $copy = null;
                } elseif ($copy === null && preg_match(self::COPY, $digits)) {
                    $copy = $i;
                }
            }
            // the description that closes the volume ends its last treatise
            if ($open !== null && $copy !== null && $copy > $items[$open]['from']) {
                $items[$open]['to'] = $copy;
            }
            // the register number closes the copy, «(ثبت ۶۱۹۶۴)», «[شماره ثبت: ۸۳۷۴۰]»: a stray line after it is not its own
            $closed = $end;
            for ($i = $end - 1; $copy !== null && $i > $copy; $i--) {
                if (preg_match('/ثبت/u', $lines[$i]['text'])) {
                    $closed = $i + 1;

                    break;
                }
            }
            $entries[$n][] = [
                'line' => $from, 'end' => $end, 'closed' => $closed, 'items' => $items, 'copy' => $copy,
                'page_start' => $lines[$from]['page'],
                // the next heading's page too: a copy whose description runs to the top of it
                'page_last' => $lines[$end - 1]['page'],
            ];
        }

        return ['lines' => $lines, 'entries' => $entries];
    }

    /** The lines, a paragraph each. */
    private static function join(array $lines, array $ids): ?string
    {
        $text = implode("\n", array_map(fn ($i) => $lines[$i]['text'], $ids));

        return $text === '' ? null : $text;
    }
}
