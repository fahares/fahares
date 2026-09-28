<?php

namespace App\Http\Controllers;

use App\Models\Person;
use Illuminate\Http\Request;

class PersonController extends Controller
{
    public function show($id)
    {
        $person = Person::withCount(['works', 'scribedManuscripts'])->findOrFail($id);

        $authoredWorks = $person->works()
            ->with(['subjects', 'languages', 'catalog'])
            ->withCount('manuscripts')
            ->orderByDesc('manuscripts_count')
            ->paginate(20, ['*'], 'works_page');

        $scribedManuscripts = $person->scribedManuscripts()
            ->with(['work', 'library', 'scripts', 'catalog'])
            ->paginate(20, ['*'], 'manuscripts_page');

        return view('people.show', compact('person', 'authoredWorks', 'scribedManuscripts'));
    }
}
