<?php

namespace App\Support;

use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\DB;

/**
 * One printed page (or column) of a source as its text page shows it (plan docs/plans/source-page-text.md): the page's
 * text cut where its entries start, each part with the entry it belongs to. A source whose page texts are on the site
 * (source_pages: Monzavi, Kashf, Idah, Hadiyya) gives the printed page; al-Dhari'a, whose text is kept by entry only,
 * gives its entries' parts on that page.
 *
 * A library catalogue or Fankha has no entries of its own (source_items) but the records made from it: their lines on
 * the page (a work's heading, «● الاربعون حديثاً / حدیث / عربی», a copy's «1. تهران؛ مجلس؛ شماره نسخه: 7728» or
 * «ش 714، نسخ…») are marked and link the work or manuscript.
 */
class PrintedPage
{
    /**
     * @param  Collection<int, SourceItem>  $items  the entries on the page, in their order
     * @param  list<array{item: ?SourceItem, text: string, continued: bool, marks?: list<array{at: int, length: int, url: string, kind: string, title: string}>}>  $blocks
     * @param  list<array{url: string, title: string, kind: string}>  $records  the works and manuscripts marked on the page, in order
     */
    private function __construct(
        public readonly Source $source,
        public readonly SourceVolume $volume,
        public readonly int $page,
        public readonly Collection $items,
        public readonly array $blocks,
        public readonly bool $frontMatter,
        public readonly ?array $previous,
        public readonly ?array $next,
        public readonly array $records = [],
    ) {
    }

    /** The page, or null when the volume has no text there. */
    public static function find(Source $source, SourceVolume $volume, int $page): ?self
    {
        $items = SourceItem::where('source_volume_id', $volume->id)->where('page_start', '<=', $page)->where('page_end', '>=', $page)
            ->orderBy('position')->get()->each->setRelation('source', $source);
        $paged = self::hasPageTexts($volume);
        $text = $paged ? DB::table('source_pages')->where('source_volume_id', $volume->id)->where('page', $page)->value('text') : null;
        if ($paged ? $text === null : $items->isEmpty()) {
            return null;
        }

        $blocks = $paged ? self::cut($text, $items, $page) : $items->map(fn (SourceItem $item) => [
            'item' => $item,
            'text' => collect($item->pageSegments())->firstWhere('page', $page)['text'] ?? '',
            'continued' => $item->page_start < $page,
        ])->filter(fn ($b) => $b['text'] !== '')->values()->all();
        $firstEntry = SourceItem::where('source_volume_id', $volume->id)->where('kind', '!=', 'preface')->min('page_start');
        $records = [];
        if ($paged && $items->isEmpty() && count($blocks) === 1) {
            $blocks[0]['marks'] = self::marks($blocks[0]['text'], $source, $volume, $page);
            $records = collect($blocks[0]['marks'])->unique('url')->map(fn ($m) => array_intersect_key($m, array_flip(['url', 'title', 'kind'])))->values()->all();
        }

        return new self($source, $volume, $page, $items, $blocks, $firstEntry !== null && $page < $firstEntry,
            self::neighbour($source, $volume, $page, -1), self::neighbour($source, $volume, $page, 1), $records);
    }

    /** Whether the page is printed in two columns (config/catalogs.php: Fankha). */
    public function columns(): int
    {
        return (int) config("catalogs.columns.{$this->source->code}", 1);
    }

    /**
     * A piece of the page's text as HTML: escaped, in Persian digits, and
     * - Noor's marks (App\Support\PageMarks) set: a Quran verse with its sura and verse on hover, a hadith, a passage in
     *   Latin letters left to right with its own digits; al-Dhari'a's names kept as data-noor for later;
     * - a line wholly in Latin letters (Fankha's transliteration, «erjā‘-ol-mote‘allemīn») left to right;
     * - the verses of a poem (lines whose hemistichs a tab parts, as the Noor readers write Noor's <poem>) set as a poem:
     *   centred, each hemistich as wide as the longest, the blank lines around it dropped.
     * A mark that runs over a line break or into a poem closes at the line's end and opens again on the next.
     */
    public static function text(string $text): HtmlString
    {
        $stack = [];
        $parts = [];
        foreach (explode("\n", $text) as $line) {
            if (str_contains($line, "\t")) {
                if (($parts[array_key_last($parts) ?? -1]['poem'] ?? false) === false) {
                    // the blank line before a poem goes; so does the one after it, below
                    while ($parts && end($parts)['html'] === '' && ! end($parts)['poem']) {
                        array_pop($parts);
                    }
                    $parts[] = ['poem' => true, 'html' => ''];
                }
                foreach (explode("\t", $line) as $h) {
                    $parts[array_key_last($parts)]['html'] .= '<span class="book-poem__h">' . self::inline(trim($h), $stack) . '</span>';
                }
                continue;
            }
            $plain = PageMarks::strip($line);
            if (trim($plain) === '') {
                // a blank line, or one with only a mark's opening or closing: its marks still count, its text is none
                self::inline($line, $stack);
                if (! ($parts && end($parts)['poem'])) {
                    $parts[] = ['poem' => false, 'html' => ''];
                }
                continue;
            }
            $parts[] = ['poem' => false, 'html' => ! $stack && $plain === $line && preg_match('/\p{Latin}/u', $line) && ! preg_match('/\p{Arabic}/u', $line)
                ? '<span dir="ltr" class="book-sheet__latin">' . e($line) . '</span>'
                : self::inline($line, $stack)];
        }

        // a poem is a block of its own: no line break before or after it
        $html = '';
        foreach ($parts as $i => $part) {
            if ($part['poem']) {
                $html .= '<span class="book-poem" data-poem>' . $part['html'] . '</span>';
                continue;
            }
            $html .= ($i > 0 && ! $parts[$i - 1]['poem'] ? "\n" : '') . $part['html'];
        }

        return new HtmlString($html);
    }

