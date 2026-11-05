<?php

namespace App\Support\PageLinks;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Models\Work;
use App\Support\DirectAnswer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The copy a holding line of a bibliography names (step 14.34, Holdings): «دانشگاه 2705/12 ش (1) 3701» is the copy of
 * the University of Tehran whose number is 3701, described on page 2705 of volume 12 of its catalogue, as the copy's
 * Fankha citation («[ف: 12-2705]», source_entries of origin fankha_citation) gives the page.
 *
 * The copies looked at are those of the entry's work (its source_entries to a work, «work»), or, for an entry tied to
 * no work, those of the number or the catalogue page whose work's title is the entry's (Titles::agree, «title»). Of
 * them the one that has the number, the page, or both («work+both», «title+shelfmark», …) is linked; the number and the
 * page naming two copies («conflict»), or either naming several («several»), link none. A page found when the number
 * given is of no copy is «page-not-number».
 */
class HoldingResolver
{
    /** @var array<string, list<int>> library ids by the form the source prints */
    private array $libraries = [];

    /** @var array<int, list<int>> work ids by entry id */
    private array $works = [];

    /** @var array<int, array<int, list<array{0: int, 1: int, 2: int}>>> by library and volume: copy id, first and last page */
    private array $cited = [];

    /**
     * @param  array<string, list<array{0: string, 1: string}>>  $forms  the form => the site's libraries (city, name)
     */
    public function __construct(array $forms)
    {
        $all = Library::get(['id', 'city', 'name']);
        foreach ($forms as $form => $places) {
            $this->libraries[$form] = $all->filter(fn (Library $l) => in_array([$l->city, $l->name], $places, true))->pluck('id')->values()->all();
        }
    }

    /** @return list<string> the forms that name a library of the site */
    public function forms(): array
    {
        return array_keys(array_filter($this->libraries));
    }

    /**
     * @param  array{form: string, volume: ?int, page: ?int, shelfmark: ?string}  $holding
     * @return array{0: ?Manuscript, 1: string}  the copy and how, or null and why not
     */
    public function resolve(array $holding, ?SourceItem $entry): array
    {
        $libraries = $this->libraries[$holding['form']] ?? [];
        if (! $libraries) {
            return [null, 'no-library'];
        }
        if (! $entry) {
            return [null, 'no-entry'];
        }
        $works = $this->works[$entry->id] ??= SourceEntry::where('source_item_id', $entry->id)->where('citable_type', Work::class)
            ->where('status', '!=', SourceEntry::STATUS_REJECTED)->pluck('citable_id')->unique()->values()->all();

        if ($works) {
            $basis = 'work';
            $copies = Manuscript::whereIn('library_id', $libraries)->whereIn('work_id', $works)->get(['id', 'work_id', 'library_id', 'shelfmark']);
        } else {
            $basis = 'title';
            $copies = $this->byNumberOrPage($libraries, $holding)->load('work')
                ->filter(fn (Manuscript $m) => $m->work && collect(array_merge(...$m->work->searchTitles()))->contains(fn ($t) => Titles::agree($t, $entry->title)))->values();
        }
        if ($copies->isEmpty()) {
            return [null, "{$basis}:none"];
        }

        $byNumber = $holding['shelfmark'] === null ? null : $this->ofNumber($copies, $holding['shelfmark']);
        $byPage = $holding['page'] === null ? null : $this->ofPage($copies, $holding['volume'], $holding['page']);

        if ($byNumber !== null && $byPage !== null) {
            $both = $byNumber->intersect($byPage)->values();
            [$ids, $how] = match (true) {
                $both->isNotEmpty() => [$both, 'both'],
                $byNumber->isNotEmpty() && $byPage->isNotEmpty() => [collect(), 'conflict'],
                // the page found without the number: Monzavi's number is not the site's («ملی 46/1، ش 1079 - 1041»)
                default => $byNumber->isNotEmpty() ? [$byNumber, 'shelfmark'] : [$byPage, 'page-not-number'],
            };
        } else {
            [$ids, $how] = $byNumber !== null ? [$byNumber, 'shelfmark'] : [$byPage, 'page'];
        }
        if ($ids->count() !== 1) {
            return [null, $basis . ':' . ($how === 'conflict' ? 'conflict' : ($ids->isEmpty() ? 'none' : 'several'))];
        }

        return [$copies->firstWhere('id', $ids->first()), "{$basis}+{$how}"];
    }

