<?php

namespace App\Http\Controllers;

use App\Models\EntityRedirect;
use App\Models\Work;
use App\Support\RecordSources;
use Illuminate\Http\Request;

class WorkController extends Controller
{
    public function show(Request $request, $id)
    {
        $numericId = (int) $id;

        $work = Work::with([
            'catalog',
            'author',
            'authorEntries.person',
            'subjects',
            'languages',
            'referrals',
            'scholarlyAnnotations',
            ...RecordSources::RELATIONS,
        ])
        ->withCount('manuscripts')
        ->find($numericId);

        if (! $work) {
            $targetId = EntityRedirect::resolveTargetId('works', $numericId);
            $target = $targetId ? Work::find($targetId) : null;
            if ($target) {
                return redirect()->route('works.show', array_merge(['id' => $target->getRouteKey()], $request->query()), 301);
            }
            // A record removed as redundant (or a merged one that pointed at it)
            if ($gone = \App\Suggestions\RecordRemover::goneResponse(Work::class, $targetId ?: $numericId)) {
                return $gone;
            }
            abort(404);
        }

        $canonicalKey = (string) $work->getRouteKey();
        if ($id !== $canonicalKey && urldecode($id) !== $canonicalKey) {
            return redirect()->route('works.show', array_merge(['id' => $canonicalKey], $request->query()), 301);
        }

        $sort = $request->input('sort', 'sequence');
        $direction = strtolower($request->input('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $query = $work->manuscripts()->with(['catalog', 'libraryRecord', 'scripts', 'scribe']);

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
                // Catalog print order: addenda printed in later volumes follow the main entry's copies
                $query->orderBy('volume_number', $direction)
                      ->orderBy('page_start', $direction)
                      ->orderBy('sequence_number', $direction);
                break;
        }

        $manuscripts = $query->paginate(30)
            ->withQueryString()
            ->fragment('manuscripts');

        $earliest = $work->earliestDatedManuscript();

        return view('works.show', compact('work', 'manuscripts', 'sort', 'direction', 'earliest'));
    }
}
