<?php

namespace App\Sources\Extractors;

use App\Console\Commands\MatchCatalogEditionCommand as Format;

/**
 * Catalogues whose entries are headed by the shelfmarks of their copies, «( ش ۳۴۳ و۸۲۸ و۸۶۴ )», then the work's
 * title, author and incipit, then each copy on a line of its own, «* ش ۸۲۸ ، رقعی ، تحریر سده ۱۱ و ۱۲»; a copy
 * described in the heading's entry alone (the Qurans, «( ش ۹۶۳ )» and its hand and date); and composite volumes headed
 * «( ش ۶۵۱ - خشتی )» with their treatises «۱ - اکسیرالعارفین ملاصدرا ، … تحریر ۱۲۷۹ .» (the Marvi school's catalogue,
 * backlog item 147). Footnotes, after «---» on a page, belong to no entry.
 *
 * A copy stands on every page from its entry's heading to the next heading, since Fankha cites the page of its line.
 */
class ListedShelfmarkCatalogueExtractor implements EntryTextExtractor
{
    /** An entry's heading: «( ش ۳۴۳ و۸۲۸ )», «( ۳۶ و ۴۷۲ و ۶۱۶ )», a composite volume's «( ش ۶۴۰ - خشتی )». */
    private const HEADING = '/^\(\s*(?:ش\s*)?(\d[\d\sو،,ش\[\]؟]*?)\s*(?:[-–ـ]\s*([^\d()][^()]*?))?\s*\)\s*\.?$/u';

    /** A copy's line: «* ش ۴۳۹ ، وزیری …», «* ۶۳۵ ، خشتی …», «ش ۸۴۹ ، رقعی …». */
    private const COPY = '/^\s*(?:\*\s*(?:ش\s*)?|ش\s*)(\d+)(?:\s*[،,.]|\s|$)/u';

    /** A treatise of a composite volume, «۲ - رسالة فی خلق الاعمال …». */
    private const ITEM = '/^\s*(\d{1,3})\s*[-–ـ]\s*\S/u';

    /** @var array<string, array> the reading of each volume's pages */
    private static array $read = [];

    public function extract(array $pages, string $shelfmark, int $page, array $hints = []): ?array
    {
        if (! $pages || ! preg_match('/(\d+)(?:\s*\/\s*(\d+))?/', Format::digits($shelfmark), $m)) {
            return null;
        }
        $key = md5(implode(',', array_keys($pages)) . '|' . array_sum(array_map('strlen', $pages)));
        $read = self::$read[$key] ??= self::read($pages);
        $number = (int) $m[1];
        $part = isset($m[2]) ? (int) $m[2] : null;
        $entry = null;
        foreach ($read['entries'] as $e) {
            if (in_array($number, $e['numbers'], true) && $e['page_start'] <= $page && $e['page_last'] >= $page) {
                $entry = $e;

                break;
            }
        }
        if (! $entry) {
            return null;
        }
        $lines = $read['lines'];
        $body = range($entry['line'] + 1, $entry['end'] - 1);
        $at = $entry['line'];
        if (isset($entry['copies'][$number])) {
            // the work's heading and description; the copy's own line
            $copy = $entry['copies'][$number];
            $first = min($entry['copies']);
            $entryLines = range($entry['line'], $first - 1);
            $copyLines = [$copy];
            $at = $copy;
        } elseif ($entry['composite']) {
            // the volume's heading and the treatise the shelfmark names, or all of them
            $item = $part !== null ? ($entry['items'][$part] ?? null) : null;
            if ($item === null && $part !== null && ! empty($hints['strict_part'])) {
                return null;
            }
            $entryLines = $item ? range($item['from'], $item['to'] - 1) : $body;
            // the volume's size in its heading, the treatise's hand and date in its line
            $copyLines = $item ? [$entry['line'], ...$entryLines] : [$entry['line']];
            $at = $item['from'] ?? $entry['line'];
        } else {
            // a copy described in its entry alone
            $entryLines = [$entry['line']];
            $copyLines = $body;
        }
        $last = $lines[$entry['end'] - 1]['page'];

        return [
            'entry_text' => self::join($lines, $entryLines),
            'copy_text' => self::join($lines, $copyLines),
            'page_start' => $entry['page_start'],
            'page_end' => $last > $entry['page_start'] ? $last : null,
            'shelfmark_page' => $lines[$at]['page'],
            'entry_number' => null,
        ];
    }

    /**
     * The volume's lines (without footnotes) and its entries: the shelfmarks of the heading, its first line and the
     * line after its last, its copy lines (shelfmark => line), its treatises (number => from, to) and its pages.
     *
     * @return array{lines: list<array{page: int, text: string}>, entries: list<array>}
     */
    public static function read(array $pages): array
    {
        $lines = [];
        foreach ($pages as $page => $text) {
            foreach (preg_split('/\R/u', (string) $text) as $line) {
                if (trim($line) === '---') {
                    break;
                }
                if (trim($line) !== '') {
                    $lines[] = ['page' => (int) $page, 'text' => trim($line)];
                }
            }
        }
        $heads = [];
        foreach ($lines as $i => $line) {
            if (preg_match(self::HEADING, Format::digits($line['text']), $h)) {
                preg_match_all('/\d+/', $h[1], $n);
                $heads[] = [$i, array_map('intval', $n[0]), isset($h[2]) && trim($h[2]) !== ''];
            }
        }
        $entries = [];
        foreach ($heads as $k => [$from, $numbers, $composite]) {
            $end = $heads[$k + 1][0] ?? count($lines);
            $copies = [];
            $items = [];
            $open = null;
            for ($i = $from + 1; $i < $end; $i++) {
                $digits = Format::digits($lines[$i]['text']);
                // a copy line under a heading that leaves its number out, or that the print left unreadable, «( ش ۰۰۰[؟] )»
                if (! $composite && preg_match(self::COPY, $digits, $c)) {
                    $copies[(int) $c[1]] ??= $i;
                } elseif ($composite && preg_match(self::ITEM, $digits, $it)) {
                    if ($open !== null) {
                        $items[$open]['to'] = $i;
                    }
                    $open = (int) $it[1];
                    $items[$open] = ['from' => $i, 'to' => $end];
                }
            }
            $entries[] = [
                'line' => $from, 'end' => $end, 'numbers' => array_values(array_unique([...$numbers, ...array_keys($copies)])), 'composite' => $composite,
                'copies' => $copies, 'items' => $items,
                'page_start' => $lines[$from]['page'], 'page_last' => $lines[$end - 1]['page'],
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
