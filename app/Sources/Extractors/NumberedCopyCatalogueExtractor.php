<?php

namespace App\Sources\Extractors;

use App\Console\Commands\MatchCatalogEditionCommand as Format;

/**
 * Catalogues whose entries are unnumbered and whose copies are numbered (the Golpaygani catalogue, 1388 printing, step
 * 14.206), one paragraph a line: a heading «آئینه حق نما (اخلاق- فارسی)», the author, a description and a reference
 * list «[…]», then each copy as «۱۲۱۸) نسخه شماره: ۹۷۰-۶/۶۰» with its «آغاز:», «انجام:» and a paragraph that describes
 * it and closes with its leaves and size («… ۲۱۷ برگ، ۱۵ سطر ، ۱۴/۵×۲۱سم»). A composite volume is headed «مجموعه» or
 * «مجموعه (گوناگون- فارسی)» and lists its treatises «۱- …» before its copy line.
 *
 * A copy is found by its shelfmark's numbers in any order: the catalogue prints «۹۷۰-۶/۶۰» (970-6/60), and Fankha
 * writes the same copy «970-6/60», «6/60-970» or, for the ninth treatise of a composite volume, «8100/9-55/70». A copy
 * stands on every page from its heading's to its last line's, since Fankha cites the page its description runs onto as
 * well. When no copy has the very numbers there, the one copy on the page whose shelfmark differs in one number (a
 * misprint in either book) is taken, unless the shelfmark was corrected by hand (strict_part) or the numbers stand
 * exactly a few pages away.
 */
class NumberedCopyCatalogueExtractor implements EntryTextExtractor
{
    /** «۱۲۱۸) نسخه شماره: ۹۷۰-۶/۶۰», and the three copies printed without their number */
    private const COPY = '/^\s*(?:(\d+)\s*\)\s*)?نسخه\s*شماره\s*:?\s*(.*)$/u';

    /** A heading: «عنوان (موضوع- زبان)», with any dash, or a composite volume's «مجموعه». */
    private const HEADING = '/^\s*(?:[^\[\]:←]{1,200}?\(\s*[^()]{0,60}?[-–ـ_]\s*[^()]{0,40}\)|مجموعه(?:\s*\([^()]*\))?)\s*$/u';

    /** The close of a copy's description: its leaves, «۲۱۷ برگ», or its size, «۱۴/۵×۲۱سم». */
    private const PHYSICAL = '/\d\s*برگ|×|\dسم\s*$/u';

    private const SEE = '/←/u';

    /** A treatise of a composite volume, «۹- …», or a part of a work, «۹. …». */
    private const ITEM = '/^\s*(\d+)\s*[-–ـ.]\s*\S/u';

    /** How far an exact shelfmark keeps a one-number difference from being taken for it. */
    private const NEAR_PAGES = 5;

    /** @var array<string, array> the reading of each volume's pages */
    private static array $read = [];

    public function extract(array $pages, string $shelfmark, int $page, array $hints = []): ?array
    {
        $numbers = self::numbers($shelfmark);
        if (! $pages || ! $numbers) {
            return null;
        }
        $key = md5(implode(',', array_keys($pages)) . '|' . array_sum(array_map('strlen', $pages)));
        $read = self::$read[$key] ??= self::read($pages);
        $strict = ! empty($hints['strict_part']);

        $at = fn (array $copy) => $read['entries'][$copy['entry']]['page_start'] === $page || ($copy['page'] <= $page && $copy['last_page'] >= $page);
        $here = array_values(array_filter($read['index'][implode('-', $numbers)] ?? [], fn ($c) => $at($read['copies'][$c])));
        $part = null;
        if (! $here) {
            [$here, $part] = self::near($read, $numbers, $page, $at, $strict);
        }
        if (! $here) {
            return null;
        }
        // as it was found before: its copy number, then the page of its copy line
        $number = $hints['entry_number'] ?? null;
        $shelfPage = $hints['shelfmark_page'] ?? null;
        usort($here, fn ($a, $b) => [
            $number !== null && $read['copies'][$a]['number'] !== $number, $shelfPage !== null && $read['copies'][$a]['page'] !== $shelfPage, $a,
        ] <=> [
            $number !== null && $read['copies'][$b]['number'] !== $number, $shelfPage !== null && $read['copies'][$b]['page'] !== $shelfPage, $b,
        ]);
        $copy = $read['copies'][$here[0]];
        $entry = $read['entries'][$copy['entry']];
        $lines = $read['lines'];
        $body = range($entry['line'], $entry['end'] - 1);
        if ($part !== null) {
            $body = self::item($lines, $body, $part) ?? $body;
        }
        $last = max($copy['last_page'], $entry['page_start']);

        return [
            'entry_text' => self::join($lines, $body),
            'copy_text' => self::join($lines, $copy['lines']),
            'page_start' => $entry['page_start'],
            'page_end' => $last > $entry['page_start'] ? $last : null,
            'shelfmark_page' => $copy['page'],
            'entry_number' => $copy['number'],
        ];
    }

