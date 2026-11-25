<?php

namespace App\Support;

use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Work;
use Illuminate\Support\Collection;

/**
 * The work in the printed catalogues of its copies that introduce the work itself (step 14.378,
 * config('catalogs.describe_works')): one card for each catalogue, on the page of the work's first entry there, where
 * the catalogue usually introduces it, with the other pages its copies are on. Only pages the reader can see: the
 * entries of the copies that open a page image (SourceEntry::viewer()), not rejected.
 */
class WorkCatalogues
{
    /**
     * @return Collection<int, array{entry: SourceEntry, others: Collection<int, SourceEntry>, copies: int}>
     */
    public static function for(Work $work): Collection
    {
        $codes = config('catalogs.describe_works', []);
        if (! $codes) {
            return collect();
        }
        $sources = Source::whereIn('code', $codes)->pluck('id');
        if ($sources->isEmpty()) {
            return collect();
        }

        $entries = SourceEntry::with(['source', 'sourceVolume'])
            ->where('citable_type', Manuscript::class)
            ->whereIn('citable_id', Manuscript::where('work_id', $work->id)->select('id'))
            ->whereIn('source_id', $sources)
            ->where('status', '!=', SourceEntry::STATUS_REJECTED)
            ->whereNotNull('page_start')
            ->get()
            ->filter(fn (SourceEntry $e) => $e->viewer() !== null);

        return $entries->groupBy('source_id')
            ->map(function (Collection $group) {
                // one card for each page: two copies of the work on one page are one place
                $pages = $group->sortBy(fn (SourceEntry $e) => [$e->sourceVolume?->volume_number ?? $e->volume_number ?? 0, $e->page_start, $e->id])
                    ->unique(fn (SourceEntry $e) => $e->source_volume_id . ':' . $e->page_start)->values();

                return ['entry' => $pages->first(), 'others' => $pages->slice(1)->values(), 'copies' => $group->pluck('citable_id')->unique()->count()];
            })
            ->sortBy(fn (array $g) => [$g['entry']->source?->rank ?? Source::KIND_RANKS[Source::KIND_OTHER], $g['entry']->source_id])
            ->values();
    }
}
