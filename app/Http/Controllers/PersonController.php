<?php

namespace App\Http\Controllers;

use App\Models\EntityRedirect;
use App\Models\Person;
use Illuminate\Http\Request;

class PersonController extends Controller
{
    public function show(Request $request, $id)
    {
        $numericId = (int) $id;

        $person = Person::withCount(['works', 'scribedManuscripts'])->find($numericId);

        if (! $person) {
            $targetId = EntityRedirect::resolveTargetId('people', $numericId);
            if ($targetId) {
                $target = Person::find($targetId);
                if ($target) {
                    return redirect()->route('people.show', array_merge(['id' => $target->getRouteKey()], $request->query()), 301);
                }
            }
            abort(404);
        }

        $canonicalKey = (string) $person->getRouteKey();
        if ($id !== $canonicalKey && urldecode($id) !== $canonicalKey) {
            return redirect()->route('people.show', array_merge(['id' => $canonicalKey], $request->query()), 301);
        }

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
