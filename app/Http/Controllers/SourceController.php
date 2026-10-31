<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Models\SourceItem;
use App\Support\SourceShelf;
use Illuminate\Support\Facades\DB;

/**
 * «منابع و مراجع» (step 13.80): every source the site is made from or ties its records to, by kind (/sources), and one
 * source with its volumes, where to start reading its text or turning its page images (/sources/fankha).
 */
class SourceController extends Controller
{
    /** The kinds in the order the index shows them. */
    private const KINDS = [Source::KIND_BIBLIOGRAPHY, Source::KIND_BIOGRAPHICAL, Source::KIND_UNION_CATALOGUE, Source::KIND_LIBRARY_CATALOGUE, Source::KIND_OTHER];

    public function index()
    {
        $counts = SourceShelf::counts();
        $sources = Source::with(['library', 'volumes', 'editions'])->whereNull('edition_of_id')->orderBy('rank')->orderBy('title')->get()
            // a source the site has nothing of, in itself or an edition, is left out
            ->filter(fn (Source $s) => collect([$s, ...$s->editions])->contains(fn ($e) => array_sum($counts[$e->id] ?? []) > 0))
            ->groupBy('kind');
        $groups = collect(self::KINDS)->filter(fn ($k) => $sources->has($k))->mapWithKeys(fn ($k) => [$k => $sources[$k]]);

        return view('sources.index', ['groups' => $groups, 'counts' => $counts]);
    }

    public function show(string $code)
    {
        $source = Source::with(['library', 'volumes.catalogers', 'editionOf', 'editions'])->where('code', $code)->firstOrFail();
        $counts = SourceShelf::counts()[$source->id] ?? ['works' => 0, 'manuscripts' => 0, 'items' => 0, 'pages' => 0];
        $volumeIds = $source->volumes->pluck('id');
        $pages = DB::table('source_pages')->whereIn('source_volume_id', $volumeIds)->selectRaw('source_volume_id, count(*) n, min(page) first_page')
            ->groupBy('source_volume_id')->get()->keyBy('source_volume_id');
        $items = SourceItem::whereIn('source_volume_id', $volumeIds)->selectRaw('source_volume_id, count(*) n, min(page_start) first_page')
            ->groupBy('source_volume_id')->get()->keyBy('source_volume_id');
        $images = SourceShelf::hasImages($source);
        // the manuscripts each volume describes; Fankha's take seconds to count, so kept a day
        $copies = \Illuminate\Support\Facades\Cache::remember("sources:copies:{$source->id}", now()->addDay(), fn () => DB::table('source_entries')
            ->whereIn('source_volume_id', $volumeIds)->where('citable_type', \App\Models\Manuscript::class)
            ->where('status', '!=', \App\Models\SourceEntry::STATUS_REJECTED)->selectRaw('source_volume_id, count(distinct citable_id) n')
            ->groupBy('source_volume_id')->pluck('n', 'source_volume_id')->all());

        // each volume: its text's first page (the entries' first page when the volume is kept by entry) and its images
        $volumes = $source->volumes->map(fn ($v) => [
            'volume' => $v,
            'pages' => (int) ($pages[$v->id]->n ?? 0),
            'items' => (int) ($items[$v->id]->n ?? 0),
            'manuscripts' => (int) ($copies[$v->id] ?? 0),
            'toc' => $this->toc($source, $v),
            'read' => ($first = $pages[$v->id]->first_page ?? $items[$v->id]->first_page ?? null)
                ? route('source-pages.show', [$source->code, $v->volume_number, $first]) : null,
            'viewer' => $images && $v->facsimile_code ? ['catalog' => $v->facsimile_code, 'catalogName' => $source->short_name ?: $source->title,
                'volume' => $v->volume_number, 'page' => (int) ($v->page_first ?: $first ?: 1)] : null,
        ]);

        // a book in one volume has no list of volumes: its one volume's buttons and catalogers go in the header
        $single = $source->volumes_count === 1 && $volumes->count() === 1 ? $volumes->first() : null;

        return view('sources.show', compact('source', 'counts', 'volumes', 'images', 'single'));
    }

    /**
     * The volume's table of contents (sources:import-toc), each heading with its page text when the site has that
     * page: [{title, level, url}].
     */
    private function toc(Source $source, $volume): array
    {
        $toc = $volume->metadata['toc'] ?? [];
        if (! $toc) {
            return [];
        }
        $pages = DB::table('source_pages')->where('source_volume_id', $volume->id)->pluck('page')->flip();
        $range = $pages->isEmpty() ? SourceItem::where('source_volume_id', $volume->id)->selectRaw('min(page_start) a, max(page_end) b')->first() : null;
        $has = fn (int $p) => $range ? $range->a !== null && $p >= $range->a && $p <= $range->b : $pages->has($p);

        return array_map(fn ($h) => $h + ['url' => $has($h['page']) ? route('source-pages.show', [$source->code, $volume->volume_number, $h['page']]) : null], $toc);
    }
}
