<?php

namespace App\Http\Controllers;

use App\Models\Work;
use Illuminate\Http\Request;

class WorkController extends Controller
{
    public function show(Request $request, $id)
    {
        $work = Work::with([
            'catalog',
            'author',
            'subjects',
            'languages',
            'referrals',
            'scholarlyAnnotations',
        ])
        ->withCount('manuscripts')
        ->findOrFail($id);

        $sort = $request->input('sort', 'sequence');
        $direction = strtolower($request->input('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $query = $work->manuscripts()->with(['catalog', 'library', 'scripts']);

        switch ($sort) {
            case 'library':
                $query->orderByRaw("ISNULL(library) ASC, library {$direction}")
                      ->orderByRaw("ISNULL(city) ASC, city {$direction}")
                      ->orderBy('shelfmark_key');
                break;
            case 'shelfmark':
                $query->orderByRaw("ISNULL(shelfmark) ASC, shelfmark_key {$direction}")
                      ->orderBy('sequence_number');
                break;
            case 'scribe':
                $query->orderByRaw("ISNULL(scribe_name) ASC, scribe_name = '' ASC, scribe_name {$direction}")
                      ->orderBy('sequence_number');
                break;
            case 'date':
                $query->orderByRaw("ISNULL(copy_date_hijri_year) ASC, copy_date_hijri_year {$direction}")
                      ->orderBy('sequence_number');
                break;
            case 'script':
                $query->orderByRaw("ISNULL(script_names) ASC, script_names {$direction}")
                      ->orderBy('sequence_number');
                break;
            case 'folios':
                $query->orderByRaw("ISNULL(folios) ASC, folios {$direction}")
                      ->orderBy('sequence_number');
                break;
            case 'sequence':
            default:
                $sort = 'sequence';
                $query->orderBy('sequence_number', $direction);
                break;
        }

        $manuscripts = $query->paginate(30)
            ->withQueryString()
            ->fragment('manuscripts');

        return view('works.show', compact('work', 'manuscripts', 'sort', 'direction'));
    }
}
