<?php

namespace App\Http\Controllers;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Script;
use App\Models\Subject;
use App\Models\Work;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $query = trim($request->input('q', ''));
        $type = $request->input('type', 'works');
        $subjectId = $request->input('subject_id');
        $libraryId = $request->input('library_id');
        $scriptId = $request->input('script_id');
        $century = $request->input('century');
        $flag = $request->input('flag');
        $perPage = 20;

        $results = null;

        $validFlags = ['is_autograph', 'is_illuminated', 'is_illustrated', 'is_corrected', 'has_marginal_notes', 'is_collated'];

        if ($type === 'manuscripts') {
            if ($query !== '') {
                $builder = Manuscript::search($query)
                    ->query(function ($q) use ($libraryId, $scriptId, $century, $flag, $validFlags) {
                        $q->with(['work.author', 'library', 'scripts']);
                        if ($libraryId) {
                            $q->where('library_id', $libraryId);
                        }
                        if ($century) {
                            $centuryStart = ($century - 1) * 100 + 1;
                            $centuryEnd = $century * 100;
                            $q->whereBetween('copy_date_hijri_year', [$centuryStart, $centuryEnd]);
                        }
                        if ($scriptId) {
                            $q->whereHas('scripts', fn($sq) => $sq->where('scripts.id', $scriptId));
                        }
                        if ($flag && in_array($flag, $validFlags)) {
                            $q->where($flag, true);
                        }
                    });
                $results = $builder->paginate($perPage)->withQueryString();
            } else {
                $builder = Manuscript::query()->with(['work.author', 'library', 'scripts']);
                if ($libraryId) {
                    $builder->where('library_id', $libraryId);
                }
                if ($century) {
                    $centuryStart = ($century - 1) * 100 + 1;
                    $centuryEnd = $century * 100;
                    $builder->whereBetween('copy_date_hijri_year', [$centuryStart, $centuryEnd]);
                }
                if ($scriptId) {
                    $builder->whereHas('scripts', fn($sq) => $sq->where('scripts.id', $scriptId));
                }
                if ($flag && in_array($flag, $validFlags)) {
                    $builder->where($flag, true);
                }
                $results = $builder->orderBy('id')->paginate($perPage)->withQueryString();
            }
        } elseif ($type === 'people') {
            if ($query !== '') {
                $results = Person::search($query)
                    ->query(fn($q) => $q->withCount(['works', 'scribedManuscripts']))
                    ->paginate($perPage)
                    ->withQueryString();
            } else {
                $results = Person::query()
                    ->withCount(['works', 'scribedManuscripts'])
                    ->orderByDesc('works_count')
                    ->paginate($perPage)
                    ->withQueryString();
            }
        } else {
            // Default: works
            $type = 'works';
            if ($query !== '') {
                $builder = Work::search($query)
                    ->query(function ($q) use ($subjectId) {
                        $q->with(['author', 'subjects', 'languages'])->withCount('manuscripts');
                        if ($subjectId) {
                            $q->whereHas('subjects', fn($sq) => $sq->where('subjects.id', $subjectId));
                        }
                    });
                $results = $builder->paginate($perPage)->withQueryString();
            } else {
                $builder = Work::query()->with(['author', 'subjects', 'languages'])->withCount('manuscripts');
                if ($subjectId) {
                    $builder->whereHas('subjects', fn($sq) => $sq->where('subjects.id', $subjectId));
                }
                $results = $builder->orderByDesc('manuscripts_count')->paginate($perPage)->withQueryString();
            }
        }

        // Filter metadata
        $subjects = Subject::orderBy('name')->get();
        $scripts = Script::orderBy('name')->get();
        $libraries = Library::whereHas('manuscripts')->orderBy('name')->take(50)->get();

        return view('search', compact('results', 'query', 'type', 'subjects', 'scripts', 'libraries', 'subjectId', 'libraryId', 'scriptId', 'century', 'flag'));
    }

    /**
     * Fast JSON API for Live Instant Search Autocomplete
     */
    public function api(Request $request)
    {
        $query = trim($request->input('q', ''));
        $type = $request->input('type', 'all');

        if ($query === '' || mb_strlen($query) < 2) {
            return response()->json([
                'works' => [],
                'people' => [],
                'manuscripts' => [],
            ]);
        }

        $response = [];

        if ($type === 'all' || $type === 'works') {
            $response['works'] = Work::search($query)
                ->query(fn($q) => $q->with('author')->withCount('manuscripts'))
                ->take(5)
                ->get()
                ->map(fn($w) => [
                    'id' => $w->id,
                    'title' => $w->title,
                    'author' => $w->author?->name ?? 'ناشناخته',
                    'manuscripts_count' => $w->manuscripts_count,
                    'url' => route('works.show', $w->id),
                ]);
        }

        if ($type === 'all' || $type === 'people') {
            $response['people'] = Person::search($query)
                ->query(fn($q) => $q->withCount('works'))
                ->take(5)
                ->get()
                ->map(fn($p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'death_hijri' => $p->death_year_hijri ? "متوفای {$p->death_year_hijri} ق" : ($p->death_century_hijri ? "قرن {$p->death_century_hijri} ق" : null),
                    'works_count' => $p->works_count,
                    'url' => route('people.show', $p->id),
                ]);
        }

        if ($type === 'all' || $type === 'manuscripts') {
            $response['manuscripts'] = Manuscript::search($query)
                ->query(fn($q) => $q->with(['work', 'library']))
                ->take(5)
                ->get()
                ->map(fn($m) => [
                    'id' => $m->id,
                    'work_title' => $m->work?->title ?? 'نسخه بدون عنوان',
                    'library' => $m->library?->name ?? 'کتابخانه نامشخص',
                    'accession_number' => $m->accession_number,
                    'url' => route('manuscripts.show', $m->id),
                ]);
        }

        return response()->json($response);
    }
}
