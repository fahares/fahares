<?php

namespace App\Sources\Extractors;

use App\Console\Commands\MatchCatalogEditionCommand as Format;

/**
 * The Sepahsalar (Shahid Motahhari) catalogue in its second edition, as Ghaemiyeh's reader gives it (step 14.267): every
 * paragraph is headed by a title on a line of its own, and each of the five volumes keeps the form of the first
 * printing it was set from.
 *
 * - Vol. 3: entries numbered in brackets, «[5] آتشکده آذر تاریخ شعرا/ فارسی» (title, subject and «/ language» run
 *   together), and copies numbered in a series of their own, each on a line that begins with the series number and the
 *   shelfmark, «7 2726: شکسته نستعلیق …». A treatise of a composite volume names its place, «12931: ش 49 دفتر.». The
 *   reader often drops the space after the series number, «12931:»: the series runs on through the volume, so the
 *   number expected next splits them. The copy's line (with the lines that go on with it) is the copy text, the rest
 *   of the entry the entry text.
 * - Vols. 4 and 5: a work's paragraph, «جهانگشای نادری» then «(1026) جهانگشای نادری (تاریخ. فارسی) …», and a paragraph
 *   for each copy, headed by the title and its copy's number, «دیوان مختاری غزنوی (2)», whose text begins with the
 *   shelfmark, «374 نستعلیق …» or «3297: نسخ …». The copy's paragraph is the copy text and the work's paragraph of that
 *   title the entry text. A copy may also stand inside the work's paragraph, «… 2925: ش 1 دفتر، …».
 * - Vols. 1 and 2 (Ibn Yusuf): «[589] دیوان واله اصفهانی (فارسی) …» describes the work and «(892) مبانی اللغه …» each
 *   copy, which closes with «شماره کتابخانه (2726)»; the copy text runs from its «(892)».
 *
 * A heading's title is matched in each of its shorter forms, the trailing words (a subject) dropped one by one.
 */
class BracketedEntryCatalogueExtractor extends NumberedCatalogueExtractor
{
    /** «[5] آتشکده …»: a numbered heading (vol. 3) */
    private const BRACKETED = '/^\s*\[\s*(\d+)\s*\]\s*(.+?)\s*$/u';

    /** «7 2726:», «12931:», «8 228 صدر:»: the series number, the shelfmark (maybe run together), its word, the rest (vol. 3) */
    private const SERIAL = '/^\s*(\d+)(?:\s+(\d+))?((?:\s*(?:مکرر|صدر|مشیر))?)\s*:\s*(.*)$/u';

    /** A copy paragraph's heading, «دیوان مختاری غزنوی (2)» */
    private const COPY_HEADING = '/^(.+?)\s*[(«]\s*\d+\s*\)?\s*$/u';

    /** The shelfmark that opens a copy paragraph, «374 نستعلیق», «3297: نسخ», «228 صدر: …» */
    private const LEADING = '/^\s*(\d{1,5})((?:\s*(?:مکرر|صدر|مشیر))?)(?:\s*:\s*|\s+(?=\D))/u';

    /** A copy inside a work's paragraph, «2925: ش 1 دفتر» */
    private const INLINE = '/(?:^|[\s.،؛])(\d{1,5})\s*:\s*(?=ش\s*\d)/u';

    /** Ibn Yusuf's «شماره کتابخانه (2726)» (vol. 2) or «نمره کتابخانه (1005)» (vol. 1), not a footnote mark «(1) {1}» */
    private const LIBRARY_NUMBER = '/(?:شماره|نمره)\s*\x{200C}?\s*کتاب\s*\x{200C}?\s*خانه\s*\(\s*(\d+)\s*\)(?!\s*\{)/u';

    /** «ش 49 دفتر», «ش 84 16 ر», the treatise of a composite volume, right after the shelfmark */
    private const PART = '/^\s*ش\s*(\d+)/u';

