<?php

namespace App\Sources\Extractors;

use App\Console\Commands\MatchCatalogEditionCommand as Format;

/**
 * The catalogue of the Sheikh Ali Haydar al-Mo'ayyad library (Qom), three volumes, as the Ghaemiyeh reader gives its
 * printed pages (step 14.258). Every entry is one copy headed by its shelfmark, in two layouts:
 *
 * - vols 1 and 2 (Arabic): the title and subject first, then the number alone, «القانونچه [ الطب]» / «472»; a
 *   composite volume «591» / «مجموعة» and its treatises «1- جلاء القلوب (1ظ - 60ظ) [ الأخلاق ]», or unnumbered
 *   «رسالة في علم الحروف (104 ظ۔ 105 و) [العلوم الغريبة]»;
 * - vol 3 (Persian): the number and title on one line, «1449 زهر الرياض», «1027 مجموعه».
 *
 * The copy's description begins with its script, «نستعلیق، …», «النسخ، …», and closes with its leaves, size and lines,
 * «100 برگ، 16/5 × 10/5 سم، 15 سطر.», «165 ورقة، …». As in ShelfmarkHeadedCatalogueExtractor, the heading (with the
 * one treatise a shelfmark «591/3» names) is the entry text and the description the copy text; a copy stands on every
 * page from its heading's to the next heading's.
 */
class MoayyedCatalogueExtractor implements EntryTextExtractor
{
    /** The number alone, or with «مجموعه» after it, or (vol 3) with the title after it. */
    private const HEADING = '/^\(?\s*(\d{1,6})\s*\)?\s*(?:(مجموع[هة])\s*:?|([^\d\s\-ـ.،,(\/×].*))?\s*$/u';

    /** Not a title after a number: the leaves, size and lines that close a copy, «140 برگ، …», «68 ورقة …». */
    private const MEASURE = '/^(?:برگ|ورق|گ\b|سطر|صفحه|ص\b|سم)/u';

    /** A title line before its number (vols 1 and 2): it ends with the subject in brackets. */
    private const TITLE_BEFORE = '/\]\s*$/u';

    /** An author's line, «ل : ؟», «لـ:؟», «للشيخ …», «لأبي حامد …». */
    private const AUTHOR = '/^ل(?:\s*ـ?\s*:|ل|أ|ا|ـ|\S*\s)/u';

    /** A numbered treatise, «1- …», «2 - …», «1_…», and in vol 3's appendix «1: …». */
    private const ITEM = '/^\s*(\d{1,3})\s*[-ـ.:_]\s*\S/u';

    /** An unnumbered treatise: its title with its leaves in brackets and its subject, «… (107ظ_ 110 ظ) [العقائد]», the
     * leaves closed by a misprinted «]» at times, «(73ظ 81ظ] [العقائد]». */
    private const ITEM_FOLIOS = '/\(\s*\d+\s*[ظوپر].*[)\]]\s*\[[^\]]+\]\s*$/u';

    /** Where the copy's description begins: its script. */
    private const COPY = '/^\s*(?:ال)?(?:نستعلیق|نستعليق|نسخ|شکسته|شكسته|ثلث|تعلیق|تعليق|رقاع|ریحان|ريحان|کوفی|كوفي|مغربی|مغربي)\b/u';

