<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Script;
use App\Models\SearchLog;
use App\Helpers\TextNormalizer;
use App\Models\Subject;
use App\Models\Work;
use App\Support\CopyPlaces;
use App\Support\DirectAnswer;
use App\Support\SearchExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Meilisearch\Client as MeiliClient;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $data = $this->search($request);

        return is_array($data) ? view('search', $data) : $data;
    }

    /**
     * The results of the search page and everything the page shows beside them, or a redirect to the one
     * manuscript a search names. An export (backlog item 83, phase 4) asks for one large page of the same results.
     */
    protected function search(Request $request, ?int $exportLimit = null): array|\Illuminate\Http\RedirectResponse
    {
        $rawQuery = trim($request->input('q', ''));
        $searchQuery = TextNormalizer::normalize($rawQuery);
        $query = $rawQuery;
        $type = $request->input('type', 'works');
        $scope = $request->input('scope', 'titles_names');
        if (!in_array($scope, ['titles', 'titles_names', 'all'])) {
            $scope = 'titles_names';
        }

        $subjectId = $request->input('subject_id');
        $libraryId = $request->input('library_id');
        $scriptId = $request->input('script_id');
        $century = $request->input('century');
        // City of copying (config/insights.php copy_places), linked from the insights map
        $copyCity = CopyPlaces::isCity($request->input('copy_city')) ? $request->input('copy_city') : null;
        $languageId = $request->input('language_id');
        $workForm = $request->input('work_form');
        $manuscriptsRange = $request->input('manuscripts_range');

        $validFlags = ['is_autograph', 'is_illuminated', 'is_illustrated', 'is_corrected', 'has_marginal_notes', 'is_collated'];
        $rawFlags = (array) $request->input('flags', []);
        if ($request->filled('flag')) {
            $rawFlags[] = $request->input('flag');
        }
        $flags = array_values(array_unique(array_intersect($rawFlags, $validFlags)));
        $flag = !empty($flags) ? $flags[0] : null;

        $validWorkForms = [
            'translation' => 'ترجمه',
            'selection' => 'گزیده و تلخیص',
            'treatise' => 'رساله',
            'verse' => 'منظوم (شعر)',
            'table' => 'جدول و تقویم',
            'compilation' => 'جنگ و مجموعه',
            'notes' => 'یادداشت‌ها و تعلیقات',
            'commentary' => 'شرح',
            'lecture_notes' => 'تقریرات',
        ];

        $validManuscriptRanges = [
            'single' => 'تک‌نسخه (۱ نسخه)',
            'few' => 'کم‌نسخه (۲ تا ۴ نسخه)',
            'multiple' => 'پرنسخه (۵ تا ۱۹ نسخه)',
            'very_frequent' => 'بسیار پرنسخه (۲۰ نسخه به بالا)',
        ];

        $perPage = $exportLimit ?? (in_array((int) $request->input('per_page'), self::PER_PAGE, true) ? (int) $request->input('per_page') : 20);
        // The chosen order of the tab; none means by relevance, or the tab's own order when browsing (backlog item 83, phase 3)
        $sort = self::SORTS[$type][$request->input('sort')] ?? null;
        $sortKey = $sort ? $request->input('sort') : '';
        // An export needs only the ids from the engine; the records come from the database
        $engineSort = fn (array $options) => ($sort ? $options + ['sort' => ["{$sort[1]}:{$sort[2]}"]] : $options)
            + ($exportLimit ? ['attributesToRetrieve' => ['id']] : []);
        $dbSort = fn ($builder) => $builder->orderByRaw("{$sort[1]} IS NULL")->orderBy($sort[1], $sort[2]);

        // A search that names a shelfmark or a catalogue page is answered directly (backlog item 83, phase 2);
        // one manuscript is opened at once
        $direct = null;
        if (! $exportLimit && $rawQuery !== '' && (int) $request->input('page', 1) === 1 && ! array_filter($request->only(self::LOGGED_FILTERS))) {
            $direct = DirectAnswer::for($rawQuery);
            if ($single = $direct?->single()) {
                $this->logSearch($request, $searchQuery, $type, $scope, 1, 1, clickedPosition: 1);

                return redirect()->route('manuscripts.show', $single);
            }
        }

        $results = null;

        $subjects = collect();
        $scripts = collect();
        $libraries = collect();
        $languages = collect();
        $centuries = [];
        $flagCounts = [];
        $workFormCounts = [];
        $totalFacetManuscripts = 0;

        $selectedSubject = $subjectId ? Subject::with(['children', 'parent'])->find($subjectId) : null;
        $selectedLibrary = $libraryId ? Library::find($libraryId) : null;
        $selectedScript = $scriptId ? Script::find($scriptId) : null;
        $selectedLanguage = $languageId ? Language::find($languageId) : null;

        if ($type === 'manuscripts') {
            // Determine attributes to search on based on scope
            $searchAttrs = self::scopeAttributes('manuscripts', $scope);

            // 1. Fetch dynamic faceted distribution for manuscripts
            $meiliClient = app(MeiliClient::class);
            $facetDistribution = [];

            if ($searchQuery !== '') {
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

                    $facetRes = $meiliClient->index('manuscripts_index')->search($searchQuery, $facetOptions);
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
                // Ids differ between local and production, so pick the most used scripts by count
                $scripts = Script::where('manuscripts_count', '>', 0)->orderByDesc('manuscripts_count')->take(12)->get();
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
            if ($searchQuery !== '') {
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
                if ($copyCity) {
                    // The index keeps copy_place normalized
                    $places = collect(CopyPlaces::variants($copyCity))
                        ->map(fn ($p) => '"' . addslashes(TextNormalizer::normalize($p)) . '"')->unique()->implode(', ');
                    $meiliFilters[] = $places !== '' ? "copy_place IN [{$places}]" : 'id = 0';
                }
                foreach ($flags as $f) {
                    $meiliFilters[] = "{$f} = true";
                }

                $filterString = !empty($meiliFilters) ? implode(' AND ', $meiliFilters) : null;

                $builder = Manuscript::search($searchQuery, function ($meili, $searchQuery, $options) use ($filterString, $searchAttrs, $engineSort) {
                    $options = $engineSort($options);
                    $options['matchingStrategy'] = 'all';
                    if ($searchAttrs) {
                        $options['attributesToSearchOn'] = $searchAttrs;
                    }
                    if ($filterString) {
                        $options['filter'] = $filterString;
                    }
                    return $meili->search($searchQuery, $options);
                })->query(fn($q) => $q->with(['catalog', 'work.author', 'scribe', 'libraryRecord', 'scripts']));

                $results = $builder->paginate($perPage)->withQueryString();
            } else {
                $builder = Manuscript::query()->with(['catalog', 'work.author', 'scribe', 'libraryRecord', 'scripts']);
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
                if ($copyCity) {
                    $builder->whereIn('copy_place', CopyPlaces::variants($copyCity));
                }
                foreach ($flags as $f) {
                    $builder->where($f, true);
                }
                $results = ($sort ? $dbSort($builder) : $builder->orderBy('id'))->paginate($perPage)->withQueryString();
            }
        } elseif ($type === 'people') {
            $peopleSearchAttrs = self::scopeAttributes('people', $scope);

            if ($searchQuery !== '') {
                $results = Person::search($searchQuery, function ($meili, $searchQuery, $options) use ($peopleSearchAttrs, $engineSort) {
                    $options = $engineSort($options);
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
                $builder = Person::query()->withCount(['works', 'scribedManuscripts']);
                $results = ($sort ? $dbSort($builder) : $builder->orderByDesc('works_count'))
                    ->paginate($perPage)
                    ->withQueryString();
            }
        } else {
            // Default: works
            $type = 'works';

            $workSearchAttrs = self::scopeAttributes('works', $scope);

            // 1. Facet distribution for works
            if ($searchQuery !== '') {
                try {
                    $meiliClient = app(MeiliClient::class);
                    $facetOptions = [
                        'matchingStrategy' => 'all',
                        'facets' => ['subjects', 'languages', 'composition_year_hijri', 'work_form'],
                        'limit' => 0,
                    ];
                    if ($workSearchAttrs) {
                        $facetOptions['attributesToSearchOn'] = $workSearchAttrs;
                    }

                    $facetRes = $meiliClient->index('works_index')->search($searchQuery, $facetOptions);
                    $facetDist = $facetRes->getFacetDistribution() ?? [];

                    $subjCounts = $facetDist['subjects'] ?? [];
                    if (!empty($subjCounts)) {
                        $allSubjects = Subject::with(['parent', 'children'])
                            ->where('works_count', '>', 0)
                            ->get();

                        foreach ($allSubjects as $s) {
                            $count = $subjCounts[$s->name] ?? 0;
                            if ($s->children->isNotEmpty()) {
                                foreach ($s->children as $child) {
                                    $count += ($subjCounts[$child->name] ?? 0);
                                }
                            }
                            $s->matching_count = $count;
                        }

                        $subjects = $allSubjects->filter(fn($s) => $s->matching_count > 0)
                            ->sortByDesc('matching_count')
                            ->values();

                        if ($subjects->isEmpty()) {
                            $subjects = Subject::with('parent')->where('works_count', '>', 0)->orderByDesc('works_count')->get();
                        }
                    } else {
                        $subjects = Subject::with('parent')->where('works_count', '>', 0)->orderByDesc('works_count')->get();
                    }

                    $langCounts = $facetDist['languages'] ?? [];
                    if (!empty($langCounts)) {
                        $languages = Language::whereIn('name', array_keys($langCounts))->get()->map(function ($l) use ($langCounts) {
                            $l->matching_count = $langCounts[$l->name] ?? 0;
                            return $l;
                        })->sortByDesc('matching_count')->values();
                    } else {
                        $languages = Language::where('works_count', '>', 0)->orderByDesc('works_count')->get();
                    }

                    $compYearCounts = $facetDist['composition_year_hijri'] ?? [];
                    foreach ($compYearCounts as $yr => $cnt) {
                        $y = (int) $yr;
                        if ($y >= 100 && $y <= 1500) {
                            $c = (int) ceil($y / 100);
                            $centuries[$c] = ($centuries[$c] ?? 0) + $cnt;
                        }
                    }
                    ksort($centuries);

                    $workFormCounts = $facetDist['work_form'] ?? [];
                } catch (\Throwable $e) {
                    Log::warning('Meilisearch works facet failed: ' . $e->getMessage());
                    $subjects = Subject::with('parent')->where('works_count', '>', 0)->orderByDesc('works_count')->get();
                    $languages = Language::where('works_count', '>', 0)->orderByDesc('works_count')->get();
                }
            } else {
                $subjects = Subject::with('parent')->where('works_count', '>', 0)->orderByDesc('works_count')->get();
                $languages = Language::where('works_count', '>', 0)->orderByDesc('works_count')->get();
                for ($c = 4; $c <= 14; $c++) {
                    $centuries[$c] = null;
                }
            }

            if ($selectedSubject && !$subjects->contains('id', $selectedSubject->id)) {
                $subjects->prepend($selectedSubject);
            }
            if ($selectedLanguage && !$languages->contains('id', $selectedLanguage->id)) {
                $selectedLanguage->matching_count = 0;
                $languages->prepend($selectedLanguage);
            }

            // 2. Execute Works Search
            if ($searchQuery !== '') {
                $workFilters = [];
                if ($selectedSubject) {
                    $targetSubjectNames = [$selectedSubject->name];
                    foreach ($selectedSubject->children as $child) {
                        $targetSubjectNames[] = $child->name;
                    }
                    if (count($targetSubjectNames) === 1) {
                        $workFilters[] = 'subjects = "' . addslashes($targetSubjectNames[0]) . '"';
                    } else {
                        $orFilters = array_map(fn($n) => 'subjects = "' . addslashes($n) . '"', $targetSubjectNames);
                        $workFilters[] = '(' . implode(' OR ', $orFilters) . ')';
                    }
                }
                if ($selectedLanguage) {
                    $workFilters[] = 'languages = "' . addslashes($selectedLanguage->name) . '"';
                }
                if ($century) {
                    $centuryStart = ($century - 1) * 100 + 1;
                    $centuryEnd = $century * 100;
                    $workFilters[] = "(composition_year_hijri >= {$centuryStart} AND composition_year_hijri <= {$centuryEnd})";
                }
                if ($workForm && isset($validWorkForms[$workForm])) {
                    $workFilters[] = 'work_form = "' . addslashes($workForm) . '"';
                }
                if ($manuscriptsRange) {
                    if ($manuscriptsRange === 'single') {
                        $workFilters[] = 'manuscripts_count = 1';
                    } elseif ($manuscriptsRange === 'few') {
                        $workFilters[] = '(manuscripts_count >= 2 AND manuscripts_count <= 4)';
                    } elseif ($manuscriptsRange === 'multiple') {
                        $workFilters[] = '(manuscripts_count >= 5 AND manuscripts_count <= 19)';
                    } elseif ($manuscriptsRange === 'very_frequent') {
                        $workFilters[] = 'manuscripts_count >= 20';
                    }
                }

                $filterString = !empty($workFilters) ? implode(' AND ', $workFilters) : null;

                $workMatchedFields = [];
                $builder = Work::search($searchQuery, function ($meili, $searchQuery, $options) use ($filterString, $workSearchAttrs, &$workMatchedFields, $engineSort) {
                    $options = $engineSort($options);
                    $options['matchingStrategy'] = 'all';
                    if ($workSearchAttrs) {
                        $options['attributesToSearchOn'] = $workSearchAttrs;
                    } else {
                        // Only the "all" scope reaches text fields; record which fields matched to explain the hit
                        $options['showMatchesPosition'] = true;
                    }
                    if ($filterString) {
                        $options['filter'] = $filterString;
                    }
                    $result = $meili->search($searchQuery, $options);
                    foreach ($result->getHits() as $hit) {
                        $workMatchedFields[$hit['id']] = array_keys($hit['_matchesPosition'] ?? []);
                    }
                    return $result;
                })->query(fn($q) => $q->with(['catalog', 'author', 'subjects', 'languages']));

                $results = $builder->paginate($perPage)->withQueryString();
                foreach ($results as $work) {
                    $work->match_reason = self::workMatchReason($workMatchedFields[$work->id] ?? []);
                }
            } else {
                $builder = Work::query()->with(['catalog', 'author', 'subjects', 'languages']);
                if ($subjectId && $selectedSubject) {
                    $targetSubjectIds = [$selectedSubject->id];
                    foreach ($selectedSubject->children as $child) {
                        $targetSubjectIds[] = $child->id;
                    }
                    $builder->whereHas('subjects', fn($sq) => $sq->whereIn('subjects.id', $targetSubjectIds));
                }
                if ($languageId) {
                    $builder->whereHas('languages', fn($lq) => $lq->where('languages.id', $languageId));
                }
                if ($century) {
                    $centuryStart = ($century - 1) * 100 + 1;
                    $centuryEnd = $century * 100;
                    $builder->whereBetween('composition_year_hijri', [$centuryStart, $centuryEnd]);
                }
                if ($workForm && isset($validWorkForms[$workForm])) {
                    $builder->where('work_form', $workForm);
                }
                if ($manuscriptsRange) {
                    if ($manuscriptsRange === 'single') {
                        $builder->where('manuscripts_count', 1);
                    } elseif ($manuscriptsRange === 'few') {
                        $builder->whereBetween('manuscripts_count', [2, 4]);
                    } elseif ($manuscriptsRange === 'multiple') {
                        $builder->whereBetween('manuscripts_count', [5, 19]);
                    } elseif ($manuscriptsRange === 'very_frequent') {
                        $builder->where('manuscripts_count', '>=', 20);
                    }
                }
                $results = ($sort ? $dbSort($builder) : $builder->orderByDesc('manuscripts_count'))->paginate($perPage)->withQueryString();
            }

            // Calculate manuscript century ranges and autograph status for all works on current page
            // The copying centuries and autograph badge are for the page, not for an export
            $workIds = $exportLimit ? [] : $results->pluck('id')->filter()->all();
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

        $tabCounts = $searchQuery !== '' && $results ? $this->tabCounts($searchQuery, $scope, $type, $results->total()) : [];

        $suggestion = null;
        if ($results && $results->total() === 0 && $searchQuery !== '') {
            $suggestion = $this->getDidYouMeanSuggestion($searchQuery, $type);
        }

        $searchLogId = $results && ! $exportLimit ? $this->logSearch($request, $searchQuery, $type, $scope, $results->total(), $results->currentPage()) : null;

        return compact(
            'sortKey',
            'perPage',
            'direct',
            'searchLogId',
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
            'copyCity',
            'flag',
            'flags',
            'selectedSubject',
            'selectedLibrary',
            'selectedScript',
            'languages',
            'selectedLanguage',
            'languageId',
            'workForm',
            'workFormCounts',
            'validWorkForms',
            'manuscriptsRange',
            'validManuscriptRanges',
            'suggestion',
            'tabCounts'
        );
    }

    /** Each tab's orders: key => [label, field, direction]; the fields are sortable in the index and columns in the table */
    public const SORTS = [
        'works' => [
            'manuscripts' => ['بیشترین نسخه', 'manuscripts_count', 'desc'],
            'oldest' => ['کهن‌ترین تألیف', 'composition_year_hijri', 'asc'],
            'newest' => ['تازه‌ترین تألیف', 'composition_year_hijri', 'desc'],
        ],
        'manuscripts' => [
            'oldest' => ['کهن‌ترین کتابت', 'copy_date_hijri_year', 'asc'],
            'newest' => ['تازه‌ترین کتابت', 'copy_date_hijri_year', 'desc'],
            'folios' => ['بیشترین برگ', 'folios', 'desc'],
        ],
        'people' => [
            'oldest' => ['کهن‌ترین وفات', 'death_year_hijri', 'asc'],
            'newest' => ['تازه‌ترین وفات', 'death_year_hijri', 'desc'],
            'works' => ['بیشترین اثر', 'works_count', 'desc'],
        ],
    ];

    public const PER_PAGE = [20, 50, 100];

    /** The filters of the search page, recorded with each search */
    private const LOGGED_FILTERS = ['subject_id', 'library_id', 'script_id', 'century', 'copy_city', 'language_id', 'work_form', 'manuscripts_range', 'flags', 'flag'];

    /**
     * Records the search for the admin's search stats (backlog item 83) and returns its id, which the page
     * sends back with the rank of the result opened. Only a search's first page, by a person, with a query
     * or a filter, is counted; browsing on to page 2 is not a new search.
     */
    protected function logSearch(Request $request, string $searchQuery, string $type, string $scope, int $total, int $page, ?int $clickedPosition = null): ?int
    {
        $filters = $request->only(self::LOGGED_FILTERS);
        // The single «flag» of older links is one of the flags
        if (isset($filters['flag'])) {
            $filters['flags'] = array_values(array_unique([...(array) ($filters['flags'] ?? []), $filters['flag']]));
            unset($filters['flag']);
        }
        $filters = array_filter($filters, fn ($v) => $v !== null && $v !== '' && $v !== []);
        if (($searchQuery === '' && ! $filters) || $page > 1 || ! SearchLog::isCountable($request)) {
            return null;
        }

        try {
            return SearchLog::create([
                'query' => mb_substr($searchQuery, 0, 200),
                'type' => $type,
                'scope' => $scope,
                'filters' => $filters ?: null,
                'results_count' => $total,
                'clicked_position' => $clickedPosition,
                'is_member' => $request->user() !== null,
                // Kept with the account unless the user turned it off in «حساب من»
                'user_id' => $request->user()?->search_history ? $request->user()->id : null,
            ])->id;
        } catch (\Throwable $e) {
            // The stats must never break the search
            Log::warning('Search log failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * The results of a search as an Excel or CSV file, with its filters and order (backlog item 83, phase 4):
     * the first GUEST_LIMIT rows for a guest, MEMBER_LIMIT for a signed-in user.
     */
    public function export(Request $request)
    {
        $limit = $request->user() ? SearchExport::MEMBER_LIMIT : SearchExport::GUEST_LIMIT;
        // Room for the largest file above php-fpm's 128 MB; the route is throttled
        ini_set('memory_limit', '192M');
        $data = $this->search($request->merge(['page' => 1]), $limit);
        $type = $data['type'];
        $name = trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', 'فهارس-' . ['works' => 'آثار', 'manuscripts' => 'نسخه‌ها', 'people' => 'اشخاص'][$type] . '-' . $data['query']), '-');

        return SearchExport::download($data['results'] ?? [], $type, $request->input('format') === 'csv' ? 'csv' : 'xlsx', $name);
    }

    /** The rank of the result opened from a logged search; only the first one opened counts */
    public function click(Request $request)
    {
        $data = $request->validate([
            'log' => ['required', 'integer'],
            'position' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        SearchLog::whereKey($data['log'])
            ->whereNull('clicked_position')
            ->where('created_at', '>=', now()->subHours(6))
            ->update(['clicked_position' => $data['position']]);

        return response()->noContent();
    }

    /**
     * Attributes each search tab searches on for a scope; null means every searchable attribute ("all").
     */
    public static function scopeAttributes(string $type, string $scope): ?array
    {
        return [
            'works' => [
                'titles' => ['primary_title', 'clean_title', 'alternative_titles'],
                'titles_names' => ['primary_title', 'clean_title', 'alternative_titles', 'author_name'],
            ],
            'manuscripts' => [
                'titles' => ['work_title'],
                // The shelfmark and library too, so «مجلس ۱۱۸۹» finds the manuscript (backlog item 83)
                'titles_names' => ['work_title', 'author_name', 'shelfmark', 'scribe_name', 'library', 'library_full_name'],
            ],
            'people' => [
                'titles' => ['name'],
                'titles_names' => ['name', 'normalized_name', 'transliteration'],
            ],
        ][$type][$scope] ?? null;
    }

    /**
     * Why a work matched when nothing in its titles, author or subject did: its own incipit/explicit,
     * its manuscripts' incipits/explicits, or its description. Null when the match needs no explaining.
     */
    protected static function workMatchReason(array $matchedFields): ?string
    {
        $fields = array_unique(array_map(fn ($f) => preg_split('/[.\[]/', $f)[0], $matchedFields));
        $textFields = ['incipit_text', 'explicit_text', 'manuscript_texts', 'description'];
        if (! $fields || array_diff($fields, $textFields)) {
            return null;
        }
        if (array_intersect($fields, ['incipit_text', 'explicit_text'])) {
            return 'یافته در آغاز یا انجام اثر';
        }
        if (in_array('manuscript_texts', $fields, true)) {
            return 'یافته در آغاز یا انجام نسخه‌ها';
        }

        return 'یافته در معرفی اثر';
    }

    /**
     * Hit counts for the three tabs, so a search on the wrong tab points to where the matches are.
     * The current tab keeps its filtered total; the others count the bare query in the same scope.
     */
    protected function tabCounts(string $searchQuery, string $scope, string $type, int $currentTotal): array
    {
        $indexes = ['works' => 'works_index', 'manuscripts' => 'manuscripts_index', 'people' => 'people_index'];
        $counts = [$type => $currentTotal];
        try {
            $meili = app(MeiliClient::class);
            foreach ($indexes as $tab => $index) {
                if ($tab === $type) {
                    continue;
                }
                $options = ['matchingStrategy' => 'all', 'limit' => 0];
                if ($attrs = self::scopeAttributes($tab, $scope)) {
                    $options['attributesToSearchOn'] = $attrs;
                }
                $counts[$tab] = (int) $meili->index($index)->search($searchQuery, $options)->getEstimatedTotalHits();
            }
        } catch (\Throwable $e) {
            Log::warning('Search tab counts failed: ' . $e->getMessage());

            return [];
        }

        return $counts;
    }

    /**
     * Fast JSON API for Live Instant Search Autocomplete
     */
    public function api(Request $request)
    {
        $rawQuery = trim($request->input('q', ''));
        $searchQuery = TextNormalizer::normalize($rawQuery);
        $type = $request->input('type', 'all');

        if ($searchQuery === '' || mb_strlen($searchQuery) < 2) {
            return response()->json([
                'works' => [],
                'people' => [],
                'manuscripts' => [],
            ]);
        }

        $response = [
            'works' => [],
            'people' => [],
            'manuscripts' => [],
        ];

        try {
            if ($type === 'all' || $type === 'works') {
                $response['works'] = Work::search($searchQuery)
                    ->query(fn($q) => $q->with('author')->withCount('manuscripts'))
                    ->take(5)
                    ->get()
                    ->map(fn($w) => [
                        'id' => $w->id,
                        'title' => $w->title,
                        'author' => $w->author?->name ?? 'ناشناخته',
                        'manuscripts_count' => $w->manuscripts_count,
                        'url' => route('works.show', $w, false),
                    ]);
            }

            if ($type === 'all' || $type === 'people') {
                $response['people'] = Person::search($searchQuery)
                    ->query(fn($q) => $q->withCount('works'))
                    ->take(5)
                    ->get()
                    ->map(fn($p) => [
                        'id' => $p->id,
                        'name' => $p->name,
                        'death_hijri' => $p->life_span,
                        'works_count' => $p->works_count,
                        'url' => route('people.show', $p, false),
                    ]);
            }

            if ($type === 'all' || $type === 'manuscripts') {
                // The manuscripts a shelfmark or a catalogue page names come first (backlog item 83)
                $named = (DirectAnswer::for($rawQuery)?->manuscripts ?? collect())->take(5);
                $response['manuscripts'] = $named->concat(Manuscript::search($searchQuery)
                    ->query(fn($q) => $q->with(['work.author', 'libraryRecord']))
                    ->take(5)
                    ->get())
                    ->unique('id')
                    ->take(5)
                    ->values()
                    ->map(fn($m) => [
                        'id' => $m->id,
                        'work_title' => $m->work?->title ?? 'نسخه بدون عنوان',
                        'author_name' => $m->work?->author?->name ?? $m->work?->author_name,
                        'library' => $m->libraryRecord?->short_name ?? $m->library ?? 'کتابخانه نامشخص',
                        'library_full_name' => $m->libraryRecord?->display_name,
                        'shelfmark' => $m->shelfmark,
                        'accession_number' => $m->shelfmark,
                        'url' => route('manuscripts.show', $m, false),
                    ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Live search API query error: ' . $e->getMessage());
        }

        return response()->json($response);
    }

    /**
     * Retrieve a spelling/fuzzy suggestion when results are 0.
     */
    protected function getDidYouMeanSuggestion(string $query, string $type): ?string
    {
        if (mb_strlen($query) < 2) {
            return null;
        }

        try {
            $meili = app(MeiliClient::class);
            $candidate = null;

            if ($type === 'people') {
                $candidate = $this->findCandidateInIndex($meili, 'people_index', $query, ['name', 'normalized_name']);
                if (!$candidate) {
                    $candidate = $this->findCandidateInIndex($meili, 'works_index', $query, ['clean_title', 'primary_title']);
                }
            } else {
                $candidate = $this->findCandidateInIndex($meili, 'works_index', $query, ['clean_title', 'primary_title']);
                if (!$candidate) {
                    $candidate = $this->findCandidateInIndex($meili, 'people_index', $query, ['name', 'normalized_name']);
                }
            }

            if ($candidate && mb_strtolower($candidate) !== mb_strtolower($query)) {
                return $candidate;
            }
        } catch (\Throwable $e) {
            Log::warning('Meilisearch DidYouMean error: ' . $e->getMessage());
        }

        return $this->getDatabaseSuggestion($query, $type);
    }

    /**
     * Find candidate string in a Meilisearch index.
     */
    protected function findCandidateInIndex(MeiliClient $meili, string $indexName, string $query, array $fields): ?string
    {
        $res = $meili->index($indexName)->search($query, [
            'limit' => 5,
            'matchingStrategy' => 'last',
        ]);

        $hits = $res->getHits();
        if (empty($hits)) {
            return null;
        }

        $bestCandidate = null;
        $highestScore = 0;

        foreach ($hits as $hit) {
            foreach ($fields as $field) {
                $val = trim($hit[$field] ?? '');
                if (!empty($val) && mb_strtolower($val) !== mb_strtolower($query)) {
                    similar_text(mb_strtolower($query), mb_strtolower($val), $percent);
                    if ($percent > $highestScore && $percent >= 30) {
                        $highestScore = $percent;
                        $bestCandidate = $val;
                    }
                }
            }
        }

        return $bestCandidate;
    }

    /**
     * Database fallback for suggestions.
     */
    protected function getDatabaseSuggestion(string $query, string $type): ?string
    {
        $prefix = mb_substr($query, 0, 3);
        if (mb_strlen($prefix) < 2) {
            return null;
        }

        if ($type === 'people') {
            $p = Person::where('name', 'LIKE', "{$prefix}%")
                ->orderByDesc('works_count')
                ->value('name');
            if ($p && mb_strtolower($p) !== mb_strtolower($query)) {
                return $p;
            }
        }

        $w = Work::where('primary_title', 'LIKE', "{$prefix}%")
            ->orderByDesc('manuscripts_count')
            ->value('primary_title');

        return ($w && mb_strtolower($w) !== mb_strtolower($query)) ? $w : null;
    }
}

