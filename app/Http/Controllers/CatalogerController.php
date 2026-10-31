<?php

namespace App\Http\Controllers;

use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\EntityRedirect;
use App\Models\Library;
use App\Models\Manuscript;
use Illuminate\Http\Request;

class CatalogerController extends Controller
{
    public function index(Request $request)
    {
        $search = trim($request->input('q', ''));
        $sort = $request->input('sort', 'birth_year');

        $query = Cataloger::query();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('bio', 'like', "%{$search}%");
            });
        }

        if ($sort === 'volumes') {
            $query->orderByDesc('volumes_count')->orderByDesc('manuscripts_count');
        } elseif ($sort === 'manuscripts') {
            $query->orderByDesc('manuscripts_count')->orderByDesc('volumes_count');
        } elseif ($sort === 'name') {
            $query->orderBy('name');
        } else {
            // Default: birth_year (oldest first, nulls last)
            $query->orderByRaw('birth_year_solar IS NULL, birth_year_solar ASC')
                  ->orderByDesc('manuscripts_count')
                  ->orderBy('name');
        }

        $catalogers = $query->paginate(30)->withQueryString();

        $stats = [
            'total_catalogers' => Cataloger::count(),
            'total_volumes' => CatalogVolume::whereNull('edition_of_id')->count(),
            'total_manuscripts' => Manuscript::whereNotNull('cataloger_id')->count(),
        ];

        return view('catalogers.index', compact('catalogers', 'stats', 'search', 'sort'));
    }

    public function show(Request $request, $id)
    {
        $numericId = (int) $id;

        $cataloger = Cataloger::with(['person', 'user'])->find($numericId);
        if (! $cataloger) {
            // A removed record that was an institution, not a cataloger, points at its library
            $library = ($libraryId = EntityRedirect::resolveTargetId('cataloger_library', $numericId)) ? Library::find($libraryId) : null;
            abort_unless($library, 404);

            return redirect()->route('libraries.show', ['id' => $library->getRouteKey()], 301);
        }

        $canonicalKey = (string) $cataloger->getRouteKey();
        if ($id !== $canonicalKey && urldecode($id) !== $canonicalKey) {
            return redirect()->route('catalogers.show', array_merge(['id' => $canonicalKey], $request->query()), 301);
        }

        // Get volumes authored by this cataloger
        $volumes = $cataloger->catalogVolumes()
            ->with(['library'])
            ->orderBy('library_id')
            ->orderBy('volume_number')
            ->get();

        // the volumes by the printed catalogue they are of, each catalogue once with its cover and page (step 13.109).
        // A source volume shares its catalog volume's id; a catalogue the registry holds as several sources (a source
        // for each volume of Golpayegani's, «… - جلد اول: آ»; Marashi's twice, under two spellings) is one, by its
        // library and its title without the volume
        $sourceVolumes = \App\Models\SourceVolume::with('source')->whereIn('id', $volumes->pluck('id'))->get()->keyBy('id');
        // «… - جلد اول: آ - ج» (the volume, then its letters), «… - 2» (a number), or no volume in the title at all
        $split = fn ($v) => preg_match('/^(.*?)\s*-\s*جلد\s*(.+)$/u', $v->title, $m) || preg_match('/^(.*?)\s*-\s*([0-9۰-۹]+)$/u', $v->title, $m)
            ? [trim($m[1]), trim($m[2])] : [trim($v->title), $v->volume_number ? (string) $v->volume_number : null];
        $base = fn ($v) => $v->library_id . '|' . trim(preg_replace(['/(?:حضرت|آیة ?الله|آیت ?الله)\s*/u', '/\s+/u'], ['', ' '],
            strtr($split($v)[0], ['ي' => 'ی', 'ك' => 'ک', "\u{200C}" => ' '])));
        // «اول» of «اول: آ - ج»
        $label = fn ($v) => ($l = $split($v)[1]) === null ? null : trim(explode(':', $l)[0]);
        $catalogues = $volumes->groupBy($base)->map(function ($group) use ($sourceVolumes, $label) {
            $sources = $group->map(fn ($v) => $sourceVolumes->get($v->id)?->source)->filter()->unique('id');
            // the page to open: a source with its cover, else the one with most of these volumes
            $source = $sources->first(fn ($s) => $s->coverUrl())
                ?? $sources->sortByDesc(fn ($s) => $group->filter(fn ($v) => $sourceVolumes->get($v->id)?->source_id === $s->id)->count())->first();

            return [
                'source' => $source ?? new \App\Models\Source(['title' => $group->first()->title, 'code' => '']),
                'linked' => $source !== null,
                'volumes' => $group->sortBy('volume_number')->values(),
                'labels' => $group->sortBy('volume_number')->map($label)->filter()->unique()->values(),
            ];
        })->values();

        // Get manuscripts cataloged by this person (either direct cataloger_id or via authored catalog volumes)
        $volumeIds = $cataloger->catalogVolumes()->pluck('catalog_volumes.id');
        $manuscripts = Manuscript::where(function ($q) use ($cataloger, $volumeIds) {
            $q->where('cataloger_id', $cataloger->id)
              ->orWhereIn('catalog_volume_id', $volumeIds);
        })
            ->with(['work.author', 'libraryRecord', 'catalogVolume'])
            ->orderBy('volume_number')
            ->orderBy('page_start')
            ->paginate(24, ['*'], 'manuscripts_page');

        return view('catalogers.show', compact('cataloger', 'volumes', 'catalogues', 'manuscripts'));
    }
}
