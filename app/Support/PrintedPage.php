<?php

namespace App\Support;

use App\Models\Source;
use App\Models\SourceItem;
use App\Models\SourceVolume;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One printed page (or column) of a source as its text page shows it (plan docs/plans/source-page-text.md): the page's
 * text cut where its entries start, each part with the entry it belongs to. A source whose page texts are on the site
 * (source_pages: Monzavi, Kashf, Idah, Hadiyya) gives the printed page; al-Dhari'a, whose text is kept by entry only,
 * gives its entries' parts on that page.
 */
class PrintedPage
{
    /**
     * @param  Collection<int, SourceItem>  $items  the entries on the page, in their order
     * @param  list<array{item: ?SourceItem, text: string, continued: bool}>  $blocks
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

        return new self($source, $volume, $page, $items, $blocks, $firstEntry !== null && $page < $firstEntry,
            self::neighbour($source, $volume, $page, -1), self::neighbour($source, $volume, $page, 1));
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
        $starts = [];
        $from = 0;
        foreach ($items->where('page_start', $page) as $item) {
            $line = trim(strtok((string) $item->text, "\n"));
            $needle = mb_substr($line, 0, 40);
            $at = $needle === '' ? false : mb_strpos($text, $needle, $from);
            // al-Dhari'a's volume 26 prints the number on a line of its own: «[ 972 ]» then «( ترجمه تاريخ عباسه ) …»,
            // where the entry reads «( 972 : ترجمه تاريخ عباسه ) …»
            if ($at === false && preg_match('/^\(\s*(\d+)\s*:\s*(.+)$/u', $line, $m)
                && ($at = mb_strpos($text, mb_substr('( ' . $m[2], 0, 40), $from)) !== false
                && preg_match('/\[\s*' . $m[1] . '\s*\]\s*$/u', mb_substr($text, 0, $at), $number)) {
                $at -= mb_strlen($number[0]);
            }
            if ($at !== false) {
                $starts[] = [$at, $item];
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
