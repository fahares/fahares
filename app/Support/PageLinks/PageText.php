<?php

namespace App\Support\PageLinks;

use App\Models\SourceItem;
use App\Support\PrintedPage;
use Illuminate\Support\Collection;

/**
 * A page's text as the links of step 13.155 read it: its Noor marks with where they stand, its words without them, and
 * the entry holding a place of it. Places are in characters of the page's text, as source_page_links.offset keeps them.
 */
class PageText
{
    private ?array $plain = null;

    private ?array $starts = null;

    /**
     * @param  object  $page  a source_pages row with its volume_number and source_volume_id
     * @param  Collection<int, SourceItem>  $items  the entries on the page
     */
    public function __construct(public readonly object $page, public readonly Collection $items)
    {
    }

    /**
     * The page's marks in order: ordinal, kind, text, and where the mark opens and where its words end (its closing).
     *
     * @return list<array{ordinal: int, kind: string, text: string, at: int, end: int}>
     */
    public function marks(): array
    {
        $text = $this->page->text;
        preg_match_all('/\x{E020}([^\x{E021}]*)\x{E021}/u', $text, $opens, PREG_OFFSET_CAPTURE);
        $marks = [];
        foreach ($opens[0] as $n => [$open, $byte]) {
            $start = $byte + strlen($open);
            $close = strpos($text, "\u{E022}", $start);
            if ($close === false || str_contains(substr($text, $start, $close - $start), "\u{E020}")) {
                continue;
            }
            $marks[] = [
                'ordinal' => $n, 'kind' => $opens[1][$n][0], 'text' => trim(substr($text, $start, $close - $start)),
                'at' => mb_strlen(substr($text, 0, $byte)), 'end' => mb_strlen(substr($text, 0, $close)),
            ];
        }

        return $marks;
    }

    /** The words without the marks, and where each of their characters stands in the text (PrintedPage::withoutMarks). */
    public function plain(): array
    {
        return $this->plain ??= PrintedPage::withoutMarks($this->page->text);
    }

    /** The entry holding a place of the page: the last that starts before it, or the one running on from the page before. */
    public function holder(int $at): ?SourceItem
    {
        $this->starts ??= PrintedPage::entryStarts($this->page->text, $this->items, (int) $this->page->page);
        $holder = $this->items->last(fn (SourceItem $i) => $i->page_start < $this->page->page);
        foreach ($this->starts as [$start, $item]) {
            if ($start <= $at) {
                $holder = $item;
            }
        }

        return $holder;
    }
}