    /**
     * The copies on the page whose shelfmark is one number off, when none has the very numbers: a misprint (one
     * number differs; the only such copy on the page), or a treatise of a composite volume or a part of a work that
     * lists its parts (the catalogue's numbers and one more, the part's), which gives that part when it heads a line.
     *
     * @return array{0: list<int>, 1: ?int}
     */
    private static function near(array $read, array $numbers, int $page, \Closure $at, bool $strict): array
    {
        $parts = [];
        $typos = [];
        foreach ($read['pages'][$page] ?? [] as $c) {
            $copy = $read['copies'][$c];
            if (! $at($copy)) {
                continue;
            }
            $extra = self::without($numbers, $copy['numbers']);
            if ($extra !== null && count($extra) === 1 && self::lists($read, $copy['entry'], $extra[0])) {
                $parts[] = [$c, $extra[0]];
            } elseif (! $strict && count($copy['numbers']) === count($numbers) && count(self::without($numbers, $copy['numbers'], true)) === 1) {
                $typos[] = $c;
            }
        }
        if (count($parts) === 1) {
            return [[$parts[0][0]], $parts[0][1]];
        }
        if (count($typos) !== 1 || $parts) {
            return [[], null];
        }
        // the very numbers a few pages away are that copy, not a misprint of this one
        foreach ($read['index'][implode('-', $numbers)] ?? [] as $c) {
            if (abs($read['copies'][$c]['page'] - $page) <= self::NEAR_PAGES) {
                return [[], null];
            }
        }

        return [$typos, null];
    }

    /**
     * The numbers of $a left when those of $b are taken out, or null when $b has one $a lacks; with $loose, the
     * numbers of $a that $b lacks, whatever $b has besides.
     *
     * @return list<int>|null
     */
    private static function without(array $a, array $b, bool $loose = false): ?array
    {
        foreach ($b as $n) {
            $i = array_search($n, $a, true);
            if ($i === false) {
                if (! $loose) {
                    return null;
                }

                continue;
            }
            unset($a[$i]);
        }

        return array_values($a);
    }

    /**
     * Whether an entry has parts that a shelfmark may name by number: a composite volume, or a work that lists its
     * parts, «۱. نزهت الارواح … ۱۴. غزلیات» (a جنگ) or «۱- سلسلة الذهب ۲- سلامان وابسال» (Jami's seven mathnawis).
     */
    private static function lists(array $read, int $entry, int $part): bool
    {
        $entry = $read['entries'][$entry];
        if ($entry['composite']) {
            return true;
        }
        foreach (range($entry['line'], $entry['end'] - 1) as $i) {
            if (preg_match('/(?:^|\s)' . $part . '\s*[-–ـ.]\s*[^\d\s]/u', $read['lines'][$i]['digits'])) {
                return true;
            }
        }

        return false;
    }

    /** The lines of a composite volume's heading and of its treatise $part, or null when it lists no such treatise. */
    private static function item(array $lines, array $body, int $part): ?array
    {
        $from = null;
        $to = null;
        foreach ($body as $i) {
            if (! preg_match(self::ITEM, $lines[$i]['digits'], $m)) {
                continue;
            }
            if ($from !== null) {
                $to = $i;

                break;
            }
            if ((int) $m[1] === $part) {
                $from = $i;
            }
        }
        if ($from === null) {
            return null;
        }

        return [$body[0], ...range($from, ($to ?? end($body) + 1) - 1)];
    }

    /** A shelfmark's numbers, smallest first: «۹۷۰-۶/۶۰» and «6/60-970» are both 6, 60, 970. */
    public static function numbers(string $shelfmark): array
    {
        preg_match_all('/\d+/', Format::digits($shelfmark), $m);
        $numbers = array_map('intval', $m[0]);
        sort($numbers);

        return $numbers;
    }

