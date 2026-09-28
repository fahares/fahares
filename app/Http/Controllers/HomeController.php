<?php

namespace App\Http\Controllers;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Subject;
use App\Models\Work;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index()
    {
        $stats = [
            'volumes_count' => 34,
            'works_count' => Work::count(),
            'manuscripts_count' => Manuscript::count(),
            'people_count' => Person::count(),
            'libraries_count' => Library::count(),
        ];

        // Direct indexed queries (< 1ms execution time)
        $topSubjects = Subject::orderByDesc('works_count')->take(8)->get();
        $topLibraries = Library::orderByDesc('manuscripts_count')->take(8)->get();

        return view('home', compact('stats', 'topSubjects', 'topLibraries'));
    }
}
