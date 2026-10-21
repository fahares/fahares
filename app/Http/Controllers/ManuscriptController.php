<?php

namespace App\Http\Controllers;

use App\Models\EntityRedirect;
use App\Models\Manuscript;
use App\Models\ManuscriptCatalogReference;
use Illuminate\Http\Request;

class ManuscriptController extends Controller
{
    public function show(Request $request, $id)
    {
        $numericId = (int) $id;

        $manuscript = Manuscript::with([
            'catalog',
            'work.author',
            'scribe',
            'scribeEntries.person',
            'work.subjects',
            'work.languages',
            'libraryRecord',
            'scripts',
            'sourceCitations.catalog',
            // Pages in other printings of the library catalogue; the Fankha row repeats metadata.catalog_citation
            'catalogReferences' => fn ($q) => $q->where('source', '!=', ManuscriptCatalogReference::SOURCE_FANKHA)->where('status', '!=', ManuscriptCatalogReference::STATUS_REJECTED)->with('catalogVolume')->orderBy('id'),
            'fieldSuggestions' => fn($q) => $q->where('status', 'approved'),
        ])->find($numericId);

        if (! $manuscript) {
            $targetId = EntityRedirect::resolveTargetId('manuscripts', $numericId);
            $target = $targetId ? Manuscript::find($targetId) : null;
            if ($target) {
                return redirect()->route('manuscripts.show', array_merge(['id' => $target->getRouteKey()], $request->query()), 301);
            }
            // A record removed as redundant (or a merged one that pointed at it)
            if ($gone = \App\Suggestions\RecordRemover::goneResponse(Manuscript::class, $targetId ?: $numericId)) {
                return $gone;
            }
            abort(404);
        }

        $canonicalKey = (string) $manuscript->getRouteKey();
        if ($id !== $canonicalKey && urldecode($id) !== $canonicalKey) {
            return redirect()->route('manuscripts.show', array_merge(['id' => $canonicalKey], $request->query()), 301);
        }

        return view('manuscripts.show', compact('manuscript'));
    }
}