    /**
     * The pages read into entries and copies. Every line is an entry's until a copy line; a copy goes on through its
     * «آغاز» and «انجام» (with their lines of verse) to the paragraph that gives its leaves or size, and on to the next
     * page when that paragraph runs over. What follows is the next copy of the same entry, or the heading of the next
     * entry; a cross reference «مونس العابدین ← ترجمه نیک بختیه» before a heading is no entry.
     *
     * @return array{lines: list<array>, entries: list<array>, copies: list<array>, index: array<string, list<int>>, pages: array<int, list<int>>}
     */
    public static function read(array $pages): array
    {
        $lines = [];
        foreach ($pages as $page => $text) {
            $top = true;
            foreach (preg_split('/\R/u', $text) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $lines[] = ['page' => (int) $page, 'text' => trim($line), 'digits' => trim(Format::digits($line)), 'top' => $top];
                $top = false;
            }
        }

        $entries = [];
        $copies = [];
        $pending = []; // the lines since the last copy: the next entry's, when a copy line follows them
        $copy = null;
        $entry = null;
        $close = function () use (&$copy, &$copies, &$entries, &$entry, $lines) {
            if ($copy === null) {
                return;
            }
            $copy['last_page'] = $lines[end($copy['lines'])]['page'];
            $copies[] = $copy;
            $copy = null;
        };
        foreach ($lines as $i => $line) {
            $d = $line['digits'];
            if (preg_match(self::COPY, $d, $m)) {
                $close();
                // the cross references before a heading are not part of its entry, nor the end of the reference list
                // before it that the text layer put after the next page's first copy, «۱۳/۱۸ فهرست … ص ۴۶۱]»
                while ($pending && (preg_match(self::SEE, $lines[$pending[0]]['digits']) && ! preg_match(self::HEADING, $lines[$pending[0]]['digits'])
                    || preg_match('/^[^\[]*\]\s*$/u', $lines[$pending[0]]['digits']))) {
                    array_shift($pending);
                }
                if ($pending) {
                    $entries[] = ['line' => $pending[0], 'end' => $i, 'page_start' => $lines[$pending[0]]['page'], 'title' => $lines[$pending[0]]['text'],
                        'composite' => (bool) preg_match('/^\s*مجموعه(?:\s*\(|\s*$)/u', $lines[$pending[0]]['digits'])];
                    $entry = array_key_last($entries);
                    $pending = [];
                }
                if ($entry === null) {
                    continue; // a copy before the first heading of the pages has no entry to read
                }
                $copy = ['line' => $i, 'lines' => [$i], 'closed' => false, 'number' => $m[1] !== '' ? (int) $m[1] : null,
                    'shelfmark' => trim($m[2]), 'numbers' => self::numbers($m[2]), 'entry' => $entry, 'page' => $line['page']];

                continue;
            }
            if ($copy !== null && ! $copy['closed']) {
                if (count($copy['lines']) > 1 && (preg_match(self::HEADING, $d) || preg_match(self::SEE, $d) && mb_strlen($d) < 120)) {
                    $close();
                    $pending = [$i];

                    continue;
                }
                $copy['lines'][] = $i;
                if (! preg_match('/^\s*(?:اغاز|انجام)[^:،]{0,25}:/u', $d) && preg_match(self::PHYSICAL, $d)) {
                    $copy['closed'] = true;
                }

                continue;
            }
            // a description broken after its leaves, «… ۱۸برگ،» and «۱۷- ۱۸ سطر ، ۱۵/۵×۲۱سم» on the next line or page
            if ($copy !== null && mb_strlen($d) < 40 && preg_match('/[،,×]\s*$/u', $lines[end($copy['lines'])]['digits'])
                && preg_match('/سطر|سم\s*$/u', $d) && ! preg_match(self::HEADING, $d)) {
                $copy['lines'][] = $i;

                continue;
            }
            $close();
            $pending[] = $i;
        }
        $close();

        $index = [];
        $byPage = [];
        foreach ($copies as $c => $copy) {
            $index[implode('-', $copy['numbers'])][] = $c;
            foreach (range(min($entries[$copy['entry']]['page_start'], $copy['page']), $copy['last_page']) as $p) {
                $byPage[$p][] = $c;
            }
        }

        return ['lines' => $lines, 'entries' => $entries, 'copies' => $copies, 'index' => $index, 'pages' => $byPage];
    }

    /** Lines joined: a paragraph the page broke goes on after a space, every other line is a line of its own. */
    private static function join(array $lines, array $ids): ?string
    {
        $out = null;
        $prev = null;
        foreach ($ids as $i) {
            $text = $lines[$i]['text'];
            if ($out === null) {
                $out = $text;
            } elseif ($i === $prev + 1 && $lines[$i]['top'] && self::continues($lines[$prev]['digits'], $lines[$i]['digits'])) {
                $out .= ' ' . $text;
            } else {
                $out .= "\n" . $text;
            }
            $prev = $i;
        }

        return $out === null ? null : mb_substr($out, 0, 20000);
    }

    /** Whether a page's first line goes on with the paragraph the last line of the page before left open. */
    private static function continues(string $before, string $after): bool
    {
        return ! preg_match('/[.!؟?:»\])…]\s*$|سم\s*$/u', $before)
            && ! preg_match(self::HEADING, $before)
            && ! preg_match('/^\s*(?:اغاز|انجام|\[|\d+\s*\)|\d+\s*[-–ـ]\s|مول?ؤ?ف\s*:)/u', $after)
            && ! preg_match(self::HEADING, $after)
            // the paragraph that describes a copy, after its incipit or explicit, is a paragraph of its own
            && ! (preg_match('/^\s*(?:اغاز|انجام)/u', $before) && preg_match(self::PHYSICAL, $after));
    }
}
