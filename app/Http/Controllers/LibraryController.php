<?php

namespace App\Http\Controllers;

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

    public function show($id)
    {
        $library = Library::withCount('manuscripts')->findOrFail($id);

        $manuscripts = $library->manuscripts()
            ->with(['work.author', 'scripts'])
            ->orderBy('shelfmark')
            ->paginate(30);

        return view('libraries.show', compact('library', 'manuscripts'));
    }
}
