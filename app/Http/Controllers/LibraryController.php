<?php

namespace App\Http\Controllers;

use App\Models\EntityRedirect;
use App\Models\Library;
use Illuminate\Http\Request;

class LibraryController extends Controller
{
    public function index(Request $request)
    {
        $search = trim($request->input('q', ''));
        $city = $request->input('city');

        $query = Library::withCount('manuscripts');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('full_name', 'like', "%{$search}%")
                  ->orWhere('aliases', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%")
                  ->orWhere('country', 'like', "%{$search}%");
            });
        }

        if ($city) {
            $query->where('city', $city);
        }

        $libraries = $query->orderByDesc('manuscripts_count')->paginate(30)->withQueryString();

        $cities = Library::select('city')
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->orderBy('city')
            ->pluck('city');

        return view('libraries.index', compact('libraries', 'cities', 'search', 'city'));
    }

    public function show(Request $request, $id)
    {
        $numericId = (int) $id;

        $library = Library::withCount('manuscripts')->find($numericId);
        if (! $library) {
            // A merged duplicate points at the library it was folded into
            $target = ($targetId = EntityRedirect::resolveTargetId('libraries', $numericId)) ? Library::find($targetId) : null;
            abort_unless($target, 404);

            return redirect()->route('libraries.show', array_merge(['id' => $target->getRouteKey()], $request->query()), 301);
        }

        $canonicalKey = (string) $library->getRouteKey();
        if ($id !== $canonicalKey && urldecode($id) !== $canonicalKey) {
            return redirect()->route('libraries.show', array_merge(['id' => $canonicalKey], $request->query()), 301);
        }

        $manuscripts = $library->manuscripts()
            ->with(['work.author', 'scripts'])
            // By number, not as text: 2 before 10, and the parts of a bound volume (1189/2) after it
            ->orderByRaw("shelfmark_key IS NULL, shelfmark_key REGEXP '^[0-9]' DESC, CAST(shelfmark_key AS UNSIGNED), shelfmark_key")
            ->paginate(30);

        // the library's printed catalogues, each a source with its own page (step 13.80)
        $catalogues = \App\Models\Source::where('library_id', $library->id)->whereNull('edition_of_id')->with('editions')->orderBy('title')->get();

        // its catalogues printed as parts of a volume that holds several libraries' («نشریه» 5, pp. 8–107), step 14.50
        $parts = collect();
        if (\App\Models\CatalogVolumePart::ready()) {
            $parts = \App\Models\CatalogVolumePart::with('catalogers:id,name')->where('library_id', $library->id)->get();
            $volumes = \App\Models\SourceVolume::with('source:id,code,title')->whereIn('id', $parts->pluck('catalog_volume_id'))->get()->keyBy('id');
            $parts = $parts->filter(fn ($p) => $volumes[$p->catalog_volume_id]?->source)
                ->each(fn ($p) => $p->setRelation('sourceVolume', $volumes[$p->catalog_volume_id]))
                ->sortBy(fn ($p) => [$p->sourceVolume->source->title, $p->sourceVolume->volume_number ?? 0, $p->sort])->values();
        }

        return view('libraries.show', compact('library', 'manuscripts', 'catalogues', 'parts'));
    }
}