    /**
     * A copy inside a paragraph (vol. 4): its shelfmark at the start of a line or after a sentence, followed by what
     * describes a copy, «… اجمعین. 5637 نسخ حسین سلماسی …», «6651 ش 1 دفتر …», «1403 نستعلیق. …»
     */
    private const DESCRIBED = '/(?:^|[.:)»]\s+)(\d{2,5})((?:\s*(?:مکرر|صدر|مشیر))?)\s*:?\s+(?=ش\s*\d|نسخ|نستعلیق|شکسته|ثلث|تعلیق|کوفی|ریحان|محقق|رقاع|سده\s|خط\s|بی\s*نشان|همان\s|بخش\s|از\s*آغاز|آغاز\s*(?:و|افتاده))/u';

    public function index(array $pages): array
    {
        // vol. 3 numbers its copies in a series, «7 2726:», and every «[N] …» there heads an entry
        $serial = preg_match_all('/^\s*\d+\s+\d+\s*:/mu', Format::digits(implode("\n", $pages))) >= 3;
        [$lines, $paragraphs] = self::paragraphs($pages, $serial);
        if ($serial) {
            // and runs from its heading to the next, over any blank line inside it
            $merged = [];
            foreach ($paragraphs as $p) {
                if ($merged && ! preg_match(self::BRACKETED, $p['heading'])) {
                    array_push($merged[array_key_last($merged)]['lines'], ...$p['lines']);
                } else {
                    $merged[] = $p;
                }
            }
            $paragraphs = $merged;
        } else {
            // a copy's heading «الجوهر النضید … (2)» over its shelfmark's line opens a paragraph even where the page
            // before ended with no blank line
            $split = [];
            foreach ($paragraphs as $p) {
                $cur = ['heading' => $p['heading'], 'lines' => []];
                foreach ($p['lines'] as $k => $ln) {
                    $text = Format::digits($lines[$ln]['text']);
                    $next = $p['lines'][$k + 1] ?? null;
                    if ($k > 0 && $next !== null && mb_strlen($text) < 120 && preg_match(self::COPY_HEADING, $text)
                        && preg_match(self::LEADING, Format::digits($lines[$next]['text']))) {
                        $split[] = $cur;
                        $cur = ['heading' => $text, 'lines' => []];
                    }
                    $cur['lines'][] = $ln;
                }
                $split[] = $cur;
            }
            // a copy's own paragraph begins with its shelfmark when a blank line parts it from its heading
            // «دعا (74)», «5725 جنگ دعا. …»: it goes on with the paragraph before
            $merged = [];
            foreach ($split as $p) {
                if ($merged && preg_match('/^\s*\d{2,5}(?:\s*(?:مکرر|صدر|مشیر))?\s*:?\s+[^\d\s\-–ـ]/u', $p['heading'])) {
                    array_push($merged[array_key_last($merged)]['lines'], ...$p['lines']);
                } else {
                    $merged[] = $p;
                }
            }
            $paragraphs = $merged;
        }
        $works = []; // a work paragraph's title => the paragraph
        foreach ($paragraphs as $i => $p) {
            if (! preg_match(self::COPY_HEADING, $p['heading'])) {
                $works[Format::norm($p['heading'])] ??= $i;
            }
        }
        $index = [];
        $last = 0; // the last series number read (vol. 3)
        foreach ($paragraphs as $i => $p) {
            $entry = self::entry($p, $lines);
            $places = $serial ? self::serialPlaces($p, $lines, $last) : self::places($p, $lines, $paragraphs, $works);
            foreach ($places as $place) {
                $index[$place['shelf']][] = ['page' => $lines[$place['line_no']]['page'], 'entry' => $entry, 'line' => $place['line'],
                    'line_no' => $place['line_no'], 'texts' => $place['texts']] + (isset($place['part']) ? ['part' => $place['part']] : []);
            }
        }

        return [$index, count($paragraphs), $lines];
    }

    public function texts(array $place, array $lines): array
    {
        return $place['texts'] ?? [null, null];
    }

