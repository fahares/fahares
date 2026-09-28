<?php

namespace App\Http\Controllers;

use App\Models\Manuscript;
use Illuminate\Http\Request;

class ManuscriptController extends Controller
{
    public function show($id)
    {
        $manuscript = Manuscript::with([
            'catalog',
            'work.author',
            'work.subjects',
            'work.languages',
            'library',
            'scripts',
            'fieldSuggestions' => fn($q) => $q->where('status', 'approved'),
        ])->findOrFail($id);

        return view('manuscripts.show', compact('manuscript'));
    }
}
