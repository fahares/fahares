<?php

namespace App\Http\Controllers;

use App\Models\EntityRedirect;
use App\Models\Subject;
use App\Models\Work;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SubjectController extends Controller
{
    /**
     * Display the hierarchical catalog of all subjects.
     */
    public function index(Request $request)
    {
        $search = trim($request->input('q', ''));

        $query = Subject::whereNull('parent_id')
            ->with(['children' => fn($q) => $q->where('works_count', '>', 0)->orderByDesc('works_count')])
            ->where('works_count', '>', 0);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhereHas('children', function ($cq) use ($search) {
                      $cq->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $rootSubjects = $query->orderByDesc('works_count')->get();

        $totalRootSubjects = Subject::whereNull('parent_id')->count();
        $totalSubSubjects = Subject::whereNotNull('parent_id')->count();
        $totalWorks = Work::has('subjects')->count();

        return view('subjects.index', compact(
            'rootSubjects',
            'totalRootSubjects',
            'totalSubSubjects',
            'totalWorks',
            'search'
        ));
    }

    /**
     * Display a specific subject profile, its statistics, subcategories and works.
     */
    public function show(Request $request, $id)
    {
        $numericId = (int) $id;

        $subject = Subject::with([
            'parent',
            'children' => fn($q) => $q->where('works_count', '>', 0)->orderByDesc('works_count')
        ])->find($numericId);

        if (! $subject) {
            $targetId = EntityRedirect::resolveTargetId('subjects', $numericId);
            if ($targetId) {
                $target = Subject::find($targetId);
                if ($target) {
                    return redirect()->route('subjects.show', array_merge(['id' => $target->getRouteKey()], $request->query()), 301);
                }
            }
            abort(404);
        }

        $canonicalKey = (string) $subject->getRouteKey();
        if ($id !== $canonicalKey && urldecode($id) !== $canonicalKey) {
            return redirect()->route('subjects.show', array_merge(['id' => $canonicalKey], $request->query()), 301);
        }

        $hasChildren = $subject->children->isNotEmpty();
        $scope = $request->input('scope', 'cumulative');
        $isCumulative = ($scope !== 'direct') && $hasChildren;

        if ($isCumulative) {
            $subjectIds = array_merge([$subject->id], $subject->children->pluck('id')->toArray());
        } else {
            $subjectIds = [$subject->id];
        }

        $search = trim($request->input('q', ''));
        $sort = $request->input('sort', 'copies');

        $worksQuery = Work::query()
            ->whereHas('subjects', fn($q) => $q->whereIn('subjects.id', $subjectIds))
            ->with(['catalog', 'author', 'subjects', 'languages']);

        if ($search !== '') {
            $worksQuery->where(function ($q) use ($search) {
                $q->where('primary_title', 'like', "%{$search}%")
                  ->orWhere('clean_title', 'like', "%{$search}%")
                  ->orWhere('author_name', 'like', "%{$search}%");
            });
        }

        switch ($sort) {
            case 'title':
                $worksQuery->orderBy('primary_title');
                break;
            case 'date':
                $worksQuery->orderByRaw('ISNULL(composition_year_hijri) ASC, composition_year_hijri ASC');
                break;
            case 'copies':
            default:
                $sort = 'copies';
                $worksQuery->orderByDesc('manuscripts_count');
                break;
        }

        $works = $worksQuery->paginate(24)->withQueryString();

        // Calculate quick aggregate stats
        $totalManuscripts = Work::whereHas('subjects', fn($q) => $q->whereIn('subjects.id', $subjectIds))
            ->sum('manuscripts_count');

        // Top languages in this subject
        $topLanguages = DB::table('languages')
            ->join('language_work', 'languages.id', '=', 'language_work.language_id')
            ->join('subject_work', 'language_work.work_id', '=', 'subject_work.work_id')
            ->whereIn('subject_work.subject_id', $subjectIds)
            ->select('languages.name', DB::raw('count(distinct language_work.work_id) as count'))
            ->groupBy('languages.id', 'languages.name')
            ->orderByDesc('count')
            ->limit(4)
            ->get();

        return view('subjects.show', compact(
            'subject',
            'works',
            'hasChildren',
            'isCumulative',
            'scope',
            'search',
            'sort',
            'totalManuscripts',
            'topLanguages'
        ));
    }
}
