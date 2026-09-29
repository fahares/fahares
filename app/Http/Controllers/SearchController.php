<?php

namespace App\Http\Controllers;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Script;
use App\Models\Subject;
use App\Models\Work;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Meilisearch\Client as MeiliClient;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $query = trim($request->input('q', ''));
        $type = $request->input('type', 'works');
        $scope = $request->input('scope', 'titles_names');
        if (!in_array($scope, ['titles', 'titles_names', 'all'])) {
            $scope = 'titles_names';
        }

        $subjectId = $request->input('subject_id');
        $libraryId = $request->input('library_id');
        $scriptId = $request->input('script_id');
        $century = $request->input('century');

        $validFlags = ['is_autograph', 'is_illuminated', 'is_illustrated', 'is_corrected', 'has_marginal_notes', 'is_collated'];
        $rawFlags = (array) $request->input('flags', []);
        if ($request->filled('flag')) {
            $rawFlags[] = $request->input('flag');
        }
        $flags = array_values(array_unique(array_intersect($rawFlags, $validFlags)));
        $flag = !empty($flags) ? $flags[0] : null;
        $perPage = 20;

        $results = null;

        $subjects = collect();
        $scripts = collect();
        $libraries = collect();
        $centuries = [];
        $flagCounts = [];
        $totalFacetManuscripts = 0;

        $selectedSubject = $subjectId ? Subject::find($subjectId) : null;
        $selectedLibrary = $libraryId ? Library::find($libraryId) : null;
        $selectedScript = $scriptId ? Script::find($scriptId) : null;

        if ($type === 'manuscripts') {
            // Determine attributes to search on based on scope
            $searchAttrs = null;
            if ($scope === 'titles') {
                $searchAttrs = ['work_title'];
            } elseif ($scope === 'titles_names') {
                $searchAttrs = ['work_title', 'author_name', 'scribe_name'];
            }

            // 1. Fetch dynamic faceted distribution for manuscripts
            $meiliClient = app(MeiliClient::class);
            $facetDistribution = [];

            if ($query !== '') {
                try {
                    $facetOptions = [
                        'matchingStrategy' => 'all',
                        'facets' => [
                            'library_id',
                            'scripts',
                            'copy_date_hijri_year',
                            'is_autograph',
                            'is_illuminated',
                            'is_illustrated',
                            'is_corrected',
                            'has_marginal_notes',
                            'is_collated',
                        ],
                        'limit' => 0,
                    ];
                    if ($searchAttrs) {
                        $facetOptions['attributesToSearchOn'] = $searchAttrs;
                    }

                    $facetRes = $meiliClient->index('manuscripts_index')->search($query, $facetOptions);
                    $facetDistribution = $facetRes->getFacetDistribution() ?? [];
                } catch (\Throwable $e) {
                    Log::warning('Meilisearch facet retrieval failed: ' . $e->getMessage());
                }

                // Process Libraries Facet
                $libCounts = $facetDistribution['library_id'] ?? [];
                $totalFacetManuscripts = array_sum($libCounts);

                if (!empty($libCounts)) {
                    $libIds = array_keys($libCounts);
                    $libraries = Library::whereIn('id', $libIds)->get()->map(function ($lib) use ($libCounts) {
                        $lib->matching_count = $libCounts[$lib->id] ?? 0;
                        return $lib;
                    })->sortByDesc('matching_count')->values();
                }

                // Process Scripts Facet
                $scriptCounts = $facetDistribution['scripts'] ?? [];
                if (!empty($scriptCounts)) {
                    $scriptNames = array_keys($scriptCounts);
                    $scripts = Script::whereIn('name', $scriptNames)->get()->map(function ($sc) use ($scriptCounts) {
                        $sc->matching_count = $scriptCounts[$sc->name] ?? 0;
                        return $sc;
                    })->sortByDesc('matching_count')->values();
                }

                // Process Centuries Facet
                $yearCounts = $facetDistribution['copy_date_hijri_year'] ?? [];
                foreach ($yearCounts as $yr => $cnt) {
                    $y = (int) $yr;
                    if ($y >= 100 && $y <= 1500) {
                        $c = (int) ceil($y / 100);
                        $centuries[$c] = ($centuries[$c] ?? 0) + $cnt;
                    }
                }
                ksort($centuries);

                // Process Flags Facet
                foreach ($validFlags as $vf) {
                    $flagCounts[$vf] = $facetDistribution[$vf]['true'] ?? 0;
                }
            } else {
                // Browsing without keyword: show top repositories and standard scripts
                $libraries = Library::where('manuscripts_count', '>', 0)
                    ->orderByDesc('manuscripts_count')
                    ->take(50)
                    ->get()
                    ->map(function ($lib) {
                        $lib->matching_count = $lib->manuscripts_count;
                        return $lib;
                    });
                $scripts = Script::whereIn('id', [1, 2, 5, 4, 9, 3, 11, 7, 8])->get();
                for ($c = 4; $c <= 14; $c++) {
                    $centuries[$c] = null;
                }
            }

            // Ensure currently selected entities remain in lists
            if ($selectedLibrary && !$libraries->contains('id', $selectedLibrary->id)) {
                $selectedLibrary->matching_count = 0;
                $libraries->prepend($selectedLibrary);
            }
            if ($selectedScript && !$scripts->contains('id', $selectedScript->id)) {
                $scripts->prepend($selectedScript);
            }

            // 2. Execute Search with filters applied directly in engine
            if ($query !== '') {
                $meiliFilters = [];
                if ($libraryId) {
                    $meiliFilters[] = 'library_id = ' . (int) $libraryId;
                }
                if ($century) {
                    $centuryStart = ($century - 1) * 100 + 1;
                    $centuryEnd = $century * 100;
                    $meiliFilters[] = "(copy_date_hijri_year >= {$centuryStart} AND copy_date_hijri_year <= {$centuryEnd})";
                }
                if ($selectedScript) {
                    $meiliFilters[] = 'scripts = "' . addslashes($selectedScript->name) . '"';
                }
                foreach ($flags as $f) {
                    $meiliFilters[] = "{$f} = true";
                }

                $filterString = !empty($meiliFilters) ? implode(' AND ', $meiliFilters) : null;

                $builder = Manuscript::search($query, function ($meili, $searchQuery, $options) use ($filterString, $searchAttrs) {
                    $options['matchingStrategy'] = 'all';
                    if ($searchAttrs) {
                        $options['attributesToSearchOn'] = $searchAttrs;
                    }
                    if ($filterString) {
                        $options['filter'] = $filterString;
                    }
                    return $meili->search($searchQuery, $options);
                })->query(fn($q) => $q->with(['catalog', 'work.author', 'libraryRecord', 'scripts']));

                $results = $builder->paginate($perPage)->withQueryString();
            } else {
                $builder = Manuscript::query()->with(['catalog', 'work.author', 'libraryRecord', 'scripts']);
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
                foreach ($flags as $f) {
                    $builder->where($f, true);
                }
                $results = $builder->orderBy('id')->paginate($perPage)->withQueryString();
            }
        } elseif ($type === 'people') {
            $peopleSearchAttrs = null;
            if ($scope === 'titles') {
                $peopleSearchAttrs = ['name'];
            } elseif ($scope === 'titles_names') {
                $peopleSearchAttrs = ['name', 'transliteration'];
            }

            if ($query !== '') {
                $results = Person::search($query, function ($meili, $searchQuery, $options) use ($peopleSearchAttrs) {
                    $options['matchingStrategy'] = 'all';
                    if ($peopleSearchAttrs) {
                        $options['attributesToSearchOn'] = $peopleSearchAttrs;
                    }
                    return $meili->search($searchQuery, $options);
                })
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

            $workSearchAttrs = null;
            if ($scope === 'titles') {
                $workSearchAttrs = ['primary_title', 'clean_title', 'alternative_titles'];
            } elseif ($scope === 'titles_names') {
                $workSearchAttrs = ['primary_title', 'clean_title', 'alternative_titles', 'author_name'];
            }

            // 1. Facet distribution for subjects
            if ($query !== '') {
                try {
                    $meiliClient = app(MeiliClient::class);
                    $facetOptions = [
                        'matchingStrategy' => 'all',
                        'facets' => ['subjects'],
                        'limit' => 0,
                    ];
                    if ($workSearchAttrs) {
                        $facetOptions['attributesToSearchOn'] = $workSearchAttrs;
                    }

                    $facetRes = $meiliClient->index('works_index')->search($query, $facetOptions);
                    $subjCounts = $facetRes->getFacetDistribution()['subjects'] ?? [];
                    if (!empty($subjCounts)) {
                        $subjects = Subject::whereIn('name', array_keys($subjCounts))->get()->map(function ($s) use ($subjCounts) {
                            $s->matching_count = $subjCounts[$s->name] ?? 0;
                            return $s;
                        })->sortByDesc('matching_count')->values();
                    } else {
                        $subjects = Subject::where('works_count', '>', 0)->orderByDesc('works_count')->take(30)->get();
                    }
                } catch (\Throwable $e) {
                    Log::warning('Meilisearch works facet failed: ' . $e->getMessage());
                    $subjects = Subject::where('works_count', '>', 0)->orderByDesc('works_count')->take(30)->get();
                }
            } else {
                $subjects = Subject::where('works_count', '>', 0)->orderByDesc('works_count')->take(30)->get();
            }

            if ($selectedSubject && !$subjects->contains('id', $selectedSubject->id)) {
                $subjects->prepend($selectedSubject);
            }

            // 2. Execute Works Search
            if ($query !== '') {
                $workFilter = null;
                if ($selectedSubject) {
                    $workFilter = 'subjects = "' . addslashes($selectedSubject->name) . '"';
                }

                $builder = Work::search($query, function ($meili, $searchQuery, $options) use ($workFilter, $workSearchAttrs) {
                    $options['matchingStrategy'] = 'all';
                    if ($workSearchAttrs) {
                        $options['attributesToSearchOn'] = $workSearchAttrs;
                    }
                    if ($workFilter) {
                        $options['filter'] = $workFilter;
                    }
                    return $meili->search($searchQuery, $options);
                })->query(fn($q) => $q->with(['catalog', 'author', 'subjects', 'languages'])->withCount('manuscripts'));

                $results = $builder->paginate($perPage)->withQueryString();
            } else {
                $builder = Work::query()->with(['catalog', 'author', 'subjects', 'languages'])->withCount('manuscripts');
                if ($subjectId) {
                    $builder->whereHas('subjects', fn($sq) => $sq->where('subjects.id', $subjectId));
                }
                $results = $builder->orderByDesc('manuscripts_count')->paginate($perPage)->withQueryString();
            }

            // Calculate manuscript century ranges and autograph status for all works on current page
            $workIds = $results->pluck('id')->filter()->all();
            if (!empty($workIds)) {
                $allYearsByWork = Manuscript::whereIn('work_id', $workIds)
                    ->whereNotNull('copy_date_hijri_year')
                    ->where('copy_date_hijri_year', '>=', 300)
                    ->where('copy_date_hijri_year', '<=', 1500)
                    ->select('work_id', 'copy_date_hijri_year')
                    ->distinct()
                    ->get()
                    ->groupBy('work_id');

                $autographWorkIds = Manuscript::whereIn('work_id', $workIds)
                    ->where('is_autograph', true)
                    ->pluck('work_id')
                    ->flip()
                    ->all();

                foreach ($results as $work) {
                    $work->has_autograph = isset($autographWorkIds[$work->id]);

                    $minAllowedYear = 300;
                    if ($work->composition_year_hijri) {
                        $minAllowedYear = max($minAllowedYear, $work->composition_year_hijri - 40);
                    } elseif ($work->author?->death_year_hijri) {
                        $minAllowedYear = max($minAllowedYear, $work->author->death_year_hijri - 90);
                    } elseif ($work->author?->century_hijri) {
                        $minAllowedYear = max($minAllowedYear, ($work->author->century_hijri - 2) * 100);
                    }

                    $years = ($allYearsByWork->get($work->id) ?? collect())
                        ->pluck('copy_date_hijri_year')
                        ->filter(fn($y) => $y >= $minAllowedYear);

                    if ($years->isNotEmpty()) {
                        $minYear = $years->min();
                        $maxYear = $years->max();
                        $minCentury = (int) ceil($minYear / 100);
                        $maxCentury = (int) ceil($maxYear / 100);
                        $work->copy_century_text = ($minCentury === $maxCentury)
                            ? "قرن {$minCentury} هـ.ق"
                            : "از قرن {$minCentury} تا {$maxCentury} هـ.ق";
                    } else {
                        $work->copy_century_text = null;
                    }
                }
            }
        }

        return view('search', compact(
            'results',
            'query',
            'type',
            'scope',
            'subjects',
            'scripts',
            'libraries',
            'centuries',
            'flagCounts',
            'totalFacetManuscripts',
            'subjectId',
            'libraryId',
            'scriptId',
            'century',
            'flag',
            'flags',
            'selectedSubject',
            'selectedLibrary',
            'selectedScript'
        ));
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
                    'url' => route('works.show', $w),
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
                    'url' => route('people.show', $p),
                ]);
        }

        if ($type === 'all' || $type === 'manuscripts') {
            $response['manuscripts'] = Manuscript::search($query)
                ->query(fn($q) => $q->with(['work', 'libraryRecord']))
                ->take(5)
                ->get()
                ->map(fn($m) => [
                    'id' => $m->id,
                    'work_title' => $m->work?->title ?? 'نسخه بدون عنوان',
                    'library' => $m->libraryRecord?->name ?? $m->library ?? 'کتابخانه نامشخص',
                    'accession_number' => $m->accession_number,
                    'url' => route('manuscripts.show', $m),
                ]);
        }

        return response()->json($response);
    }
}