    /** One line (or hemistich) with its marks as elements; $stack holds the marks still open when it ends. */
    private static function inline(string $line, array &$stack): string
    {
        $html = implode('', array_map([self::class, 'openTag'], $stack));
        foreach (preg_split('/(\x{E020}[^\x{E021}]*\x{E021}|\x{E022})/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $token) {
            if ($token === "\u{E022}") {
                if ($stack) {
                    $html .= self::closeTag(array_pop($stack));
                }
            } elseif (str_starts_with($token, "\u{E020}")) {
                $stack[] = $kind = mb_substr($token, 1, -1);
                $html .= self::openTag($kind);
            } else {
                $html .= in_array('latin', $stack, true) ? e($token) : e(Fa::digits($token));
            }
        }

        return $html . implode('', array_map([self::class, 'closeTag'], array_reverse($stack)));
    }

    private static function openTag(string $kind): string
    {
        if (str_starts_with($kind, 'ayah')) {
            $label = Quran::label(trim(substr($kind, 4)));

            return '<span class="book-ayah"' . ($label ? ' title="' . e($label) . '"' : '') . ' data-ayah="' . e(trim(substr($kind, 4))) . '">';
        }

        return match ($kind) {
            'hadith' => '<span class="book-hadith">',
            'latin' => '<bdi dir="ltr" class="book-latin">',
            default => '<span data-noor="' . e($kind) . '">',
        };
    }

    private static function closeTag(string $kind): string
    {
        return $kind === 'latin' ? '</bdi>' : '</span>';
    }

    /**
     * The block cut at its marks: plain text, and the marked lines with their record.
     *
     * @return list<array{text: string, url?: string, kind?: string, title?: string}>
     */
    public static function pieces(array $block): array
    {
        $pieces = [];
        $from = 0;
        foreach ($block['marks'] ?? [] as $mark) {
            if ($mark['at'] > $from) {
                $pieces[] = ['text' => mb_substr($block['text'], $from, $mark['at'] - $from)];
            }
            $pieces[] = ['text' => mb_substr($block['text'], $mark['at'], $mark['length'])] + $mark;
            $from = $mark['at'] + $mark['length'];
        }
        $pieces[] = ['text' => mb_substr($block['text'], $from)];

        return array_values(array_filter($pieces, fn ($p) => $p['text'] !== ''));
    }

    /**
     * The lines of the records made from this page: each source entry on it is found by the first line of its text
     * (a work's heading in entry_text, a copy's line in copy_text), and a heading shared by a catalogue's copies
     * («1- القرآن الکریم») links their work, and a work's author line in Fankha («شیروانی، محمد بن حسن، 1033 - 1098
     * قمری»), which opens with the author's name as the site has it, links the person. A line is marked to its end, once.
     */
    private static function marks(string $text, Source $source, SourceVolume $volume, int $page): array
    {
        // an entry is read from its first page on; a catalogue's entries are short, so a few pages back is enough
        $entries = SourceEntry::with('citable')->where('source_id', $source->id)->where('volume_number', $volume->volume_number)
            ->whereBetween('page_start', [$page - 5, $page])->where('role', SourceEntry::ROLE_ENTRY)->where('status', '!=', SourceEntry::STATUS_REJECTED)
            ->get()->filter(fn (SourceEntry $e) => $e->citable && in_array($page, array_filter([$e->text_page, $e->shelfmark_page, $e->page_start]), true)
                || ($e->citable && $e->page_start <= $page && ($e->page_end ?? $e->page_start) >= $page))
            ->sortBy('id');
        $entries->loadMorph('citable', [Manuscript::class => ['work:id,primary_title,clean_title', 'libraryRecord:id,name'],
            \App\Models\Work::class => ['author', 'authors']]);

        $wanted = [];
        foreach ($entries as $e) {
            $first = fn (?string $t) => trim((string) strtok(trim((string) $t), "\n"));
            if ($e->citable instanceof Manuscript) {
                $wanted[] = [$first($e->copy_text), route('manuscripts.show', $e->citable), 'manuscript', $first($e->copy_text)];
                if ($e->citable->work && ($heading = $first($e->entry_text)) !== '') {
                    $wanted[] = [$heading, route('works.show', $e->citable->work), 'work', $e->citable->work->primary_title];
                }
            } else {
                $wanted[] = [$first($e->entry_text), route('works.show', $e->citable), 'work', $e->citable->primary_title ?? $first($e->entry_text)];
                // its authors' lines, each the first of the entry's lines that opens with the author's name
                $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $e->entry_text))));
                foreach (collect([$e->citable->author, ...($e->citable->authors ?? [])])->filter()->unique('id') as $person) {
                    $line = $person->name ? collect($lines)->first(fn ($l) => str_starts_with($l, $person->name)) : null;
                    if ($line) {
                        // the same author's line under another work is that work's: claimed once for each
                        $wanted[] = [$line, route('people.show', $person), 'person', $person->name, $e->id];
                    }
                }
            }
        }

        // a line printed twice («● الفرائض / فقه / عربی» of two authors) is claimed in turn, in the entries' order
        $marks = [];
        $seen = [];
        $after = [];
        foreach ($wanted as $w) {
            [$line, $url, $kind, $title] = $w;
            $needle = mb_substr($line, 0, 40);
            $key = $needle . "\0" . $url . "\0" . ($w[4] ?? '');
            if (mb_strlen($needle) < 4 || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $at = mb_strpos($text, $needle, $after[$needle] ?? 0);
            // the line, at its start, not inside another
            while ($at !== false && $at > 0 && mb_substr($text, $at - 1, 1) !== "\n") {
                $at = mb_strpos($text, $needle, $at + 1);
            }
            if ($at === false) {
                continue;
            }
            $after[$needle] = $at + 1;
            $end = mb_strpos($text, "\n", $at);
            $marks[] = ['at' => $at, 'length' => ($end === false ? mb_strlen($text) : $end) - $at, 'url' => $url, 'kind' => $kind, 'title' => $title];
        }
        usort($marks, fn ($a, $b) => $a['at'] <=> $b['at']);

        // overlapping lines keep the first
        $kept = [];
        foreach ($marks as $m) {
            if (! $kept || $m['at'] >= end($kept)['at'] + end($kept)['length']) {
                $kept[] = $m;
            }
        }

        return $kept;
    }

    /** The page text of a page the viewer shows (its volume's facsimile_code), when the site has that page's text. */
    public static function urlForViewer(array $viewer): ?string
    {
        $volume = SourceVolume::with('source')->where('facsimile_code', $viewer['catalog'] ?? null)->where('volume_number', (int) ($viewer['volume'] ?? 0))->first();

        return $volume?->source && self::exists($volume, (int) $viewer['page'])
            ? route('source-pages.show', [$volume->source->code, $volume->volume_number, (int) $viewer['page']]) : null;
    }

    /** Whether the volume has a printed page, as the text page and the links to it judge. */
    public static function exists(SourceVolume $volume, int $page): bool
    {
        return self::hasPageTexts($volume)
            ? DB::table('source_pages')->where('source_volume_id', $volume->id)->where('page', $page)->exists()
            : SourceItem::where('source_volume_id', $volume->id)->where('page_start', '<=', $page)->where('page_end', '>=', $page)->exists();
    }

    /** «ستون» or «ص», as the source numbers its pages. */
    public function unit(): string
    {
        return $this->source->unit === 'ستون' ? 'ستون' : 'ص';
    }

    /** «ج ۵، ص ۳۳۶۶». */
    public function placeLabel(): string
    {
        return Fa::digits("ج {$this->volume->volume_number}، {$this->unit()} {$this->page}");
    }

    /** The page in the page viewer, when the site has the source's page images. */
    public function viewer(): ?array
    {
        $code = $this->source->hasPageImages() ? $this->volume->facsimile_code : null;

        return $code ? ['catalog' => $code, 'catalogName' => $this->source->short_name ?: $this->source->title,
            'volume' => $this->volume->volume_number, 'page' => $this->page] : null;
    }

    public function thumbUrl(): ?string
    {
        $v = $this->viewer();

        return $v ? asset('storage/catalogs/' . $v['catalog'] . '/v' . str_pad($v['volume'], 2, '0', STR_PAD_LEFT) . '/p' . str_pad($this->page, 4, '0', STR_PAD_LEFT) . '.webp') : null;
    }

    /**
     * The page's text cut where each entry that starts on it begins; the part before the first belongs to the entry
     * that runs on from the page before, if any. An entry is found by its first line, which Noor's pages and entries
     * share.
     */
    private static function cut(string $text, Collection $items, int $page): array
    {
        // the entries are looked for in the page's words, without Noor's marks (App\Support\PageMarks), and cut where
        // those words stand in the page
        [$plain, $at_in_text] = self::withoutMarks($text);
        $starts = [];
        $from = 0;
        foreach ($items->where('page_start', $page) as $item) {
            $line = trim(strtok((string) $item->text, "\n"));
            $needle = mb_substr($line, 0, 40);
            $at = $needle === '' ? false : mb_strpos($plain, $needle, $from);
            // al-Dhari'a's volume 26 prints the number on a line of its own: «[ 972 ]» then «( ترجمه تاريخ عباسه ) …»,
            // where the entry reads «( 972 : ترجمه تاريخ عباسه ) …»
            if ($at === false && preg_match('/^\(\s*(\d+)\s*:\s*(.+)$/u', $line, $m)
                && ($at = mb_strpos($plain, mb_substr('( ' . $m[2], 0, 40), $from)) !== false
                && preg_match('/\[\s*' . $m[1] . '\s*\]\s*$/u', mb_substr($plain, 0, $at), $number)) {
                $at -= mb_strlen($number[0]);
            }
            if ($at !== false) {
                $starts[] = [$at_in_text[$at], $item];
                $from = $at + 1;
            }
        }

        $blocks = [];
        $head = trim(mb_substr($text, 0, $starts[0][0] ?? null));
        if ($head !== '') {
            $blocks[] = ['item' => $items->last(fn (SourceItem $i) => $i->page_start < $page), 'text' => $head, 'continued' => true];
        }
        foreach ($starts as $n => [$at, $item]) {
            $end = $starts[$n + 1][0] ?? null;
            $blocks[] = ['item' => $item, 'text' => trim(mb_substr($text, $at, $end === null ? null : $end - $at)), 'continued' => false];
        }

        return $blocks;
    }

    /**
     * The text without Noor's marks, and where each of its characters stands in the text with them.
     *
     * @return array{0: string, 1: list<int>}
     */
    private static function withoutMarks(string $text): array
    {
        $plain = '';
        $at = [];
        $i = 0;
        foreach (preg_split('/(' . trim(PageMarks::PATTERN, '/u') . ')/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $k => $token) {
            $length = mb_strlen($token);
            if ($k % 2 === 0 && $length > 0) {
                $plain .= $token;
                array_push($at, ...range($i, $i + $length - 1));
            }
            $i += $length;
        }
        $at[] = $i;

        return [$plain, $at];
    }

    /** The page before or after, in the volume or at the end of the volume next to it: [volume, page]. */
    private static function neighbour(Source $source, SourceVolume $volume, int $page, int $step): ?array
    {
        $found = self::edge($volume, $step, $page);
        if ($found !== null) {
            return ['volume' => $volume->volume_number, 'page' => $found];
        }
        $other = SourceVolume::where('source_id', $source->id)->where('volume_number', $volume->volume_number + $step)->first();
        $edge = $other ? self::edge($other, -$step) : null;

        return $edge !== null ? ['volume' => $other->volume_number, 'page' => $edge] : null;
    }

    /**
     * The volume's nearest page past $page in the direction of $step; with no $page, the volume's last page for a
     * step of 1 and its first for -1 (the edge a reader comes to from the volume before or after). A volume kept by
     * entry has every page its entries cover.
     */
    private static function edge(SourceVolume $volume, int $step, ?int $page = null): ?int
    {
        $forward = $page !== null ? $step > 0 : $step < 0;
        if (self::hasPageTexts($volume)) {
            $q = DB::table('source_pages')->where('source_volume_id', $volume->id);
            if ($page !== null) {
                $q->where('page', $forward ? '>' : '<', $page);
            }
            $found = $forward ? $q->min('page') : $q->max('page');

            return $found !== null ? (int) $found : null;
        }

        $row = SourceItem::where('source_volume_id', $volume->id)->selectRaw('min(page_start) first_page, max(page_end) last_page')->first();
        if ($row?->first_page === null) {
            return null;
        }
        if ($page === null) {
            return (int) ($forward ? $row->first_page : $row->last_page);
        }
        $to = $page + ($forward ? 1 : -1);

        return $to >= $row->first_page && $to <= $row->last_page ? $to : null;
    }

    private static function hasPageTexts(SourceVolume $volume): bool
    {
        return DB::table('source_pages')->where('source_volume_id', $volume->id)->exists();
    }
}
