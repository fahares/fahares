<?php

namespace App\Support\PageLinks;

use App\Models\SourceItem;
use App\Models\SourcePage;
use Illuminate\Support\Facades\DB;

/**
 * The entry of a source a reference to a volume and page of it names (step 13.155), or else that page. Of the entries
 * on the page, the one whose title is the reference's («مر في (161:7) بعنوان خسرو و گل»: Titles::agree), or, without a
 * title, the one whose title stands in the words before the reference («بدائع الأصول الذي مر في (ج 3 - ص 62)») or is
 * the referring entry's own («( مثنوي خسروي‌نامه ) … مر (162:7)», Titles::in). A reference to the page it stands on is
 * not linked.
 *
 * A reference with an entry number («ذ 4 رقم 483», step 14.103) links the entry of that number in the volume, on the
 * page when the reference gives one; of two places a pair may be, the one where that entry is.
 */
class EntryResolver
{
    /** @var array<int, array<int, list<object>>> volume => page => the entries on it */
    private array $entries = [];

    /** @var array<int, array<int, list<object>>> volume => entry number => the entries of it */
    private array $numbered = [];

    /** @var array<int, array<int, int>> volume => page => source_pages.id */
    private array $pages = [];

    /** @param  int  $sourceId  the source referred to: the page's own, or another (config page_links.<code>.references.source) */
    public function __construct(private int $sourceId)
    {
        $items = DB::table('source_items')->where('source_id', $sourceId)->whereIn('kind', [SourceItem::KIND_ENTRY, SourceItem::KIND_SUB_ENTRY])
            ->get(['id', 'volume_number', 'page_start', 'page_end', 'title', 'entry_number']);
        foreach ($items as $item) {
            if ($item->entry_number !== null) {
                $this->numbered[$item->volume_number][$item->entry_number][] = $item;
            }
            for ($p = $item->page_start; $p <= max($item->page_start, (int) $item->page_end); $p++) {
                $this->entries[$item->volume_number][$p][] = $item;
            }
        }
        $pages = DB::table('source_pages')->join('source_volumes', 'source_volumes.id', '=', 'source_pages.source_volume_id')
            ->where('source_volumes.source_id', $sourceId)->get(['source_pages.id', 'source_pages.page', 'source_volumes.volume_number']);
        foreach ($pages as $page) {
            $this->pages[$page->volume_number][$page->page] = $page->id;
        }
    }

    /**
     * @param  array{volume: ?int, page: ?int, title: ?string, before: string, number?: ?int, places?: list<array>}  $ref  as References::in() gives it
     * @param  ?int  $fromSource  the source of the page the reference stands on, when it is not the one referred to
     * @return array{0: ?string, 1: ?int, 2: string}  the target's class and id and how, or nulls and why not
     */
    public function resolve(array $ref, object $from, ?SourceItem $holder, ?int $fromSource = null): array
    {
        if (($ref['number'] ?? null) !== null) {
            $hits = [];
            foreach ($ref['places'] ?? [[$ref['volume'], $ref['page']]] as [$volume, $page]) {
                foreach ($this->numbered[$volume][$ref['number']] ?? [] as $item) {
                    if ($page === null || ($page >= $item->page_start - 1 && $page <= max($item->page_start, (int) $item->page_end) + 1)) {
                        $hits[$item->id] = $item;
                    }
                }
            }
            if (count($hits) === 1) {
                return [SourceItem::class, array_key_first($hits), 'number'];
            }
            if ($ref['page'] === null) {
                return [null, null, $hits ? 'number-twice' : 'no-number'];
            }
        }
        if (($fromSource === null || $fromSource === $this->sourceId) && $ref['volume'] === (int) $from->volume_number && $ref['page'] === (int) $from->page) {
            return [null, null, 'own-page'];
        }
        $on = $this->entries[$ref['volume']][$ref['page']] ?? [];
        $hits = $ref['title'] !== null
            ? array_filter($on, fn ($i) => Titles::agree($i->title, $ref['title']))
            : array_filter($on, fn ($i) => Titles::in($i->title, $ref['before']) || ($holder && Titles::agree($i->title, $holder->title)));
        if (count($hits) === 1) {
            return [SourceItem::class, reset($hits)->id, $ref['title'] !== null ? 'title' : 'context'];
        }
        $page = $this->pages[$ref['volume']][$ref['page']] ?? null;

        return $page ? [SourcePage::class, $page, $ref['title'] !== null ? 'page-titled' : 'page'] : [null, null, 'no-page'];
    }
}
