<?php

namespace App\Http\Controllers;

use App\Helpers\TextNormalizer;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Support\SourceShelf;
use App\Models\Work;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * «منابع و مراجع» (step 13.80): every source the site is made from or ties its records to, by kind (/sources), and one
 * source with its volumes, where to start reading its text or turning its page images (/sources/fankha).
 */
class SourceController extends Controller
{
    /** The kinds in the order the index shows them; a union catalogue (Fankha) is one of the libraries' catalogues. */
    private const KINDS = [Source::KIND_BIBLIOGRAPHY, Source::KIND_BIOGRAPHICAL, Source::KIND_LIBRARY_CATALOGUE, Source::KIND_OTHER];

    public function index()
    {
        $counts = SourceShelf::counts();
        $sources = Source::with(['library', 'volumes', 'editions'])->whereNull('edition_of_id')->orderBy('rank')->orderBy('title')->get()
            // a source the site has nothing of, in itself or an edition, is left out
            ->filter(fn (Source $s) => collect([$s, ...$s->editions])->contains(fn ($e) => array_sum($counts[$e->id] ?? []) > 0))
            ->groupBy(fn (Source $s) => $s->kind === Source::KIND_UNION_CATALOGUE ? Source::KIND_LIBRARY_CATALOGUE : $s->kind);
        $groups = collect(self::KINDS)->filter(fn ($k) => $sources->has($k))->mapWithKeys(fn ($k) => [$k => $sources[$k]]);

        return view('sources.index', ['groups' => $groups, 'counts' => $counts]);
    }

    public function show(Request $request, string $code)
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

        $linked = $this->linked($request, $source, $counts, $volumes);

        return view('sources.show', compact('source', 'counts', 'volumes', 'images', 'single', 'linked'));
    }

    /**
     * The site's works or manuscripts this source is cited for, as its entries in the book's order (?list=works|
     * manuscripts), narrowed by a word of the title (?q=) and a volume (?volume=): {type, types, q, volume, rows}, or
     * null when the source is cited for none.
     */
    private function linked(Request $request, Source $source, array $counts, $volumes): ?array
    {
        $types = array_values(array_filter(['manuscripts', 'works'], fn ($t) => $counts[$t] > 0));
        if (! $types) {
            return null;
        }
        $type = in_array($request->query('list'), $types, true) ? $request->query('list') : $types[0];
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 100));
        $volume = ctype_digit((string) $request->query('volume')) ? (int) $request->query('volume') : null;
        $volume = $volume && $volumes->contains(fn ($r) => $r['volume']->volume_number === $volume) ? $volume : null;

        $query = SourceEntry::where('source_id', $source->id)->where('citable_type', $type === 'works' ? Work::class : Manuscript::class)
            ->where('status', '!=', SourceEntry::STATUS_REJECTED)
            ->when($volume, fn ($e) => $e->where('volume_number', $volume));
        if ($q !== '') {
            // the titles keep the Arabic and the Persian letters as written: both sides are brought to the Persian ones
            $title = 'primary_title';
            foreach (['ي' => 'ی', 'ى' => 'ی', 'ئ' => 'ی', 'ك' => 'ک', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ؤ' => 'و', "\u{200c}" => ' '] as $from => $to) {
                $title = "replace({$title}, '{$from}', '{$to}')";
            }
            // the works found first, so few of them are not looked for through all of a large source's entries
            $titled = Work::whereRaw("{$title} like ?", ['%' . addcslashes(TextNormalizer::normalize($q), '%_\\') . '%'])->pluck('id')->all();
            $query->whereIn('citable_id', $type === 'works' ? $titled : Manuscript::whereIn('work_id', $titled)->select('id'));
        }
        // Fankha's entries take a second or more to count, so the count is kept a day
        $total = Cache::remember('sources:linked:' . md5(implode('|', [$source->id, $type, $volume, $q])), now()->addDay(), fn () => (clone $query)->count());
        $rows = $query->with($type === 'works' ? ['citable.author'] : ['citable.work.author', 'citable.libraryRecord'])
            ->orderBy('volume_number')->orderBy('page_start')->orderBy('id')
            ->paginate(30, ['*'], 'page', null, $total)->withQueryString()->fragment('linked');

        return compact('type', 'types', 'q', 'volume', 'rows');
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
