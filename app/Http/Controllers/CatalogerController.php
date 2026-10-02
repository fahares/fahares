<?php

namespace App\Http\Controllers;

use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Manuscript;
use Illuminate\Http\Request;

class CatalogerController extends Controller
{
    public function index(Request $request)
    {
        $search = trim($request->input('q', ''));
        $sort = $request->input('sort', 'manuscripts');

        $query = Cataloger::query();

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('nickname', 'like', "%{$search}%")
                  ->orWhere('bio', 'like', "%{$search}%");
            });
        }

        if ($sort === 'volumes') {
            $query->orderByDesc('volumes_count')->orderByDesc('manuscripts_count');
        } elseif ($sort === 'name') {
            $query->orderBy('name');
        } else {
            // Default: manuscripts
            $query->orderByDesc('manuscripts_count')->orderByDesc('volumes_count');
        }

        $catalogers = $query->paginate(24)->withQueryString();

        $stats = [
            'total_catalogers' => Cataloger::count(),
            'total_volumes' => CatalogVolume::count(),
            'total_manuscripts' => Manuscript::whereNotNull('cataloger_id')->count(),
        ];

        return view('catalogers.index', compact('catalogers', 'stats', 'search', 'sort'));
    }

    public function show(Request $request, $id)
    {
        $numericId = (int) $id;

        $cataloger = Cataloger::with(['person', 'user'])
            ->findOrFail($numericId);

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

        // Get manuscripts cataloged by this person
        $manuscripts = $cataloger->manuscripts()
            ->with(['work.author', 'libraryRecord', 'catalogVolume'])
            ->orderBy('volume_number')
            ->orderBy('page_start')
            ->paginate(24, ['*'], 'manuscripts_page');

        return view('catalogers.show', compact('cataloger', 'volumes', 'manuscripts'));
    }
}