    /**
     * The lines of the pages and their paragraphs: a paragraph begins after a blank line (or at a page that the page
     * before closed with one), and its first line is its heading. With $numbered, every «[N] …» line opens one too.
     *
     * @return array{0: list<array{page: int, text: string, page_start: bool, gap: bool}>, 1: list<array{heading: string, lines: list<int>}>}
     */
    public static function paragraphs(array $pages, bool $numbered = false): array
    {
        $lines = [];
        $paragraphs = [];
        $break = true;
        foreach ($pages as $page => $text) {
            $top = true;
            foreach (preg_split('/\R/u', $text) as $line) {
                if (trim($line) === '') {
                    $break = true;

                    continue;
                }
                $ln = count($lines);
                $lines[] = ['page' => $page, 'text' => trim($line), 'page_start' => $top, 'gap' => $break];
                // a numbered heading «[1] …» opens its entry even where the page before ends with no blank line
                if ($break || ! $paragraphs || ($numbered && preg_match(self::BRACKETED, Format::digits($line)))) {
                    $paragraphs[] = ['heading' => Format::digits(trim($line)), 'lines' => []];
                }
                $paragraphs[array_key_last($paragraphs)]['lines'][] = $ln;
                [$top, $break] = [false, false];
            }
            $break = (bool) preg_match('/\R[^\S\r\n]*\R\s*$/u', $text);
        }

        return [$lines, $paragraphs];
    }

    /** A paragraph as an entry of match-edition: its number, its title forms, its heading and pages. */
    private static function entry(array $p, array $lines): array
    {
        $heading = $p['heading'];
        $number = null;
        if (preg_match(self::BRACKETED, $heading, $m)) {
            [$number, $heading] = [(int) $m[1], $m[2]];
        } elseif (preg_match(self::COPY_HEADING, $heading, $m)) {
            $heading = $m[1];
        } elseif (isset($p['lines'][1]) && preg_match('/^\s*[\[(]\s*(\d+)\s*[\])]/u', Format::digits($lines[$p['lines'][1]]['text']), $m)) {
            $number = (int) $m[1]; // «(1026) جهانگشای نادری …», «[589] دیوان واله …»
        }
        $first = $p['lines'][0];
        $end = end($p['lines']);

        return ['number' => $number, 'title' => self::titles($heading), 'heading' => $lines[$first]['text'],
            'page_start' => $lines[$first]['page'], 'page_end' => $lines[$end]['page'], 'line' => $first, 'line_end' => $end + 1];
    }

    /** Vol. 3: the copy lines of an entry, with the lines that go on with each. */
    private static function serialPlaces(array $p, array $lines, int &$last): array
    {
        $owner = []; // line => its copy line
        $found = [];
        $copy = null;
        foreach (array_slice($p['lines'], 1) as $ln) {
            if (($shelf = self::copyLine(Format::digits($lines[$ln]['text']), $last)) !== null) {
                [$last, $number, $rest] = $shelf;
                $copy = $ln;
                $found[$ln] = ['shelf' => $number, 'line_no' => $ln, 'line' => $lines[$ln]['text']]
                    + (preg_match(self::PART, $rest, $m) ? ['part' => (int) $m[1]] : []);
            }
            if ($copy !== null) {
                $owner[$ln] = $copy;
            }
        }
        $body = array_values(array_filter($p['lines'], fn ($i) => ! isset($owner[$i])));
        $entryText = Format::joinLines($lines, $body);
        foreach ($found as $ln => &$place) {
            $place['texts'] = [$entryText, Format::joinLines($lines, array_keys($owner, $ln, true))];
        }

        return array_values($found);
    }

