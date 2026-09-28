<?php

namespace App\Http\Controllers;

use App\Models\Work;
use Illuminate\Http\Request;

class WorkController extends Controller
{
    public function show($id)
    {
        $work = Work::with([
            'author',
            'subjects',
            'languages',
            'referrals',
            'scholarlyAnnotations',
        ])
        ->withCount('manuscripts')
        ->findOrFail($id);

        $manuscripts = $work->manuscripts()
            ->with(['library', 'scripts'])
            ->orderBy('sequence_number')
            ->paginate(30);

        return view('works.show', compact('work', 'manuscripts'));
    }
}