    /** The line that closes a copy: its leaves. */
    private const CLOSE = '/^\s*\d+\s*(?:برگ|ورقة|ورقه|ورق)\b/u';

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
        $at = $entry['number_line'];
        if ($part !== null && ($item = $entry['items'][$part] ?? null)) {
            $first = min(array_column($entry['items'], 'from'));
            $head = $first > $entry['line'] ? range($entry['line'], $first - 1) : [];
            $own = range($item['from'], min($item['to'], $copy) - 1);
            // vol 1: the first treatise stands before the number, so a later one is shown with the number
            if ($head === [] && ! in_array($entry['number_line'], $own, true)) {
                $head = [$entry['number_line']];
            }
            $body = [...$head, ...$own];
            $at = $item['from'];
        } elseif ($part !== null && $entry['items']) {
            // a treatise the volume does not list under that number is not taken as the whole volume
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
     * The volume's lines and its entries by shelfmark: each with its first line (the title line before the number in
     * vols 1 and 2), its number's line, the line after its last, its treatises (number => from, to), the line its
     * copy description begins at, the line after the one that closes it, and its pages.
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
            $digits = Format::digits($line['text']);
            if (! preg_match(self::HEADING, $digits, $h) || (isset($h[3]) && preg_match(self::MEASURE, $h[3]))) {
                continue;
            }
            $n = (int) $h[1];
            // an appendix numbers its entries from 1 again (vol 3's «مستدرک», «1 شرح التصريف» on p. 521): a «1» with
            // a title or «مجموعه» after it starts a new run when a «2» heading follows
            $restart = $n === 1 && $last !== null && $last > 1 && (($h[2] ?? '') !== '' || ($h[3] ?? '') !== '')
                && self::followedBy($lines, $i, 2);
            // vol 3: a number with a title is a heading when it follows the last one, or with its subject on its line
            // or the next, «[فقه ]»; a line of the description that begins with a year, «1166 با مهر بیضوی …», is not
            if (! $restart && ($h[3] ?? '') !== '' && ($last === null || $n !== $last + 1) && ! str_contains($line['text'], ']') && ! str_starts_with($lines[$i + 1]['text'] ?? '', '[')) {
                continue;
            }
            if ($last !== null && ! $restart && ! ($n > $last && $n - $last <= self::STEP)) {
                continue;
            }
            $from = $i;
            // vols 1 and 2: the title stands on the line before the number alone
            $alone = ($h[2] ?? '') === '' && ($h[3] ?? '') === '';
            if ($alone && $i > 0 && ($heads === [] || end($heads)[1] < $i - 1)) {
                $before = $lines[$i - 1]['text'];
                if (preg_match(self::TITLE_BEFORE, $before) && ! str_starts_with($before, '[') && ! preg_match(self::CLOSE, Format::digits($before))) {
                    $from = $i - 1;
                } elseif ($i > 1 && ($heads === [] || end($heads)[1] < $i - 2) && preg_match(self::AUTHOR, $before)) {
                    // the author's line between the title and the number, «السرّ المنير [العلوم الغريبة]» / «ل : ؟» / «484»
                    $title = $lines[$i - 2]['text'];
                    if (preg_match(self::TITLE_BEFORE, $title) && ! str_starts_with($title, '[') && ! preg_match(self::CLOSE, Format::digits($title))) {
                        $from = $i - 2;
                    }
                }
            }
            $heads[] = [$from, $i, $n];
            $last = $n;
        }
        // a composite volume whose number the text lost (vol 1 p. 45: «مجموعة» heads the page, «6» is missing): one
        // «مجموعة» line alone in a gap of one number between two headings takes that number
        $filled = [];
        foreach ($heads as $k => $head) {
            $filled[] = $head;
            $next = $heads[$k + 1] ?? null;
            if (! $next || $next[2] !== $head[2] + 2) {
                continue;
            }
            $found = [];
            for ($i = $head[1] + 1; $i < $next[0]; $i++) {
                if (preg_match('/^مجموع[هة]\s*$/u', $lines[$i]['text']) && ! preg_match('/^\d+$/', Format::digits($lines[$i - 1]['text']))) {
                    $found[] = $i;
                }
            }
            if (count($found) === 1) {
                $filled[] = [$found[0], $found[0], $head[2] + 1];
            }
        }
        $heads = $filled;
        $entries = [];
        foreach ($heads as $k => [$from, $numberLine, $n]) {
            $end = $heads[$k + 1][0] ?? count($lines);
            $items = [];
            $copy = null;
            $open = null;
            $numbered = false;
            // vol 1: the line before the number is the first treatise of a composite volume, with its leaves
            if ($from < $numberLine && preg_match(self::ITEM_FOLIOS, Format::digits($lines[$from]['text']))) {
                $open = 1;
                $items[1] = ['from' => $from, 'to' => $end];
            }
            for ($i = $numberLine + 1; $i < $end; $i++) {
                $digits = Format::digits($lines[$i]['text']);
                $it = null;
                if (preg_match(self::ITEM, $digits, $mm) && ! isset($items[(int) $mm[1]])) {
                    $it = (int) $mm[1];
                    $numbered = true;
                } elseif (! $numbered && preg_match(self::ITEM_FOLIOS, $digits)) {
                    $it = count($items) + 1;
                }
                if ($it !== null) {
                    if ($open !== null) {
                        $items[$open]['to'] = $i;
                    }
                    $open = $it;
                    $items[$open] = ['from' => $i, 'to' => $end];
                    $copy = null;
                } elseif ($copy === null && preg_match(self::COPY, $digits)) {
                    $copy = $i;
                }
            }
            // a lone treatise line is not a composite volume
            if (count($items) === 1) {
                $items = [];
            }
            if ($open !== null && $items && $copy !== null && $copy > $items[$open]['from']) {
                $items[$open]['to'] = $copy;
            }
            // the leaves, size and lines close the copy: a stray line after them is not its own
            $closed = $end;
            for ($i = $end - 1; $copy !== null && $i >= $copy; $i--) {
                if (preg_match(self::CLOSE, Format::digits($lines[$i]['text']))) {
                    $closed = $i + 1;

                    break;
                }
            }
            $entries[$n][] = [
                'line' => $from, 'number_line' => $numberLine, 'end' => $end, 'closed' => $closed, 'items' => $items, 'copy' => $copy,
                'page_start' => $lines[$from]['page'],
                'page_last' => $lines[$end - 1]['page'],
            ];
        }

        return ['lines' => $lines, 'entries' => $entries];
    }

    /** Whether a heading with this number, its title and subject after it, comes within the next 80 lines. */
    private static function followedBy(array $lines, int $i, int $n): bool
    {
        for ($j = $i + 1; $j < min(count($lines), $i + 80); $j++) {
            if (preg_match('/^' . $n . '\s*(?:مجموع[هة]|[^\d\s\-ـ.،,(\/×:])/u', Format::digits($lines[$j]['text']))
                && (str_contains($lines[$j]['text'], ']') || str_starts_with($lines[$j + 1]['text'] ?? '', '[') || preg_match('/مجموع[هة]/u', $lines[$j]['text']))) {
                return true;
            }
        }

        return false;
    }

    /** The lines, a paragraph each. */
    private static function join(array $lines, array $ids): ?string
    {
        $text = implode("\n", array_map(fn ($i) => $lines[$i]['text'], $ids));

        return $text === '' ? null : $text;
    }
}
