<?php

namespace App\Sources\Extractors;

use App\Console\Commands\MatchCatalogEditionCommand as Format;

/**
 * Catalogues whose copies are numbered in a series of their own, the number centred on a line, «۱۱۹», and each copy's
 * line begins with its shelfmark and goes on with its hand, date, leaves and size, «۶۹۱ نستعلیق محمد قلی … ۲۴۶ گ،
 * ۱۲×۱۹/۴، …»; a composite volume then lists its treatises «۳ـ رسالة لابی یوسف یعقوب بن اسحق الکندی … (۶ر ـ ۸پ).»
 * (the Malek library catalogue, vol. 5, backlog item 147).
 *
 * The copy's line is the copy text; the treatise a shelfmark «691/3» names, or the volume's whole list, is the entry
 * text. A copy stands on every page from its number's to the next number's, since Fankha cites the page of a treatise.
 */
class SerialShelfmarkCatalogueExtractor implements EntryTextExtractor
{
    /** The series number on a line of its own, «۱۱۹», «[۴۷]», «۹۲[؟]». */
    private const SERIAL = '/^\[?\d{1,4}\]?(?:\[؟\])?$/u';

    /** The copy's line after it, beginning with the shelfmark, «۶۹۱ نستعلیق …», «۳۰۷ ۱۱۱ برگ …», «[۴۷۴] …», or the shelfmark alone. */
    private const SHELF = '/^\[?(\d{1,6})\]?(?:\[؟\])?(?:\s+\S|\s*[^\d\s\/:]|$)/u';

    /** A treatise, «۳ـ رسالة …», «۱۱ - حاشیة …». */
    private const ITEM = '/^\s*(\d{1,3})(?:\[؟\])?\s*[-–ـ]\s*\S/u';

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
        $first = $entry['items'] ? min(array_column($entry['items'], 'from')) : $entry['end'];
        $item = $part !== null ? ($entry['items'][$part] ?? null) : null;
        if ($item === null && $part !== null && $entry['items'] && ! empty($hints['strict_part'])) {
            return null;
        }
        $body = $item ? range($item['from'], $item['to'] - 1) : ($first < $entry['end'] ? range($first, $entry['end'] - 1) : []);
        $last = $lines[$entry['end'] - 1]['page'];

        return [
            'entry_text' => self::join($lines, $body),
            'copy_text' => self::join($lines, range($entry['line'] + 1, $first - 1)),
            'page_start' => $entry['page_start'],
            'page_end' => $last > $entry['page_start'] ? $last : null,
            'shelfmark_page' => $lines[$item['from'] ?? $entry['line']]['page'],
            'entry_number' => $entry['serial'],
        ];
    }

    /**
     * The volume's lines and its copies by shelfmark: each with its series number, the line of that number, the line
     * after its last, its treatises (number => from, to) and its pages.
     *
     * @return array{lines: list<array{page: int, text: string}>, entries: array<int, list<array>>}
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
            if (isset($lines[$i + 1]) && preg_match(self::SERIAL, Format::digits($line['text']))
                && preg_match(self::SHELF, Format::digits($lines[$i + 1]['text']), $s)) {
                $heads[] = [$i, (int) $s[1], (int) Format::digits($line['text'])];
            }
        }
        $entries = [];
        foreach ($heads as $k => [$from, $shelf, $serial]) {
            $end = $heads[$k + 1][0] ?? count($lines);
            $items = [];
            $open = null;
            for ($i = $from + 2; $i < $end; $i++) {
                if (preg_match(self::ITEM, Format::digits($lines[$i]['text']), $it) && ! isset($items[(int) $it[1]])) {
                    if ($open !== null) {
                        $items[$open]['to'] = $i;
                    }
                    $open = (int) $it[1];
                    $items[$open] = ['from' => $i, 'to' => $end];
                }
            }
            $entries[$shelf][] = [
                'line' => $from, 'end' => $end, 'items' => $items, 'serial' => $serial,
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
