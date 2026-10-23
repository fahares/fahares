<?php

namespace App\Console\Commands;

use App\Models\Manuscript;
use App\Models\SourceEntry as Entry;
use App\Models\SourceVolume;
use App\Sources\SourceEntrySync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Finds the copies of a library in the text of another printing of its catalogue (backlog item 51) and writes
 * them as source entries of origin match, unverified, for the review queue (step 13.4; before it,
 * manuscript_catalog_references rows of source ai_match).
 *
 * The page texts (one {"page": printed page, "text": …} per page, e.g. from Ghaemiyeh's reader) are read entry by
 * entry: «607- دیوان قدسی (فارسی)» opens an entry, and every «ش 1939» inside it is a copy of that work. Each copy
 * of the library is looked up by the base number of its shelfmark and confirmed by comparing its work's title
 * with the entry title (and its «=» alternates):
 *
 *  - shelfmark_title: the best title is similar (≥ 0.6) and no other page ties with it; confidence = similarity;
 *  - shelfmark_single_page: every place the number appears is on one page and the title is close (≥ 0.35);
 *  - shelfmark_only: the number appears in one entry only but the titles differ (often «جنگ», «بیاض»), confidence 25;
 *  - ambiguous: several places and none clearly better; the best is written with confidence 10, the rest in notes.
 *
 * Volumes 3 and 4 of the Masjid-e A'zam catalogue describe composite volumes instead: «( شماره 5 )» or «( 17 )» heads
 * a volume, and its treatises follow as «1 - حبل المتین «برگ 1 - 143» (فقه - عربی)», «2 ـ …». Every treatise is a
 * place of the heading's number with its part, so the copy «5/1» is compared with the first treatise only.
 *
 * A copy may stand by number in two volumes of the printing; both are kept when both are confirmed by title (vol 1
 * names the treatises of a composite volume that vol 3 or 4 describes). A weak match (shelfmark_only, ambiguous, or
 * shelfmark_single_page under 0.5) gives way to a confirmed one, or a verified one, in another volume of the same
 * facsimile_code: it is not written, and an unverified weak row already there is deleted, whichever volume runs first.
 *
 * page_start is the page where the entry begins (the viewer opens it) and shelfmark_page where the number stands.
 * Running it again updates the unverified matches and leaves the reviewed ones (verified or rejected), and the copies
 * whose address was corrected by hand (source manual), alone.
 *
 * Each match also keeps the printed text (backlog item 60, texts()): entry_text, the entry or treatise, and copy_text,
 * the copy's own line or the composite volume's closing notes. --texts fills them for every reference of the edition,
 * reviewed ones too, at the address it has, and changes nothing else.
 */
class MatchCatalogEditionCommand extends Command
{
    protected $signature = 'catalogs:match-edition
        {edition : facsimile_code of the edition (catalog_volumes row)}
        {pages : Directory of page JSON files, or a .jsonl / .jsonl.gz file, with "page" and "text"}
        {--volume= : Volume number of the edition, when the code has several}
        {--texts : Only fill entry_text and copy_text of every reference of the edition, at the address it has}
        {--dry-run : Report the counts without saving anything}
        {--report= : Write every match as JSON to this path}';

    protected $description = 'Match a library\'s copies to the pages of another printing of its catalogue by shelfmark and title';

    public const METHOD_TITLE = 'shelfmark_title';

    public const METHOD_SINGLE_PAGE = 'shelfmark_single_page';

    public const METHOD_SHELFMARK_ONLY = 'shelfmark_only';

    public const METHOD_AMBIGUOUS = 'ambiguous';

    /** A match confirmed by its title: shelfmark_title, or shelfmark_single_page from a similarity of 0.5 up. */
    public static function confirmed(string $method, int $confidence): bool
    {
        return $method === self::METHOD_TITLE || ($method === self::METHOD_SINGLE_PAGE && $confidence >= 50);
    }

    private const ENTRY = '/^\s*(\d+)\s*[-–ـ]\s*(.+?)\s*(?:\(([^)]*)\))?\s*$/u';

    /** «( شماره 5 )», «( 3619 )», «( 652 شامل ده رساله )»: the shelfmark heading a composite volume */
    private const VOLUME = '/^\s*\(\s*(?:شماره\s*)?(\d+)(?:\s+[^)\d]{1,30})?\s*\)\s*$/u';

    /** «فهرست نام کتاب‌ها», the index of titles after the last entry */
    private const BACK_MATTER = '/^\s*فهرست\s+نام\s+کتاب[\s\x{200C}]*ها\s*$/u';

    private const SHELF = '/(?:^|[\s،(])ش\s*(\d+)(?:\s*\/\s*(\d+))?/u';

    public function handle(): int
    {
        $editions = SourceVolume::with('source')->where('facsimile_code', $this->argument('edition'))
            ->when($this->option('volume'), fn ($q, $v) => $q->where('volume_number', $v))->get();
        if ($editions->count() !== 1) {
            $this->error("Expected one edition {$this->argument('edition')}, found {$editions->count()} (use --volume)");

            return self::FAILURE;
        }
        $edition = $editions[0];
        $pages = self::readPages($this->argument('pages'));
        if (! $pages) {
            $this->error('No page texts found in ' . $this->argument('pages'));

            return self::FAILURE;
        }

        [$index, $entries, $lines] = self::index($pages);
        $this->line(sprintf('%s v%d: %d pages, %d entries, %d shelfmarks indexed', $edition->facsimile_code, $edition->volume_number, count($pages), $entries, count($index)));
        if ($this->option('texts')) {
            $stats = $this->fillTexts($edition, $index, $lines, (bool) $this->option('dry-run'));
            $this->table(['', 'count'], collect($stats)->map(fn ($n, $k) => [$k, number_format($n)])->values()->all());
            $this->option('dry-run') && $this->warn('Dry run: nothing is saved.');

            return self::SUCCESS;
        }

        $copies = DB::table('manuscripts')->leftJoin('works', 'works.id', '=', 'manuscripts.work_id')
            ->where('manuscripts.library_id', $edition->source->library_id)
            ->select('manuscripts.id', 'manuscripts.shelfmark', 'works.primary_title')->orderBy('manuscripts.id')->get();

        $stats = ['copies' => $copies->count(), 'no shelfmark in edition' => 0] + array_fill_keys([self::METHOD_TITLE, self::METHOD_SINGLE_PAGE, self::METHOD_SHELFMARK_ONLY, self::METHOD_AMBIGUOUS], 0);
        $matches = [];
        foreach ($copies as $copy) {
            $match = self::match($copy, $index, $lines);
            if (! $match) {
                $stats['no shelfmark in edition']++;

                continue;
            }
            $stats[$match['match_method']]++;
            $matches[] = $match;
        }

        $write = [];
        if ($matches) {
            $write = $this->write($edition, $matches, (bool) $this->option('dry-run'));
        }

        $this->table(['', 'count'], collect($stats + $write)->map(fn ($n, $k) => [$k, number_format($n)])->values()->all());
        if ($this->option('dry-run')) {
            $this->warn('Dry run: nothing is saved.');
        }
        if ($path = $this->option('report')) {
            file_put_contents($path, json_encode($matches, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            $this->line("Report written to {$path}");
        }

        return self::SUCCESS;
    }

    /** @return array<int, string> printed page => text */
    public static function readPages(string $path): array
    {
        $rows = [];
        if (is_dir($path)) {
            foreach (glob(rtrim($path, '/') . '/*.json') as $file) {
                $rows[] = json_decode(file_get_contents($file), true);
            }
        } elseif (is_file($path)) {
            $content = str_ends_with($path, '.gz') ? gzdecode(file_get_contents($path)) : file_get_contents($path);
            foreach (explode("\n", $content) as $line) {
                if (trim($line) !== '') {
                    $rows[] = json_decode($line, true);
                }
            }
        }
        $pages = [];
        foreach ($rows as $row) {
            if (isset($row['page'], $row['text'])) {
                $pages[(int) $row['page']] = (string) $row['text'];
            }
        }
        ksort($pages);

        return $pages;
    }

    /**
     * Every shelfmark base number with the places it stands: the page, the line, and the entry it belongs to (number,
     * title, first and last page, first line and the line after its last). The lines are the page texts as printed,
     * without blank lines, for texts().
     *
     * @return array{0: array<string, list<array>>, 1: int, 2: list<array{page: int, text: string, page_start: bool, gap: bool}>}
     */
    public static function index(array $pages): array
    {
        $index = [];
        $entries = [];
        $lines = [];
        $volumes = []; // the line of a composite volume's heading => its first and last treatise
        $current = null;
        $volume = null; // the composite volume being read: [number, heading, last part, in a contents list, line]
        $break = true; // a blank line before the next line: a new paragraph
        foreach ($pages as $page => $text) {
            $first = true;
            $top = true;
            $printed = preg_split('/\R/u', $text);
            foreach (preg_split('/\R/u', self::digits($text)) as $i => $line) {
                if (trim($line) === '') {
                    $break = true;

                    continue;
                }
                $ln = count($lines);
                $lines[] = ['page' => $page, 'text' => trim($printed[$i]), 'page_start' => $top, 'gap' => $break];
                [$top, $break] = [false, false];
                if ($current !== null && preg_match(self::BACK_MATTER, $line)) {
                    $entries[$current]['line_end'] ??= $ln; // the last entry's text stops at the index of titles
                }
                if (preg_match(self::VOLUME, $line, $m)) {
                    $volume = [$m[1], trim($line), 0, false, $ln]; // number, heading, last part, in a contents list, line

                    continue;
                }
                $entry = preg_match(self::ENTRY, $line, $m) && mb_strlen($m[2]) < 120 && ! str_starts_with(ltrim($line), 'ش');
                $part = null;
                if ($entry && $volume) {
                    // a treatise follows the last part (a heading or two may be lost) and carries its leaves «1 ـ 38» or
                    // its «(subject ـ language)»; a bare heading only as the very next part, and not once a contents
                    // list has begun inside the treatise («1 ـ مقدمه…» after part 1)
                    $n = (int) $m[1];
                    $marked = preg_match('/«[^»]*\d[^»]*»|\(\s*\d+\s*[-–ـ]\s*\d+\s*\)|\)\s*$/u', $line);
                    if ($volume[2] === 0 ? $n === 1 : ($marked ? $n > $volume[2] && $n <= $volume[2] + 3 : $n === $volume[2] + 1 && ! $volume[3])) {
                        $part = $n;
                    } elseif ($volume[2] === 0) {
                        $volume = null; // not a composite volume after all, e.g. a stray «(1)» line
                    } else {
                        $volume[3] = $volume[3] || $n <= $volume[2];
                        $entry = false;
                    }
                }
                // «2 ـ …» heads a treatise only inside a composite volume; elsewhere it is a numbered list in an entry
                if ($entry && ($part !== null || ! preg_match('/^\s*\d+\s*ـ/u', $line))) {
                    if ($current !== null) {
                        // the previous entry ends here, or on the page before when this one opens the page
                        $entries[$current]['page_end'] = $first ? max($entries[$current]['page_start'], $page - 1) : $page;
                        // the text of the previous entry stops at the heading of the composite volume its first treatise opens
                        $entries[$current]['line_end'] ??= $part === 1 ? $volume[4] : $ln;
                    }
                    $entries[] = ['number' => (int) $m[1], 'title' => self::entryTitle($m[2]), 'heading' => trim($line), 'page_start' => $page, 'page_end' => $page, 'line' => $ln, 'line_end' => null];
                    $current = array_key_last($entries);
                    $first = false;
                    if ($part !== null) {
                        [$volume[2], $volume[3]] = [$part, false];
                        $volumes[$volume[4]]['first'] ??= $current;
                        $volumes[$volume[4]]['last'] = $current;
                        $index[$volume[0]][] = ['page' => $page, 'entry' => $current, 'line' => $volume[1], 'part' => $part, 'line_no' => $volume[4]];
                    }

                    continue;
                }
                $first = false;
                if (preg_match_all(self::SHELF, $line, $all, PREG_SET_ORDER)) {
                    foreach ($all as $s) {
                        $index[$s[1]][] = ['page' => $page, 'entry' => $current, 'line' => trim($line), 'line_no' => $ln];
                    }
                }
            }
            if ($current !== null) {
                $entries[$current]['page_end'] = max($entries[$current]['page_end'], $page);
            }
            // a page that ends in an empty line ends its paragraph; otherwise the next page may go on with it
            $break = (bool) preg_match('/\R[^\S\r\n]*\R\s*$/u', $text);
        }
        if ($current !== null) {
            $entries[$current]['line_end'] ??= count($lines);
        }
        // places resolved to their entry once every entry has its last page; a treatise also to its volume's lines
        foreach ($index as &$places) {
            foreach ($places as &$place) {
                if (isset($place['part'])) {
                    $volume = $volumes[$place['line_no']];
                    $place['volume'] = ['line' => $place['line_no'], 'first' => $entries[$volume['first']]['line'], 'last' => $entries[$volume['last']]['line'], 'end' => $entries[$volume['last']]['line_end']];
                }
                $place['entry'] = $place['entry'] === null ? null : $entries[$place['entry']];
            }
        }

        return [$index, count($entries), $lines];
    }

    /** The match of one copy ({id, shelfmark, primary_title}), or null when its number is not in the edition. */
    public static function match(object $copy, array $index, array $lines = []): ?array
    {
        if (! preg_match('/(\d+)(?:\s*\/\s*(\d+))?/', self::digits((string) $copy->shelfmark), $base) || empty($index[$base[1]])) {
            return null;
        }
        $title = self::norm((string) $copy->primary_title);
        $note = null;
        $all = [];
        foreach ($index[$base[1]] as $place) {
            $parts = explode('=', $place['entry']['title'] ?? '');
            $all[] = ['score' => max(array_map(fn ($part) => self::similarity($title, self::norm($part)), $parts)), 'place' => $place];
        }
        usort($all, fn ($a, $b) => $b['score'] <=> $a['score']);
        // «5/1» is the first treatise of volume 5: the other treatises of that volume are not its places, unless the
        // same part does not fit and one other treatise clearly does (the two catalogues numbered the parts apart)
        $part = isset($base[2]) ? (int) $base[2] : null;
        $scored = array_values(array_filter($all, fn ($s) => $part === null || ! isset($s['place']['part']) || $s['place']['part'] === $part));
        $shifted = array_values(array_filter($all, fn ($s) => $part !== null && isset($s['place']['part']) && $s['place']['part'] !== $part));
        if ($shifted && $shifted[0]['score'] >= 0.8 && ($scored[0]['score'] ?? 0) < 0.5
            && ! (isset($shifted[1]) && $shifted[1]['score'] === $shifted[0]['score'])) {
            $scored = [$shifted[0]];
            $note = sprintf('ردیف %d این مجموعه در فهرست، نه %d', $shifted[0]['place']['part'], $part);
        }
        if (! $scored) {
            return null;
        }
        $best = $scored[0];
        $ties = array_filter($scored, fn ($s) => $s['score'] === $best['score'] && $s['place']['page'] !== $best['place']['page']);
        $pages = array_unique(array_map(fn ($s) => $s['place']['page'], $scored));
        $entryKeys = array_unique(array_map(fn ($s) => ($s['place']['entry']['number'] ?? '') . '@' . ($s['place']['entry']['page_start'] ?? ''), $scored));

        if ($best['score'] >= 0.6 && ! $ties) {
            [$method, $confidence] = [self::METHOD_TITLE, (int) round($best['score'] * 100)];
        } elseif (count($pages) === 1 && $best['score'] >= 0.35) {
            [$method, $confidence] = [self::METHOD_SINGLE_PAGE, (int) round($best['score'] * 100)];
        } elseif (count($entryKeys) === 1) {
            [$method, $confidence] = [self::METHOD_SHELFMARK_ONLY, 25];
        } else {
            [$method, $confidence] = [self::METHOD_AMBIGUOUS, 10];
        }

        $place = $best['place'];
        $entry = $place['entry'];
        [$entryText, $copyText] = self::texts($place, $lines);
        $others = $method === self::METHOD_AMBIGUOUS
            ? collect($scored)->skip(1)->map(fn ($s) => sprintf('ص %d، مدخل %s (%.2f)', $s['place']['page'], $s['place']['entry']['number'] ?? '؟', $s['score']))->unique()->implode('؛ ')
            : null;

        return [
            'manuscript_id' => $copy->id,
            'shelfmark' => $copy->shelfmark,
            'title' => $copy->primary_title,
            'page_start' => $entry['page_start'] ?? $place['page'],
            'page_end' => $entry['page_end'] ?? $place['page'],
            'shelfmark_page' => $place['page'],
            'entry_number' => $entry['number'] ?? null,
            'raw_text' => mb_substr(trim(isset($place['part']) ? $place['line'] . "\n" . $entry['heading'] : ($entry['heading'] ?? '') . "\n" . $place['line']), 0, 1000),
            'entry_text' => $entryText,
            'copy_text' => $copyText,
            'match_method' => $method,
            'confidence' => min(100, $confidence),
            'score' => round($best['score'], 2),
            'candidates' => count($scored),
            'notes' => $others ? 'جاهای دیگر: ' . $others : $note,
        ];
    }

    /**
     * The printed text at a place (backlog item 60): [entry_text, copy_text].
     *
     * In an ordinary entry, a copy is its line «ش 2366، 162 برگ، …» with the lines that follow it up to a blank line,
     * the next copy or the end of the entry; the entry text is the rest (heading, author, description, incipit,
     * explicit, and a closing note such as «هر سه نسخه بیاض است»). A number cited inside a sentence has that line.
     *
     * In a composite volume, the entry text is the treatise from its heading to the next; the copy text is the volume's
     * heading «( 937 )» with the notes that close the volume after its last treatise, «کتاب اول … 75 برگ، 10 × 15»,
     * which describe every treatise of it. Lines broken by a page are joined when the sentence goes on.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public static function texts(array $place, array $lines): array
    {
        $entry = $place['entry'] ?? null;
        if (! $entry || ! $lines) {
            return [null, null];
        }
        $end = $entry['line_end'] ?? count($lines);
        if (isset($place['volume'])) {
            $volume = $place['volume'];
            $closing = self::closing($lines, $volume['last'], $volume['end']);
            $body = range($entry['line'], ($end === $volume['end'] ? $closing : $end) - 1);
            $copy = array_merge(range($volume['line'], $volume['first'] - 1), $closing < $volume['end'] ? range($closing, $volume['end'] - 1) : []);

            return [self::joinLines($lines, $body), self::joinLines($lines, $copy)];
        }
        $owner = []; // line => the copy line it belongs to
        $copyLine = null;
        for ($i = $entry['line'] + 1; $i < $end; $i++) {
            if (preg_match(self::COPY, self::digits($lines[$i]['text']))) {
                $copyLine = $i;
            } elseif ($copyLine !== null && $lines[$i]['gap']) {
                $copyLine = null;
            }
            if ($copyLine !== null) {
                $owner[$i] = $copyLine;
            }
        }
        $body = array_values(array_filter(range($entry['line'], $end - 1), fn ($i) => ! isset($owner[$i])));
        $own = $owner[$place['line_no'] ?? -1] ?? null;
        $copy = $own === null ? (isset($place['line_no']) ? [$place['line_no']] : []) : array_keys($owner, $own, true);

        return [self::joinLines($lines, $body), self::joinLines($lines, $copy)];
    }

    /** A copy's own line: «ش 2366، …», «ش 1120، تحریر …», or the number alone before its leaves, «4036، 192 برگ». */
    private const COPY = '/^\s*(?:ش\s*\d+|\d+\s*[،,]\s*\d+\s*برگ)/u';

    /** The first note that closes a composite volume: «کتاب اول …», «رساله‌های 1 و 2 …», «هر سه …», «هیچ کدام …». */
    private const CLOSING = '/^\s*(?:(?:کتاب|رساله|نسخه)(?:\s*\x{200C}?\s*(?:ها|های))?\s*(?:اول|نخست|یکم|دوم|دوّم|سوم|سوّم|چهارم|پنجم|ششم|هفتم|هشتم|نهم|دهم|یازدهم|دوازدهم|\d)|هر\s*(?:دو|سه|چهار|پنج|شش|هفت|هشت|نه|ده)(?:\s|$)|همه\s*(?:ی|\x{200C}ی)?\s*(?:رساله|کتاب|نسخه)|هیچ\s*(?:کدام|یک)|بقیه|سایر|این مجموعه|\d+\s*برگ)/u';

    /** Where the closing notes of a composite volume begin among the lines of its last treatise (from its heading). */
    private static function closing(array $lines, int $last, int $end): int
    {
        // the leaves and size close the volume, «75 برگ، 10 × 15»; a note before them that speaks of the treatises opens it
        for ($i = $last + 2; $i < $end; $i++) {
            if (preg_match(self::CLOSING, self::digits($lines[$i]['text']))) {
                return $i;
            }
        }

        return $end;
    }

    /** Lines joined as printed: a line the page broke goes on after a space, a gap between parts is one line break. */
    private static function joinLines(array $lines, array $ids): ?string
    {
        $out = null;
        $prev = null;
        foreach ($ids as $i) {
            $text = $lines[$i]['text'];
            if ($out === null) {
                $out = $text;
            } elseif ($i === $prev + 1 && $lines[$i]['page_start'] && ! $lines[$i]['gap'] && self::continues($lines[$prev]['text'], $text)) {
                $out .= ' ' . $text;
            } else {
                $out .= "\n" . $text;
            }
            $prev = $i;
        }

        return $out === null ? null : mb_substr($out, 0, 20000);
    }

    /**
     * Whether the first line of a page goes on with the sentence the last line of the page before left open. A line
     * that ends in a number ends a sentence too: the reader's text puts the full stop before it («در .1070») or drops it.
     */
    private static function continues(string $before, string $after): bool
    {
        return ! preg_match('/[.!؟?:»\]…\d۰-۹]\s*$/u', $before)
            && ! preg_match('/^\s*(?:آغاز|انجام|از\s*:|ش\s*[\d۰-۹]|\[|[\d۰-۹]+\s*[-–ـ]|\(\s*(?:شماره\s*)?[\d۰-۹]+\s*\)$)/u', $after)
            && ! preg_match(self::CLOSING, self::digits($after));
    }

    /**
     * Fills the printed text of every reference of the edition, reviewed or not and whatever its source, from the entry
     * at its stored address (page_start and entry_number, or the treatise of the shelfmark's part); nothing else changes.
     */
    private function fillTexts(SourceVolume $edition, array $index, array $lines, bool $dry): array
    {
        $stats = ['references' => 0, 'filled' => 0, 'unchanged' => 0, 'entry not found' => 0, 'edited, kept' => 0];
        Entry::where('source_volume_id', $edition->id)->where('citable_type', Manuscript::class)->with('citable:id,shelfmark')->orderBy('id')
            ->chunkById(500, function ($entries) use ($index, $lines, $dry, &$stats) {
                foreach ($entries as $entry) {
                    $stats['references']++;
                    // a text a reader corrected is theirs
                    if ($entry->text_status === Entry::TEXT_EDITED) {
                        $stats['edited, kept']++;

                        continue;
                    }
                    $place = self::placeOf($entry, $index);
                    if (! $place) {
                        $stats['entry not found']++;

                        continue;
                    }
                    [$entryText, $copyText] = self::texts($place, $lines);
                    $values = self::textValues($entryText, $copyText, $entry->volumeNumber(), $entry->page_start);
                    if (array_intersect_key($entry->getAttributes(), $values) == $values) {
                        $stats['unchanged']++;

                        continue;
                    }
                    $stats['filled']++;
                    // the review's time stays the entry's last change
                    $dry || DB::table('source_entries')->where('id', $entry->id)->update($values);
                }
            });

        return $stats;
    }

    /** The printed text of an entry and the place it was taken from. */
    private static function textValues(?string $entryText, ?string $copyText, ?int $volume, ?int $page): array
    {
        $has = filled($entryText) || filled($copyText);

        return [
            'entry_text' => $entryText, 'copy_text' => $copyText,
            'text_volume_number' => $has ? $volume : null, 'text_page' => $has ? $page : null,
            'text_status' => $has ? Entry::TEXT_AUTO : Entry::TEXT_NONE,
        ];
    }

    /** The place of a stored entry: its shelfmark's number in the entry that begins at its page_start. */
    public static function placeOf(Entry $ref, array $index): ?array
    {
        if (! $ref->page_start || ! preg_match('/(\d+)(?:\s*\/\s*(\d+))?/', self::digits((string) $ref->citable?->shelfmark), $base)) {
            return null;
        }
        $part = isset($base[2]) ? (int) $base[2] : null;
        $places = array_filter($index[$base[1]] ?? [], fn ($p) => ($p['entry']['page_start'] ?? null) === $ref->page_start
            && ($ref->entry_number === null || ($p['entry']['number'] ?? null) === $ref->entry_number));
        // the same part first, then the page where the number stands
        usort($places, fn ($a, $b) => [($a['part'] ?? $part) !== $part, $a['page'] !== $ref->shelfmark_page] <=> [($b['part'] ?? $part) !== $part, $b['page'] !== $ref->shelfmark_page]);

        return $places[0] ?? null;
    }

    private function write(SourceVolume $edition, array $matches, bool $dry): array
    {
        $stats = ['insert' => 0, 'update' => 0, 'unchanged' => 0, 'reviewed, kept' => 0, 'weak, superseded' => 0, 'sibling weak, deleted' => 0];
        $copies = fn ($query) => $query->where('citable_type', Manuscript::class);
        $siblings = SourceVolume::where('facsimile_code', $edition->facsimile_code)->where('id', '!=', $edition->id)->pluck('id');
        $siblingRefs = $copies(Entry::whereIn('source_volume_id', $siblings))->where('origin', Entry::ORIGIN_MATCH)->where('status', '!=', Entry::STATUS_REJECTED)
            ->get(['id', 'citable_id', 'match_method', 'confidence', 'status'])->groupBy('citable_id');
        $existing = $copies(Entry::where('source_volume_id', $edition->id))->where('origin', Entry::ORIGIN_MATCH)->get()->keyBy('citable_id');
        // a copy whose address a reviewer set by hand (an approved correction) is not matched again
        $manual = $copies(Entry::where('source_volume_id', $edition->id))->where('origin', '!=', Entry::ORIGIN_MATCH)->pluck('citable_id')->flip();
        $inserts = [];
        foreach ($matches as $match) {
            $values = [
                'volume_number' => $edition->volume_number,
                'page_start' => $match['page_start'], 'page_end' => $match['page_end'], 'shelfmark_page' => $match['shelfmark_page'],
                'entry_number' => $match['entry_number'], 'citation_text' => $match['raw_text'],
                'match_method' => $match['match_method'], 'confidence' => $match['confidence'], 'notes' => $match['notes'],
            ] + self::textValues($match['entry_text'], $match['copy_text'], $edition->volume_number, $match['page_start']);
            $old = $existing[$match['manuscript_id']] ?? null;
            $others = $siblingRefs[$match['manuscript_id']] ?? collect();
            if (self::confirmed($match['match_method'], $match['confidence'])) {
                $weak = $others->filter(fn ($r) => $r->status === Entry::STATUS_UNVERIFIED && ! self::confirmed($r->match_method, $r->confidence));
                $stats['sibling weak, deleted'] += $weak->count();
                $dry || ($weak->isNotEmpty() && Entry::whereIn('id', $weak->pluck('id'))->delete());
            } elseif ($others->contains(fn ($r) => self::confirmed($r->match_method, $r->confidence) || $r->status === Entry::STATUS_VERIFIED)) {
                $stats['weak, superseded']++;
                if ($old && $old->status === Entry::STATUS_UNVERIFIED) {
                    $dry || $old->delete();
                }

                continue;
            }
            if (isset($manual[$match['manuscript_id']])) {
                $stats['reviewed, kept']++;
            } elseif (! $old) {
                $stats['insert']++;
                $inserts[] = SourceEntrySync::row($values + ['citable_type' => Manuscript::class, 'citable_id' => $match['manuscript_id'],
                    'source_id' => $edition->source_id, 'source_volume_id' => $edition->id, 'origin' => Entry::ORIGIN_MATCH,
                    'legacy_type' => null, 'legacy_id' => null]);
            } elseif ($old->status !== Entry::STATUS_UNVERIFIED) {
                $stats['reviewed, kept']++;
            } else {
                $old->fill($values);
                if ($old->isDirty()) {
                    $stats['update']++;
                    $dry || $old->save();
                } else {
                    $stats['unchanged']++;
                }
            }
        }
        if (! $dry) {
            foreach (array_chunk($inserts, 500) as $batch) {
                DB::table('source_entries')->insert($batch);
            }
        }

        return $stats;
    }

    /** The entry title without the leaf range of a treatise: «حبل المتین «برگ 1 - 143»», «الوجیزة (144 - 145)». */
    public static function entryTitle(string $title): string
    {
        $title = preg_replace('/«[^»]*\d[^»]*»|\(\s*\d+\s*[-–ـ]\s*\d+\s*\)/u', ' ', $title);

        return trim(preg_replace('/\s+/u', ' ', $title));
    }

    public static function digits(string $text): string
    {
        return strtr($text, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            'ي' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ی',
        ]);
    }

    /** A title reduced for comparison: letters unified, diacritics, «ال» and «رسالة فی» dropped, punctuation spaced. */
    public static function norm(string $title): string
    {
        $s = str_replace("\u{200C}", ' ', self::digits($title));
        $s = preg_replace('/[\x{064B}-\x{0652}\x{0640}]/u', '', $s);
        $s = preg_replace('/^(ال|رسالة فی |رساله در )/u', '', trim($s));
        $s = preg_replace('/(*UCP)\bال/u', '', $s);
        $s = preg_replace('/(*UCP)[^\w\s]/u', ' ', $s);

        return trim(preg_replace('/\s+/u', ' ', $s));
    }

    /** difflib's ratio (Ratcliff/Obershelp) over characters: similar_text on a one-byte-per-character encoding. */
    public static function similarity(string $a, string $b): float
    {
        if ($a === '' && $b === '') {
            return 1.0;
        }
        $map = [];
        $encode = function (string $s) use (&$map) {
            $out = '';
            foreach (mb_str_split($s) as $ch) {
                $map[$ch] ??= chr(count($map) % 256);
                $out .= $map[$ch];
            }

            return $out;
        };
        $x = $encode($a);
        $y = $encode($b);
        similar_text($x, $y, $percent);

        return $percent / 100;
    }
}
