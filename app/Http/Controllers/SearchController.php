<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Script;
use App\Models\SearchLog;
use App\Models\SourceEntry;
use App\Models\SourceItem;
use App\Helpers\TextNormalizer;
use App\Models\Subject;
use App\Models\Work;
use App\Support\CopyPlaces;
use App\Support\DirectAnswer;
use App\Support\SearchExport;
use App\Support\SearchFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
    protected function search(Request $request, ?int $exportLimit = null, bool $idsOnly = false): array|\Illuminate\Http\RedirectResponse
    {
        $rawQuery = trim((string) $request->input('q', ''));
        $searchQuery = TextNormalizer::normalize($rawQuery);
        $query = $rawQuery;
        $type = in_array($request->input('type'), self::TYPES, true) ? $request->input('type') : 'works';
        $scope = self::scopeAvailable($type, (string) $request->input('scope')) ? $request->input('scope') : 'titles_names';
        // Every filter of the tab, defined once for the engine and the database (backlog item 83, phase 5)
        $filters = SearchFilters::fromRequest($request, $type);

        $perPage = $exportLimit ?? (in_array((int) $request->input('per_page'), self::PER_PAGE, true) ? (int) $request->input('per_page') : 20);
        // The chosen order of the tab; none means by relevance, or the tab's own order when browsing (backlog item 83, phase 3)
        $sort = self::SORTS[$type][$request->input('sort')] ?? null;
        $sortKey = $sort ? $request->input('sort') : '';
        // Only the ids come from the engine; the records come from the database
        $engineSort = fn (array $options) => ($sort ? $options + ['sort' => ["{$sort[1]}:{$sort[2]}"]] : $options) + ['attributesToRetrieve' => ['id']];
        $dbSort = function ($builder) use ($sort) {
            // A field the table lacks (the author's death of a work) is given as an expression
            $expr = $sort[3] ?? $builder->getModel()->getTable() . '.' . $sort[1];

            return $builder->orderByRaw("{$expr} IS NULL")->orderByRaw("{$expr} {$sort[2]}");
        };

        // A search that names a shelfmark or a catalogue page is answered directly (backlog item 83, phase 2);
        // one manuscript is opened at once
        $direct = null;
        if (! $exportLimit && $rawQuery !== '' && (int) $request->input('page', 1) === 1 && $filters->isEmpty()) {
            $direct = DirectAnswer::for($rawQuery);
            if ($single = $direct?->single()) {
                $this->logSearch($request, $searchQuery, $type, $scope, $filters, 1, 1, clickedPosition: 1);

                return redirect()->route('manuscripts.show', $single);
            }
        }

        $searchAttrs = self::scopeAttributes($type, $scope);
        // The counts beside each filter's choices, among the matches of the words (or all records) within the list
        // the tab is narrowed to; when the engine fails, the stored totals
        $facets = $exportLimit || $idsOnly ? null : $this->facets($type, $searchQuery, $searchAttrs, $filters->contextEngine());
        $options = $exportLimit || $idsOnly ? [] : $this->filterOptions($type, $filters, $facets ?? [], $facets !== null);

        $model = ['works' => Work::class, 'manuscripts' => Manuscript::class, 'people' => Person::class, 'sources' => SourceItem::class][$type];
        $with = self::WITH[$type];
        $prepare = fn ($q) => match ($type) {
            'people' => $q->withCount(['works', 'scribedManuscripts']),
            // A preface is not an entry, and the index leaves it out too
            'sources' => $q->with($with)->where('source_items.kind', '!=', 'preface'),
            default => $q->with($with),
        };

        $matchedFields = [];
        $snippets = [];
        $engineDown = false;
        if ($searchQuery !== '') {
            $engineFilter = $filters->engine();
            $builder = $model::search($searchQuery, function ($meili, $searchQuery, $options) use ($type, $searchAttrs, $engineFilter, $engineSort, $exportLimit, &$matchedFields, &$snippets) {
                $options = $engineSort($options);
                $options['matchingStrategy'] = 'all';
                if ($type === 'sources' && ! $exportLimit) {
                    // The words in their place in the printed text, marked; \x01 and \x02 survive the escaping of the page
                    $options = ['attributesToRetrieve' => ['id', 'title', 'text'], 'attributesToCrop' => ['text:36'], 'cropMarker' => '…',
                        'attributesToHighlight' => ['title', 'text'], 'highlightPreTag' => "\x01", 'highlightPostTag' => "\x02"] + $options;
                }
                if ($searchAttrs) {
                    $options['attributesToSearchOn'] = $searchAttrs;
                } elseif ($type === 'works') {
                    // Only the "all" scope reaches text fields; record which fields matched to explain the hit
                    $options['showMatchesPosition'] = true;
                }
                if ($engineFilter) {
                    $options['filter'] = $engineFilter;
                }
                $result = $meili->search($searchQuery, $options);
                foreach ($result->getHits() as $hit) {
                    $matchedFields[$hit['id']] = array_keys($hit['_matchesPosition'] ?? []);
                    if (isset($hit['_formatted'])) {
                        $snippets[$hit['id']] = ['title' => $hit['_formatted']['title'] ?? null, 'text' => $hit['_formatted']['text'] ?? null];
                    }
                }

                return $result;
            });
            if ($idsOnly) {
                // A queued export: only the ids, in the order of the results, up to its limit
                return ['type' => $type, 'query' => $query, 'ids' => $builder->take($exportLimit)->keys()->map(fn ($id) => (int) $id)->all()];
            }
            // No query() callback: with one, Scout counts the total by fetching every match from the engine and
            // counting it in the database (40 s for «کافی» in production). The relations are loaded for the page alone.
            try {
                $results = $builder->paginate($perPage)->withQueryString();
            } catch (\Meilisearch\Exceptions\ApiException|\Meilisearch\Exceptions\CommunicationException|\Meilisearch\Exceptions\TimeOutException $e) {
                // The engine is down or an index is still being built: an empty page that says so, not an error page;
                // the admins still hear of it (bootstrap/app.php)
                report($e);
                $engineDown = true;
                $results = new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage, 1, ['path' => $request->url(), 'query' => $request->query()]);
            }
            if (! $engineDown) {
                $type === 'people'
                    ? $results->getCollection()->loadCount(['works', 'scribedManuscripts'])
                    : $results->getCollection()->load($with);
            }
        } else {
            $builder = $filters->apply($prepare($model::query()));
            $ordered = $sort ? $dbSort($builder) : match ($type) {
                'works' => $builder->orderByDesc('works.manuscripts_count'),
                // The stored count: ordering by the counted one counts every person's works first
                'people' => $builder->orderByDesc('people.works_count'),
                'sources' => $builder->orderBy('source_items.volume_number')->orderBy('source_items.position'),
                default => $builder->orderBy('manuscripts.id'),
            };
            if ($idsOnly) {
                $table = $ordered->getModel()->getTable();

                return ['type' => $type, 'query' => $query, 'ids' => $ordered->setEagerLoads([])->limit($exportLimit)->pluck("{$table}.id")->map(fn ($id) => (int) $id)->all()];
            }
            $results = $ordered->paginate($perPage)->withQueryString();
        }

        if ($type === 'sources') {
            foreach ($results as $item) {
                $item->snippet = $snippets[$item->id] ?? null;
            }
            if (! $exportLimit) {
                $this->addSourceLinks($results);
            }
        }

        if ($type === 'works' && ! $exportLimit) {
            foreach ($results as $work) {
                $work->match_reason = self::workMatchReason($matchedFields[$work->id] ?? []);
            }
            $this->addCopyCenturies($results);
        }

        $tabCounts = $searchQuery !== '' && ! $exportLimit ? $this->tabCounts($searchQuery, $scope, $type, $results->total()) : [];
        $suggestion = ! $exportLimit && $results->total() === 0 && $searchQuery !== '' ? $this->getDidYouMeanSuggestion($searchQuery, $type) : null;
        $searchLogId = $exportLimit || $engineDown ? null : $this->logSearch($request, $searchQuery, $type, $scope, $filters, $results->total(), $results->currentPage());
        $validWorkForms = SearchFilters::WORK_FORMS;

        return compact('results', 'query', 'type', 'scope', 'filters', 'options', 'sortKey', 'perPage', 'direct', 'searchLogId', 'engineDown',
            'tabCounts', 'suggestion', 'validWorkForms');
    }

    /**
     * The engine's counts of the values of each filter among the matches of the words, or among all records when
     * there are none, within the context filter and before the others; null when the engine fails
     */
    protected function facets(string $type, string $searchQuery, ?array $searchAttrs, ?string $context = null): ?array
    {
        $names = [
            'works' => ['subjects', 'languages', 'era_year', 'work_form', 'bibliographies', 'has_autograph'],
            'manuscripts' => ['library_id', 'city', 'scripts', 'copy_date_hijri_year', 'subjects', 'languages', 'is_facsimile', ...array_keys(SearchFilters::FLAGS)],
            'people' => ['century_hijri', 'is_author', 'is_scribe', 'is_translator', 'is_donor'],
            'sources' => ['source_id', 'volume_number'],
        ][$type];
        $index = self::INDEXES[$type];
        try {
            $options = ['matchingStrategy' => 'all', 'facets' => $names, 'limit' => 0];
            if ($searchAttrs && $searchQuery !== '') {
                $options['attributesToSearchOn'] = $searchAttrs;
            }
            if ($context) {
                $options['filter'] = $context;
            }

            return app(MeiliClient::class)->index($index)->search($searchQuery, $options)->getFacetDistribution() ?? [];
        } catch (\Throwable $e) {
            Log::warning("Meilisearch {$type} facets failed: " . $e->getMessage());

            return null;
        }
    }

    /**
     * The choices of each filter in the sidebar, with how many matches each has: counted by the engine ($counted:
     * counts are known), or the stored totals when it failed. A chosen value stays in its list even with no match.
     */
    protected function filterOptions(string $type, SearchFilters $filters, array $facets, bool $counted): array
    {
        $options = [];
        $count = fn (string $facet, $value) => $counted ? (int) ($facets[$facet][$value] ?? 0) : null;
        $centuries = function (string $facet) use ($facets, $counted) {
            if (! $counted) {
                return array_fill_keys(range(3, 14), null);
            }
            $byCentury = [];
            foreach ($facets[$facet] ?? [] as $year => $n) {
                if ($year >= 1 && $year <= 1500) {
                    $c = (int) ceil($year / 100);
                    $byCentury[$c] = ($byCentury[$c] ?? 0) + $n;
                }
            }
            ksort($byCentury);

            return $byCentury;
        };

        if ($type === 'sources') {
            // The sources whose entries are searchable, and the volumes of the one chosen
            $options['sources'] = \App\Models\Source::whereIn('id', SourceItem::query()->select('source_id')->distinct())->orderBy('rank')->orderBy('id')->get()
                ->each(fn ($src) => $src->matching_count = $count('source_id', $src->id));
            $options['volumes'] = $filters->source
                ? collect($facets['volume_number'] ?? [])->mapWithKeys(fn ($n, $v) => [(int) $v => $n])->sortKeys()->all()
                : [];

            return $options;
        }

        if ($type === 'people') {
            $options['centuries'] = $counted ? collect($facets['century_hijri'] ?? [])->mapWithKeys(fn ($n, $c) => [(int) $c => $n])->sortKeys()->all()
                : array_fill_keys(range(1, 15), null);
            $options['roles'] = collect(SearchFilters::ROLES)->map(fn ($label, $role) => [$label, $count("is_{$role}", 'true')])->all();

            return $options;
        }

        // Subjects: a parent counts its children's matches too
        $subjects = Subject::with(['parent', 'children'])->where('works_count', '>', 0)->get();
        foreach ($subjects as $s) {
            $s->matching_count = $counted
                ? collect([$s, ...$s->children])->sum(fn ($x) => $facets['subjects'][$x->name] ?? 0)
                : ($type === 'works' ? $s->works_count : null);
        }
        $options['subjects'] = $subjects->filter(fn ($s) => ! $counted || $s->matching_count > 0 || $s->id === $filters->subject?->id)
            ->sortByDesc('matching_count')->values();
        $options['languages'] = Language::where('works_count', '>', 0)->orderByDesc('works_count')->get()
            ->each(fn ($l) => $l->matching_count = $counted ? (int) ($facets['languages'][$l->name] ?? 0) : ($type === 'works' ? $l->works_count : null))
            ->filter(fn ($l) => ! $counted || $l->matching_count > 0 || $l->id === $filters->language?->id)
            ->sortByDesc('matching_count')->values();

        if ($type === 'works') {
            $options['centuries'] = $centuries('era_year');
            $options['workForms'] = collect(SearchFilters::WORK_FORMS)->map(fn ($label, $form) => [$label, $count('work_form', $form)])->all();
            $options['sources'] = \App\Models\Source::whereIn('kind', SearchFilters::SOURCE_KINDS)->orderBy('rank')->orderBy('id')->get()
                ->each(fn ($s) => $s->matching_count = $count('bibliographies', $s->id));
            $options['autographs'] = $count('has_autograph', 'true');

            return $options;
        }

        // Manuscripts: every library, so one with few matches can still be chosen
        $libCounts = $facets['library_id'] ?? [];
        $options['libraries'] = Library::where('manuscripts_count', '>', 0)->orderByDesc('manuscripts_count')->get(['id', 'name', 'full_name', 'city', 'manuscripts_count'])
            ->each(fn ($l) => $l->matching_count = $counted ? (int) ($libCounts[$l->id] ?? 0) : $l->manuscripts_count)
            ->filter(fn ($l) => ! $counted || $l->matching_count > 0 || $filters->libraries->contains('id', $l->id))
            ->sortByDesc('matching_count')->values();
        $options['cities'] = $counted
            ? collect($facets['city'] ?? [])->sortDesc()->all()
            : Library::where('manuscripts_count', '>', 0)->whereNotNull('city')->where('city', '!=', '')
                ->selectRaw('city, SUM(manuscripts_count) AS n')->groupBy('city')->orderByDesc('n')->pluck('n', 'city')->map(fn ($n) => (int) $n)->all();
        $options['scripts'] = Script::where('manuscripts_count', '>', 0)->orderByDesc('manuscripts_count')->get()
            ->each(fn ($s) => $s->matching_count = $counted ? (int) ($facets['scripts'][$s->name] ?? 0) : $s->manuscripts_count)
            ->filter(fn ($s) => ! $counted || $s->matching_count > 0 || $s->id === $filters->script?->id)
            ->sortByDesc('matching_count')->values();
        $options['centuries'] = $centuries('copy_date_hijri_year');
        $options['flags'] = collect(SearchFilters::FLAGS)->map(fn ($label, $flag) => [$label, $count($flag, 'true')])->all();
        $options['facsimile'] = ['0' => $count('is_facsimile', 'false'), '1' => $count('is_facsimile', 'true')];
        $options['copyCities'] = array_keys(CopyPlaces::cities());

        return $options;
    }

    /** The works of the site each printed entry of the page is tied to, a reviewed tie first */
    protected function addSourceLinks($results): void
    {
        $links = SourceEntry::with('citable:id,primary_title,slug')
            ->whereIn('source_item_id', $results->pluck('id'))->where('citable_type', Work::class)
            ->where('status', '!=', SourceEntry::STATUS_REJECTED)
            ->orderByRaw('status = ? desc', [SourceEntry::STATUS_VERIFIED])->get()
            ->filter(fn ($l) => $l->citable)->groupBy('source_item_id');
        foreach ($results as $item) {
            $item->linked_works = ($links->get($item->id) ?? collect())->pluck('citable')->unique('id')->values();
        }
    }

    /** Beside each work of the page: the centuries its manuscripts were copied in, and whether one is the author's own */
    protected function addCopyCenturies($results): void
    {
        $workIds = $results->pluck('id')->filter()->all();
        if (empty($workIds)) {
            return;
        }
        $allYearsByWork = Manuscript::whereIn('work_id', $workIds)
            ->whereNotNull('copy_date_hijri_year')
            ->where('copy_date_hijri_year', '>=', 300)
            ->where('copy_date_hijri_year', '<=', 1500)
            ->select('work_id', 'copy_date_hijri_year')
            ->distinct()
            ->get()
            ->groupBy('work_id');
        $autographWorkIds = Manuscript::whereIn('work_id', $workIds)->where('is_autograph', true)->pluck('work_id')->flip()->all();

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
                ->filter(fn ($y) => $y >= $minAllowedYear);

            if ($years->isNotEmpty()) {
                $minCentury = (int) ceil($years->min() / 100);
                $maxCentury = (int) ceil($years->max() / 100);
                $work->copy_century_text = ($minCentury === $maxCentury)
                    ? "قرن {$minCentury} هـ.ق"
                    : "از قرن {$minCentury} تا {$maxCentury} هـ.ق";
            } else {
                $work->copy_century_text = null;
            }
        }
    }

    /**
     * Each tab's orders: key => [label, field, direction, the database expression when the table lacks the field];
     * the fields are sortable in the index
     */
    public const SORTS = [
        'works' => [
            'manuscripts' => ['بیشترین نسخه', 'manuscripts_count', 'desc'],
            'oldest' => ['کهن‌ترین تألیف', 'composition_year_hijri', 'asc'],
            'newest' => ['تازه‌ترین تألیف', 'composition_year_hijri', 'desc'],
            'author_oldest' => ['کهن‌ترین مؤلف', 'author_death_year', 'asc', '(SELECT death_year_hijri FROM people WHERE people.id = works.author_id)'],
            'author_newest' => ['تازه‌ترین مؤلف', 'author_death_year', 'desc', '(SELECT death_year_hijri FROM people WHERE people.id = works.author_id)'],
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
        'sources' => [],
    ];

    /** The relations each tab's results show */
    public const WITH = [
        'works' => ['catalog', 'author', 'subjects', 'languages'],
        'manuscripts' => ['catalog', 'work.author', 'scribe', 'libraryRecord', 'scripts'],
        'people' => [],
        'sources' => ['source'],
    ];

    /** The search tabs: works, manuscripts, people, and the printed entries of the sources (phase 7) */
    public const TYPES = ['works', 'manuscripts', 'people', 'sources'];

    public const INDEXES = ['works' => 'works_index', 'manuscripts' => 'manuscripts_index', 'people' => 'people_index', 'sources' => 'source_items_index'];

    public const PER_PAGE = [20, 50, 100];

    /**
     * Records the search for the admin's search stats (backlog item 83) and returns its id, which the page
     * sends back with the rank of the result opened. Only a search's first page, by a person, with a query
     * or a filter, is counted; browsing on to page 2 is not a new search.
     */
    protected function logSearch(Request $request, string $searchQuery, string $type, string $scope, SearchFilters $searchFilters, int $total, int $page, ?int $clickedPosition = null, string $kind = SearchLog::KIND_SEARCH, ?string $format = null): ?int
    {
        $filters = $searchFilters->values;
        // An export of everything counts too; a bare page without words or filters is not a search
        if (($searchQuery === '' && ! $filters && $kind === SearchLog::KIND_SEARCH) || $page > 1 || ! SearchLog::isCountable($request)) {
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
                'kind' => $kind,
                'format' => $format,
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
        // Exports count in the search stats too (backlog item 85)
        $this->logSearch($request, TextNormalizer::normalize($data['query']), $type, $data['scope'], $data['filters'],
            $data['results']->total(), 1, kind: SearchLog::KIND_EXPORT, format: $request->input('format') === 'csv' ? 'csv' : 'xlsx');
        $name = trim(preg_replace('/[^\p{L}\p{N}]+/u', '-', 'فهارس-' . ['works' => 'آثار', 'manuscripts' => 'نسخه‌ها', 'people' => 'اشخاص', 'sources' => 'منابع'][$type] . '-' . $data['query']), '-');

        return SearchExport::download($data['results'] ?? [], $type, $request->input('format') === 'csv' ? 'csv' : 'xlsx', $name);
    }

    /** The ids of a search's results, in order, for a queued export (App\Jobs\ExportSearchResults) */
    public function exportIds(Request $request, int $limit): array
    {
        return $this->search($request->merge(['page' => 1]), $limit, idsOnly: true);
    }

    /**
     * A file larger than MEMBER_LIMIT rows, built in the queue; the user is told when it is ready (backlog item 83,
     * phase 4). For signed-in users only.
     */
    public function queueExport(Request $request)
    {
        $format = $request->input('format') === 'csv' ? 'csv' : 'xlsx';
        $params = $request->except(['_token', 'format', 'page', 'per_page']);
        \App\Jobs\ExportSearchResults::dispatch($request->user()->id, $params, $format);
        $data = ['query' => TextNormalizer::normalize((string) ($params['q'] ?? '')), 'type' => in_array($params['type'] ?? null, self::TYPES, true) ? $params['type'] : 'works'];
        $this->logSearch($request, $data['query'], $data['type'], (string) ($params['scope'] ?? 'titles_names'), SearchFilters::fromRequest($request, $data['type']),
            (int) $request->input('total', 0), 1, kind: SearchLog::KIND_EXPORT, format: "{$format}-queued");

        return back()->with('success', 'ساخت فایل کامل آغاز شد. وقتی آماده شد، پیوند دانلودش را در اعلان‌ها و ایمیل‌تان می‌فرستیم.');
    }

    /** A queued export's file, by its signed and expiring link */
    public function downloadExport(string $file)
    {
        abort_unless(preg_match('/^[a-f0-9]{32}\.(xlsx|csv)$/', $file, $m), 404);
        $path = Storage::disk('public')->path(SearchExport::DIRECTORY . '/' . $file);
        abort_unless(is_file($path), 404, 'این فایل دیگر نگه داشته نمی‌شود. خروجی را دوباره بگیرید.');

        return response()->download($path, 'فهارس-خروجی.' . $m[1], ['Content-Type' => SearchExport::contentType($m[1])]);
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
     * Searching one field only, beside the three general scopes (backlog item 83, phase 5): «only the scribe»,
     * «only the incipit and explicit»… key => [label, attributes]
     */
    public const FIELD_SCOPES = [
        'works' => [
            'author' => ['پدیدآور', ['author_name', 'author_aliases']],
            'texts' => ['آغاز و انجام', ['incipit_text', 'explicit_text', 'manuscript_texts']],
            'description' => ['معرفی اثر', ['description']],
        ],
        'manuscripts' => [
            'author' => ['پدیدآور', ['author_name']],
            'scribe' => ['کاتب', ['scribe_name']],
            'commissioned' => ['سفارش‌دهنده', ['commissioned_by']],
            'texts' => ['آغاز و انجام', ['incipit_text', 'explicit_text']],
            'shelfmark' => ['شماره‌ی نسخه', ['shelfmark', 'shelfmark_key']],
            'place' => ['محل کتابت', ['copy_place']],
        ],
        'people' => [],
        'sources' => [],
    ];

    public static function scopeAvailable(string $type, string $scope): bool
    {
        return in_array($scope, ['titles', 'titles_names', 'all'], true) || isset(self::FIELD_SCOPES[$type][$scope]);
    }

    /**
     * Attributes each search tab searches on for a scope; null means every searchable attribute ("all").
     */
    public static function scopeAttributes(string $type, string $scope): ?array
    {
        if (isset(self::FIELD_SCOPES[$type][$scope])) {
            return self::FIELD_SCOPES[$type][$scope][1];
        }

        return [
            'works' => [
                'titles' => ['primary_title', 'clean_title', 'alternative_titles'],
                'titles_names' => ['primary_title', 'clean_title', 'alternative_titles', 'author_name'],
            ],
            'manuscripts' => [
                'titles' => ['work_title'],
                // The shelfmark and library too, so «مجلس ۱۱۸۹» finds the manuscript, and the library's city, so «قم»
                // narrows a work's manuscripts to Qom's (backlog item 83)
                'titles_names' => ['work_title', 'author_name', 'shelfmark', 'scribe_name', 'library', 'library_full_name', 'city'],
            ],
            'people' => [
                'titles' => ['name'],
                'titles_names' => ['name', 'normalized_name', 'transliteration'],
            ],
            // An entry is searched in its text by default: that is what this tab is for
            'sources' => [
                'titles' => ['title'],
                'titles_names' => ['title', 'text'],
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
        $indexes = self::INDEXES;
        $counts = [$type => $currentTotal];
        try {
            $meili = app(MeiliClient::class);
            foreach ($indexes as $tab => $index) {
                // A field of this tab alone («only the scribe») is not searched in the others
                if ($tab === $type || ! self::scopeAvailable($tab, $scope)) {
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

