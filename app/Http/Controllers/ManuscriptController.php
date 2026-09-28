<?php

namespace App\Http\Controllers;

use App\Models\Manuscript;
use Illuminate\Http\Request;

class ManuscriptController extends Controller
{
    public function show(Request $request, $id)
    {
        $numericId = (int) $id;

        $manuscript = Manuscript::with([
            'catalog',
            'work.author',
            'work.subjects',
            'work.languages',
            'libraryRecord',
            'scripts',
            'fieldSuggestions' => fn($q) => $q->where('status', 'approved'),
        ])->findOrFail($numericId);

        $canonicalKey = (string) $manuscript->getRouteKey();
        if ($id !== $canonicalKey && urldecode($id) !== $canonicalKey) {
            return redirect()->route('manuscripts.show', array_merge(['id' => $canonicalKey], $request->query()), 301);
        }

        return view('manuscripts.show', compact('manuscript'));
    }
}