    /** Vols. 1, 2, 4 and 5: a copy paragraph's shelfmark, and the copies named inside a work's paragraph. */
    private static function places(array $p, array $lines, array $paragraphs, array $works): array
    {
        $found = [];
        $body = array_slice($p['lines'], 1);
        if (! $body) {
            return [];
        }
        $text = Format::joinLines($lines, $p['lines']);
        if (preg_match(self::COPY_HEADING, $p['heading'], $h) && preg_match(self::LEADING, Format::digits($lines[$body[0]]['text']), $m)) {
            $work = $works[Format::norm($h[1])] ?? null;
            $rest = mb_substr(Format::digits($lines[$body[0]]['text']), mb_strlen($m[0]));
            $found[] = ['shelf' => ltrim($m[1], '0'), 'line_no' => $body[0], 'line' => $lines[$body[0]]['text'],
                'texts' => [$work === null ? $lines[$p['lines'][0]]['text'] : Format::joinLines($lines, $paragraphs[$work]['lines']), $text]]
                + (preg_match(self::PART, $rest, $pm) ? ['part' => (int) $pm[1]] : []);
            $body = array_slice($body, 1); // the lines after it may name other copies
        }
        foreach ($body as $ln) {
            $line = Format::digits($lines[$ln]['text']);
            $own = $lines[$ln]['text'];
            if (preg_match_all(self::DESCRIBED, $line, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($all as $m) {
                    preg_match(self::PART, substr($line, $m[0][1] + strlen($m[0][0])), $pm);
                    $found[] = self::inline($m[1][0], $ln, $own, mb_strlen(substr($line, 0, $m[1][1])), $text, isset($pm[1]) ? (int) $pm[1] : null);
                }
            }
            if (preg_match_all(self::INLINE, $line, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($all as $m) {
                    preg_match(self::PART, substr($line, $m[0][1] + strlen($m[0][0])), $pm);
                    $found[] = self::inline($m[1][0], $ln, $own, mb_strlen(substr($line, 0, $m[1][1])), $text, isset($pm[1]) ? (int) $pm[1] : null);
                }
            }
            if (preg_match_all(self::LIBRARY_NUMBER, $line, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($all as $m) {
                    $end = mb_strlen(substr($line, 0, $m[0][1])) + mb_strlen($m[0][0]);
                    // the copy's description begins at its number «(892) …», the last one before its shelfmark
                    $before = mb_substr($line, 0, $end);
                    $from = preg_match_all('/\(\s*\d+\s*\)\s+(?=[^\d\s(])/u', $before, $starts, PREG_OFFSET_CAPTURE)
                        ? mb_strlen(substr($before, 0, end($starts[0])[1])) : 0;
                    $found[] = self::inline(ltrim($m[1][0], '0'), $ln, $own, $from, $text, null, $end);
                }
            }
        }

        // one place a shelfmark and line, the first reading of it
        $seen = [];

        return array_values(array_filter($found, function ($f) use (&$seen) {
            return ! isset($seen[$f['shelf'] . '@' . $f['line_no']]) && ($seen[$f['shelf'] . '@' . $f['line_no']] = true);
        }));
    }

    /** A copy inside a paragraph: its text from $from to $to in its line, the rest of the paragraph the entry's. */
    private static function inline(string $shelf, int $ln, string $line, int $from, string $paragraph, ?int $part, ?int $to = null): array
    {
        $copy = trim(mb_substr($line, $from, $to === null ? null : $to - $from));
        $entry = trim(str_replace($copy, ' ', $paragraph));

        return ['shelf' => ltrim($shelf, '0'), 'line_no' => $ln, 'line' => $line, 'texts' => [$entry, $copy]]
            + ($part !== null ? ['part' => $part] : []);
    }

    /**
     * The series number, the shelfmark and the rest of a copy's line (vol. 3), or null when the line is not one. Run
     * together, «12931», the series number is the one expected next (or one or two after it, a line lost).
     *
     * @return array{0: int, 1: string, 2: string}|null
     */
    public static function copyLine(string $line, int $serial): ?array
    {
        if (! preg_match(self::SERIAL, $line, $m)) {
            return null;
        }
        if ($m[2] !== '') {
            [$number, $shelf] = [(int) $m[1], $m[2]];
            // a number far from the last one is a number in the text, «1263: …», not a copy
            if ($serial && ($number <= $serial || $number > $serial + 20)) {
                return null;
            }
        } else {
            $shelf = null;
            foreach ([1, 2, 3] as $step) {
                $next = (string) ($serial + $step);
                if (str_starts_with($m[1], $next) && strlen($m[1]) > strlen($next)) {
                    [$number, $shelf] = [(int) $next, substr($m[1], strlen($next))];

                    break;
                }
            }
            if ($shelf === null || $shelf[0] === '0') {
                return null;
            }
        }

        return [$number, ltrim($shelf, '0'), trim($m[4])];
    }

    /** The heading's title in its forms, «آتشکده آذر تاریخ شعرا», «آتشکده آذر تاریخ», «آتشکده آذر», joined by «=». */
    public static function titles(string $heading): string
    {
        $title = trim(preg_replace('/\s*\/\s*\S+\s*$/u', '', $heading));
        $words = preg_split('/\s+/u', $title);
        $forms = [];
        for ($n = count($words); $n >= max(1, count($words) - 4); $n--) {
            $forms[] = implode(' ', array_slice($words, 0, $n));
        }

        return implode('=', array_unique($forms));
    }
}