    /**
     * The ids of the copies whose number is the holding's: its first number, and when the holding gives a part
     * («9421/1») and a copy has that very number, only those.
     */
    private function ofNumber(Collection $copies, string $number): Collection
    {
        $base = Holdings::base($number);
        $same = $copies->filter(fn (Manuscript $m) => Holdings::base($m->shelfmark) === $base);
        $exact = $same->filter(fn (Manuscript $m) => preg_replace('/\s+/u', '', Holdings::ascii((string) $m->shelfmark)) === $number);

        return ($exact->isNotEmpty() ? $exact : $same)->pluck('id')->values();
    }

    /** The ids of the copies whose Fankha citation of their catalogue is the holding's volume, at the page. */
    private function ofPage(Collection $copies, int $volume, int $page): Collection
    {
        $ids = $copies->pluck('id')->flip();

        return $this->citedAt($copies->pluck('library_id')->unique()->all(), $volume, $page)->filter(fn ($id) => isset($ids[$id]))->values();
    }

    /** The ids of the libraries' copies whose Fankha citation is the volume, at the page. */
    private function citedAt(array $libraries, int $volume, int $page): Collection
    {
        $ids = [];
        $this->loadCited();
        foreach ($libraries as $library) {
            foreach ($this->cited[$library][$volume] ?? [] as [$id, $start, $end]) {
                if ($start <= $page && $page <= $end) {
                    $ids[] = $id;
                }
            }
        }

        return collect($ids)->unique()->values();
    }

    /**
     * Every copy's Fankha citation, once a run: two plain queries, as a join of source_entries to manuscripts by the
     * library takes seconds a library.
     */
    private function loadCited(): void
    {
        if ($this->cited !== [] || $this->libraries === []) {
            return;
        }
        $wanted = array_flip(array_merge(...array_values($this->libraries)));
        $library = [];
        DB::table('manuscripts')->whereIn('library_id', array_keys($wanted))->select('id', 'library_id')->orderBy('id')
            ->chunk(50000, function ($rows) use (&$library) {
                foreach ($rows as $r) {
                    $library[$r->id] = (int) $r->library_id;
                }
            });
        DB::table('source_entries')->where('citable_type', Manuscript::class)->where('origin', SourceEntry::ORIGIN_FANKHA_CITATION)
            ->where('status', '!=', SourceEntry::STATUS_REJECTED)->whereNotNull('page_start')->whereNotNull('volume_number')
            ->select('id', 'citable_id', 'volume_number', 'page_start', 'page_end')->orderBy('id')
            ->chunk(50000, function ($rows) use ($library) {
                foreach ($rows as $r) {
                    if (isset($library[$r->citable_id])) {
                        $this->cited[$library[$r->citable_id]][(int) $r->volume_number][] = [(int) $r->citable_id, (int) $r->page_start, (int) ($r->page_end ?? $r->page_start)];
                    }
                }
            });
        $this->cited[0] = [];
    }

    /** The copies of the libraries with the holding's number, or cited at its catalogue page. */
    private function byNumberOrPage(array $libraries, array $holding): Collection
    {
        $ids = collect();
        if ($holding['shelfmark'] !== null) {
            foreach ($libraries as $library) {
                $ids = $ids->merge(DirectAnswer::byShelfmark($library, (string) Holdings::base($holding['shelfmark']))->pluck('id'));
            }
        }
        if ($holding['page'] !== null) {
            $ids = $ids->merge($this->citedAt($libraries, $holding['volume'], $holding['page']));
        }

        return Manuscript::whereIn('id', $ids->unique())->get(['id', 'work_id', 'library_id', 'shelfmark']);
    }
}
