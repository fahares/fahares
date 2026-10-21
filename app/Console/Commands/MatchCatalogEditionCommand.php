<?php

namespace App\Console\Commands;

use App\Models\CatalogVolume;
use App\Models\ManuscriptCatalogReference as Ref;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Finds the copies of a library in the text of another printing of its catalogue (backlog item 51) and writes
 * them as ai_match + unverified catalogue references for the review queue.
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
 * page_start is the page where the entry begins (the viewer opens it) and shelfmark_page where the number stands.
 * Running it again updates the unverified matches and leaves the reviewed ones (verified or rejected), and the copies
 * whose address was corrected by hand (source manual), alone.
 */
class MatchCatalogEditionCommand extends Command
{
    protected $signature = 'catalogs:match-edition
        {edition : facsimile_code of the edition (catalog_volumes row)}
        {pages : Directory of page JSON files, or a .jsonl / .jsonl.gz file, with "page" and "text"}
        {--volume= : Volume number of the edition, when the code has several}
        {--dry-run : Report the counts without saving anything}
        {--report= : Write every match as JSON to this path}';

    protected $description = 'Match a library\'s copies to the pages of another printing of its catalogue by shelfmark and title';

    public const METHOD_TITLE = 'shelfmark_title';

    public const METHOD_SINGLE_PAGE = 'shelfmark_single_page';

    public const METHOD_SHELFMARK_ONLY = 'shelfmark_only';

    public const METHOD_AMBIGUOUS = 'ambiguous';

    private const ENTRY = '/^\s*(\d+)\s*[-–]\s*(.+?)\s*(?:\(([^)]*)\))?\s*$/u';

    private const SHELF = '/(?:^|[\s،(])ش\s*(\d+)(?:\s*\/\s*(\d+))?/u';

    public function handle(): int
    {
        $editions = CatalogVolume::where('facsimile_code', $this->argument('edition'))
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

        [$index, $entries] = self::index($pages);
        $this->line(sprintf('%s v%d: %d pages, %d entries, %d shelfmarks indexed', $edition->facsimile_code, $edition->volume_number, count($pages), $entries, count($index)));

        $copies = DB::table('manuscripts')->leftJoin('works', 'works.id', '=', 'manuscripts.work_id')
            ->where('manuscripts.library_id', $edition->library_id)
            ->select('manuscripts.id', 'manuscripts.shelfmark', 'works.primary_title')->orderBy('manuscripts.id')->get();

        $stats = ['copies' => $copies->count(), 'no shelfmark in edition' => 0] + array_fill_keys([self::METHOD_TITLE, self::METHOD_SINGLE_PAGE, self::METHOD_SHELFMARK_ONLY, self::METHOD_AMBIGUOUS], 0);
        $matches = [];
        foreach ($copies as $copy) {
            $match = self::match($copy, $index);
            if (! $match) {
                $stats['no shelfmark in edition']++;

                continue;
            }
            $stats[$match['match_method']]++;
            $matches[] = $match;
        }

        $write = ['insert' => 0, 'update' => 0, 'unchanged' => 0, 'reviewed, kept' => 0];
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
     * Every shelfmark base number with the places it stands: the page, and the entry it belongs to (number, title,
     * first and last page).
     *
     * @return array{0: array<string, list<array>>, 1: int}
     */
    public static function index(array $pages): array
    {
        $index = [];
        $entries = [];
        $current = null;
        foreach ($pages as $page => $text) {
            $first = true;
            foreach (preg_split('/\R/u', self::digits($text)) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                if (preg_match(self::ENTRY, $line, $m) && mb_strlen($m[2]) < 120 && ! str_starts_with(ltrim($line), 'ش')) {
                    if ($current !== null) {
                        // the previous entry ends here, or on the page before when this one opens the page
                        $entries[$current]['page_end'] = $first ? max($entries[$current]['page_start'], $page - 1) : $page;
                    }
                    $entries[] = ['number' => (int) $m[1], 'title' => trim($m[2]), 'heading' => trim($line), 'page_start' => $page, 'page_end' => $page];
                    $current = array_key_last($entries);
                    $first = false;

                    continue;
                }
                $first = false;
                if (preg_match_all(self::SHELF, $line, $all, PREG_SET_ORDER)) {
                    foreach ($all as $s) {
                        $index[$s[1]][] = ['page' => $page, 'entry' => $current, 'line' => trim($line)];
                    }
                }
            }
            if ($current !== null) {
                $entries[$current]['page_end'] = max($entries[$current]['page_end'], $page);
            }
        }
        // places resolved to their entry once every entry has its last page
        foreach ($index as &$places) {
            foreach ($places as &$place) {
                $place['entry'] = $place['entry'] === null ? null : $entries[$place['entry']];
            }
        }

        return [$index, count($entries)];
    }

    /** The match of one copy ({id, shelfmark, primary_title}), or null when its number is not in the edition. */
    public static function match(object $copy, array $index): ?array
    {
        if (! preg_match('/\d+/', self::digits((string) $copy->shelfmark), $base) || empty($index[$base[0]])) {
            return null;
        }
        $title = self::norm((string) $copy->primary_title);
        $scored = [];
        foreach ($index[$base[0]] as $place) {
            $parts = explode('=', $place['entry']['title'] ?? '');
            $scored[] = ['score' => max(array_map(fn ($part) => self::similarity($title, self::norm($part)), $parts)), 'place' => $place];
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
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
            'raw_text' => mb_substr(trim(($entry['heading'] ?? '') . "\n" . $place['line']), 0, 1000),
            'match_method' => $method,
            'confidence' => min(100, $confidence),
            'score' => round($best['score'], 2),
            'candidates' => count($scored),
            'notes' => $others ? 'جاهای دیگر: ' . $others : null,
        ];
    }

    private function write(CatalogVolume $edition, array $matches, bool $dry): array
    {
        $stats = ['insert' => 0, 'update' => 0, 'unchanged' => 0, 'reviewed, kept' => 0];
        $existing = Ref::where('catalog_volume_id', $edition->id)->where('source', Ref::SOURCE_AI_MATCH)->get()->keyBy('manuscript_id');
        // a copy whose address a reviewer set by hand (an approved correction) is not matched again
        $manual = Ref::where('catalog_volume_id', $edition->id)->where('source', '!=', Ref::SOURCE_AI_MATCH)->pluck('manuscript_id')->flip();
        $now = now();
        $inserts = [];
        foreach ($matches as $match) {
            $values = [
                'volume_number' => $edition->volume_number,
                'page_start' => $match['page_start'], 'page_end' => $match['page_end'], 'shelfmark_page' => $match['shelfmark_page'],
                'entry_number' => $match['entry_number'], 'raw_text' => $match['raw_text'],
                'match_method' => $match['match_method'], 'confidence' => $match['confidence'], 'notes' => $match['notes'],
            ];
            $old = $existing[$match['manuscript_id']] ?? null;
            if (isset($manual[$match['manuscript_id']])) {
                $stats['reviewed, kept']++;
            } elseif (! $old) {
                $stats['insert']++;
                $inserts[] = $values + ['manuscript_id' => $match['manuscript_id'], 'catalog_volume_id' => $edition->id, 'source' => Ref::SOURCE_AI_MATCH,
                    'status' => Ref::STATUS_UNVERIFIED, 'created_at' => $now, 'updated_at' => $now];
            } elseif ($old->status !== Ref::STATUS_UNVERIFIED) {
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
                Ref::insert($batch);
            }
        }

        return $stats;
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
