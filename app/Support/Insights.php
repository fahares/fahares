<?php

namespace App\Support;

use App\Helpers\TextNormalizer;
use App\Models\Work;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the statistical insights page (/insights). They read the whole manuscripts
 * table, so they are built once a day by `insights:build` and kept in the cache; a page view
 * builds them only when the cache is empty. A page narrowed to a scope (InsightsScope, backlog
 * item 107) is built when it is first asked for and kept for a day.
 */
class Insights
{
    // v2: the timelines read the date ranges and their hedges (backlog item 104)
    // v3: places of composition, movement of copies, subjects and features per century (backlog item 107)
    private const CACHE_KEY = 'insights.v3';

    // The century of a copy dated within one century (inOneCentury)
    private const CENTURY = 'FLOOR((manuscripts.copy_date_from - 1) / 100) + 1';

    // Map frame (degrees) and its SVG size, the same frame as resources/views/insights/map-base
    public const MAP = ['lon0' => 28.0, 'lon1' => 90.0, 'lat0' => 10.0, 'lat1' => 44.0, 'width' => 1000, 'height' => 633];

    public static function get(InsightsScope $scope = new InsightsScope): array
    {
        $key = self::cacheKey($scope);
        if (($data = Cache::get($key)) !== null) {
            return $data;
        }

        // One build at a time per scope: a second visitor waits for the first one's figures
        return Cache::lock($key . '.lock', 300)->block(120, function () use ($key, $scope) {
            if (($data = Cache::get($key)) !== null) {
                return $data;
            }
            // The largest scopes take some 20 s on the local copy of the data, near php-fpm's default limit
            set_time_limit(120);
            $data = self::build($scope);
            $scope->isEmpty() ? Cache::forever($key, $data) : Cache::put($key, $data, (int) config('insights.scope_ttl'));

            return $data;
        });
    }

    public static function isCached(InsightsScope $scope): bool
    {
        return Cache::has(self::cacheKey($scope));
    }

    private static function cacheKey(InsightsScope $scope): string
    {
        return $scope->isEmpty() ? self::CACHE_KEY : self::CACHE_KEY . '.scope.' . $scope->key();
    }

    public static function refresh(): array
    {
        CopyPlaces::variantMap(fresh: true);
        $data = self::build();
        Cache::forever(self::CACHE_KEY, $data);

        return $data;
    }

    public static function build(InsightsScope $scope = new InsightsScope): array
    {
        $scope->open();
        try {
            return self::figures($scope);
        } finally {
            $scope->close();
        }
    }

    private static function figures(InsightsScope $scope): array
    {
        $totals = [
            'manuscripts' => $scope->manuscripts()->count(),
            'works' => $scope->works()->count(),
        ];

        $copies = self::copyDates($scope);

        return [
            'built_at' => now()->toIso8601String(),
            'totals' => $totals,
            'timeline' => self::timeline($totals['manuscripts'], $copies['ranges']),
            'authors' => self::authors($scope),
            'compositions' => self::compositions($scope, $totals['works']),
            'copy_gap' => self::copyGap($copies['gaps']),
            'places' => self::places($scope, $totals['manuscripts'], $totals['works']),
            'movement' => self::movement($scope),
            'subjects' => self::subjectCenturies($scope),
            'features' => self::featureCenturies($scope),
            'scripts' => self::scripts($scope, $totals['manuscripts']),
            'languages' => self::languages($scope, $totals['works']),
            'top_works' => self::topWorks($scope),
        ];
    }

    /**
     * The copy dates in one pass over the manuscripts table: the ranges ({f, t, d, n}: from, to, doubtful, count) for
     * the timeline, and the gaps to the author's death ({lo, hi, d, n}) for copyGap. A join of the manuscripts to their
     * authors in SQL reads the copies row by row through the work index and takes several times longer.
     */
    private static function copyDates(InsightsScope $scope): array
    {
        $span = (int) config('insights.exact_span');
        // A range is a known year (its middle) or the whole range: [earliest, latest]
        $ends = fn (?int $f, ?int $t) => $t - $f <= $span ? [intdiv($f + $t, 2), intdiv($f + $t, 2)] : [$f, $t];
        $deaths = $scope->works()->join('people', 'people.id', '=', 'works.author_id')
            ->whereNotNull('people.death_from')->whereNotNull('people.death_to')
            ->select('works.id', 'people.death_from', 'people.death_to', 'people.death_certainty')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->id => [...$ends((int) $r->death_from, (int) $r->death_to), $r->death_certainty !== null]])
            ->all();

        $ranges = [];
        $gaps = [];
        $rows = $scope->manuscripts()
            ->where(fn ($q) => $q->whereNotNull('copy_date_from')->orWhereNotNull('copy_date_to'))
            ->selectRaw('work_id AS w, copy_date_from AS f, copy_date_to AS t, copy_date_certainty IS NOT NULL AS d, COUNT(*) AS n')
            ->groupBy('w', 'f', 't', 'd')
            ->cursor();
        foreach ($rows as $row) {
            $n = (int) $row->n;
            $key = "{$row->f}|{$row->t}|{$row->d}";
            $ranges[$key] ??= (object) ['f' => $row->f, 't' => $row->t, 'd' => (bool) $row->d, 'n' => 0];
            $ranges[$key]->n += $n;
            if ($row->f === null || $row->t === null || ! isset($deaths[$row->w])) {
                continue;
            }
            [$c0, $c1] = $ends((int) $row->f, (int) $row->t);
            [$d0, $d1, $dd] = $deaths[$row->w];
            $d = $row->d || $dd;
            $key = ($c0 - $d1) . '|' . ($c1 - $d0) . '|' . (int) $d;
            $gaps[$key] ??= (object) ['lo' => $c0 - $d1, 'hi' => $c1 - $d0, 'd' => $d, 'n' => 0];
            $gaps[$key]->n += $n;
        }

        return ['ranges' => array_values($ranges), 'gaps' => array_values($gaps)];
    }

    /** Copies per century, from the range of the copy date (copy_date_from/to, step 13.82). */
    private static function timeline(int $total, array $rows): array
    {
        [$from, $to] = config('insights.centuries');
        $layers = self::centuryLayers($rows, $from, $to);

        return $layers + ['coverage' => self::share($layers['shown'], $total)];
    }

    /** Authors (people with a work) per century of death, from the death range (death_from/to, step 13.101). */
    private static function authors(InsightsScope $scope): array
    {
        [$from, $to] = config('insights.author_centuries');
        $rows = DB::table('people')
            ->where(fn ($q) => $q->whereNotNull('death_from')->orWhereNotNull('death_to'))
            ->whereExists(fn ($q) => $q->from($scope->worksTable())->whereColumn('works.author_id', 'people.id'))
            ->selectRaw('death_from AS f, death_to AS t, death_certainty IS NOT NULL AS d, COUNT(*) AS n')
            ->groupBy('f', 't', 'd')
            ->get();
        $authors = $scope->works()->whereNotNull('author_id')->distinct()->count('author_id');
        $layers = self::centuryLayers($rows, $from, $to);

        return $layers + ['total' => $authors, 'coverage' => self::share($layers['counted'], $authors)];
    }

    /** Works per century of composition (composition_date_from/to, step 13.91). */
    private static function compositions(InsightsScope $scope, int $totalWorks): array
    {
        [$from, $to] = config('insights.author_centuries');
        $rows = $scope->works()
            ->where(fn ($q) => $q->whereNotNull('composition_date_from')->orWhereNotNull('composition_date_to'))
            ->selectRaw('composition_date_from AS f, composition_date_to AS t, composition_date_certainty IS NOT NULL AS d, COUNT(*) AS n')
            ->groupBy('f', 't', 'd')
            ->get();
        $layers = self::centuryLayers($rows, $from, $to);

        return $layers + ['coverage' => self::share($layers['counted'], $totalWorks)];
    }

    /**
     * Ranges of Hijri years ({f, t, d, n}: from, to, doubtful, count) per century, in four layers: a known year (a
     * range of up to `exact_span` years, put at its middle, also counted per decade) or only a century or a part of
     * one, spread over the centuries it covers by their share of its years; each certain or doubtful. A range with
     * one end open («پیش از 1100») is counted apart.
     */
    private static function centuryLayers(iterable $rows, int $first, int $last): array
    {
        $span = config('insights.exact_span');
        $cells = [];
        $open = 0;
        $counted = 0;
        $doubtful = 0;
        $outside = 0.0;
        foreach ($rows as $row) {
            $n = (int) $row->n;
            if ($row->f === null || $row->t === null) {
                $open += $n;

                continue;
            }
            $f = max(1, min((int) $row->f, (int) $row->t));
            $t = max(1, (int) $row->f, (int) $row->t);
            $d = (bool) $row->d;
            $counted += $n;
            $doubtful += $d ? $n : 0;
            if ($t - $f <= $span) {
                $year = intdiv($f + $t, 2);
                $c = intdiv($year - 1, 100) + 1;
                if ($c < $first || $c > $last) {
                    $outside += $n;

                    continue;
                }
                $cells[$c][$d ? 'exact_doubtful' : 'exact'] = ($cells[$c][$d ? 'exact_doubtful' : 'exact'] ?? 0) + $n;
                $decade = intdiv(($year - 1) % 100, 10);
                $cells[$c][$d ? 'dd' : 'dc'][$decade] = ($cells[$c][$d ? 'dd' : 'dc'][$decade] ?? 0) + $n;

                continue;
            }
            for ($c = intdiv($f - 1, 100) + 1; $c <= intdiv($t - 1, 100) + 1; $c++) {
                $part = $n * (min($t, $c * 100) - max($f, $c * 100 - 99) + 1) / ($t - $f + 1);
                if ($c < $first || $c > $last) {
                    $outside += $part;
                } else {
                    $cells[$c][$d ? 'range_doubtful' : 'range'] = ($cells[$c][$d ? 'range_doubtful' : 'range'] ?? 0) + $part;
                }
            }
        }

        $centuries = [];
        for ($c = $first; $c <= $last; $c++) {
            $row = ['century' => $c];
            foreach (['exact', 'exact_doubtful', 'range', 'range_doubtful'] as $layer) {
                $row[$layer] = (int) round($cells[$c][$layer] ?? 0);
            }
            $row['count'] = $row['exact'] + $row['exact_doubtful'] + $row['range'] + $row['range_doubtful'];
            $row['decades'] = array_map(fn ($d) => (int) ($cells[$c]['dc'][$d] ?? 0), range(0, 9));
            $row['decades_doubtful'] = array_map(fn ($d) => (int) ($cells[$c]['dd'][$d] ?? 0), range(0, 9));
            $centuries[] = $row;
        }
        $sum = fn (string ...$layers) => array_sum(array_map(fn ($l) => array_sum(array_column($centuries, $l)), $layers));

        return [
            'centuries' => $centuries,
            'counted' => $counted,
            'shown' => $sum('exact', 'exact_doubtful', 'range', 'range_doubtful'),
            'exact' => $sum('exact', 'exact_doubtful'),
            'range' => $sum('range', 'range_doubtful'),
            'doubtful' => $doubtful,
            'doubtful_share' => self::share($doubtful, $counted),
            'outside' => (int) round($outside),
            'open' => $open,
        ];
    }

    /**
     * The years between a copy and its author's death (copyDates): the copy's year less the death year, each the
     * middle of its range when that is a known year (exact_span), else the whole range. A copy goes to the band of
     * `copy_gap` its possible gaps all fall in, or is counted apart when they cross a band edge.
     */
    private static function copyGap(array $rows): array
    {
        $edges = config('insights.copy_gap');

        // A gap's band: the number of edges below it, so a band runs from just above one edge up to the next
        $band = fn (int $gap) => count(array_filter($edges, fn ($e) => $gap > $e));
        $bands = array_fill(0, count($edges) + 1, ['certain' => 0, 'doubtful' => 0]);
        $paired = 0;
        $placed = 0;
        $doubtful = 0;
        foreach ($rows as $row) {
            $n = (int) $row->n;
            $paired += $n;
            $b = $band((int) $row->lo);
            if ($b !== $band((int) $row->hi)) {
                continue;
            }
            $placed += $n;
            $bands[$b][$row->d ? 'doubtful' : 'certain'] += $n;
            $doubtful += $row->d ? $n : 0;
        }
        $list = [];
        foreach ($bands as $i => $counts) {
            $list[] = ['above' => $edges[$i - 1] ?? null, 'upto' => $edges[$i] ?? null, 'count' => $counts['certain'] + $counts['doubtful']] + $counts;
        }

        return [
            'bands' => $list,
            'paired' => $paired,
            'placed' => $placed,
            'unplaced' => $paired - $placed,
            'doubtful' => $doubtful,
            'doubtful_share' => self::share($doubtful, $placed),
        ];
    }

    /**
     * The cities of copying (copy_place) and of composition (works.composition_place, backlog item 107), each with
     * its count per century: of the copy's year, and of the work's composition year.
     */
    private static function places(InsightsScope $scope, int $total, int $totalWorks): array
    {
        [$from, $to] = config('insights.centuries');
        $copies = $scope->manuscripts()
            ->whereNotNull('copy_place')->where('copy_place', '!=', '')
            ->selectRaw('copy_place AS place, FLOOR((copy_date_hijri_year - 1) / 100) + 1 AS century, COUNT(*) AS n')
            ->groupBy('place', 'century')
            ->get();
        $works = $scope->works()
            ->whereNotNull('composition_place')->where('composition_place', '!=', '')
            ->selectRaw('composition_place AS place, FLOOR((composition_year_hijri - 1) / 100) + 1 AS century, COUNT(*) AS n')
            ->groupBy('place', 'century')
            ->get();

        $cities = [];
        $cityOf = [];
        $layer = function (iterable $rows, string $count, string $centuries) use (&$cities, &$cityOf, $from, $to) {
            $withPlace = 0;
            $mapped = 0;
            foreach ($rows as $row) {
                $withPlace += $row->n;
                $city = $cityOf[$row->place] ??= (CopyPlaces::cityOf($row->place) ?? '');
                if ($city === '') {
                    continue;
                }
                $mapped += $row->n;
                $cities[$city] ??= ['count' => 0, 'centuries' => [], 'works' => 0, 'work_centuries' => []];
                $cities[$city][$count] += $row->n;
                $c = (int) $row->century;
                if ($row->century !== null && $c >= $from && $c <= $to) {
                    $cities[$city][$centuries][$c] = ($cities[$city][$centuries][$c] ?? 0) + $row->n;
                }
            }

            return [$withPlace, $mapped];
        };
        [$withPlace, $mapped] = $layer($copies, 'count', 'centuries');
        [$worksWithPlace, $worksMapped] = $layer($works, 'works', 'work_centuries');

        $config = CopyPlaces::cities();
        $list = [];
        foreach ($cities as $name => $info) {
            [$x, $y] = self::project($config[$name]['lat'], $config[$name]['lon']);
            $list[] = ['name' => $name, 'x' => $x, 'y' => $y] + $info;
        }
        usort($list, fn ($a, $b) => [$b['count'], $b['works']] <=> [$a['count'], $a['works']]);

        return [
            'cities' => $list,
            'with_place' => $withPlace,
            'mapped' => $mapped,
            'coverage' => self::share($mapped, $total),
            'place_coverage' => self::share($withPlace, $total),
            'mapped_share' => self::share($mapped, $withPlace),
            'composition' => [
                'cities' => count(array_filter($list, fn ($c) => $c['works'] > 0)),
                'with_place' => $worksWithPlace,
                'mapped' => $worksMapped,
                'coverage' => self::share($worksMapped, $totalWorks),
                'place_coverage' => self::share($worksWithPlace, $totalWorks),
                'mapped_share' => self::share($worksMapped, $worksWithPlace),
            ],
        ];
    }

    /**
     * Where the copies made in a city are kept today (backlog item 107): the city of copying (copy_place) against
     * the city of the present library (manuscripts.city), both read as the cities of the map where they are one.
     */
    private static function movement(InsightsScope $scope): array
    {
        $rows = $scope->manuscripts()
            ->whereNotNull('copy_place')->where('copy_place', '!=', '')
            ->whereNotNull('city')->where('city', '!=', '')
            ->selectRaw('copy_place, city, COUNT(*) AS n')
            ->groupBy('copy_place', 'city')
            ->get();

        $flows = [];
        $cityOf = [];
        foreach ($rows as $row) {
            $from = $cityOf['p:' . $row->copy_place] ??= CopyPlaces::cityOf($row->copy_place);
            if ($from === null) {
                continue;
            }
            $to = $cityOf['c:' . $row->city] ??= (CopyPlaces::cityOf($row->city) ?? $row->city);
            $flows[$from][$to] = ($flows[$from][$to] ?? 0) + $row->n;
        }

        $shown = config('insights.movement_shown');
        $ranked = function (array $counts) use ($shown) {
            arsort($counts);

            return [
                'items' => collect(array_slice($counts, 0, $shown, true))->map(fn ($n, $name) => ['name' => (string) $name, 'count' => $n])->values()->all(),
                'other' => array_sum(array_slice($counts, $shown)),
                'other_kinds' => max(0, count($counts) - $shown),
            ];
        };
        $origins = [];
        $into = [];
        $mapped = 0;
        $stayed = 0;
        foreach ($flows as $from => $to) {
            $total = array_sum($to);
            $mapped += $total;
            $stayed += $to[$from] ?? 0;
            $origins[] = ['name' => $from, 'total' => $total, 'stayed' => $to[$from] ?? 0] + $ranked($to);
            foreach ($to as $city => $n) {
                $into[$city][$from] = $n;
            }
        }
        usort($origins, fn ($a, $b) => $b['total'] <=> $a['total']);
        $destinations = [];
        foreach ($into as $city => $from) {
            $destinations[] = ['name' => (string) $city, 'total' => array_sum($from), 'stayed' => $from[$city] ?? 0] + $ranked($from);
        }
        usort($destinations, fn ($a, $b) => $b['total'] <=> $a['total']);
        $cities = config('insights.movement_cities');

        return [
            'origins' => array_slice($origins, 0, $cities),
            'destinations' => array_slice($destinations, 0, $cities),
            'mapped' => $mapped,
            'stayed' => $stayed,
            'stayed_share' => self::share($stayed, $mapped),
        ];
    }

    /**
     * The copies of one century: those whose date falls within one century, a year or the century itself; a range
     * across two centuries («قرن 12 و 13») has no one century and is left out of the charts per century below.
     */
    private static function inOneCentury(InsightsScope $scope): \Illuminate\Database\Query\Builder
    {
        return $scope->manuscripts()
            ->whereNotNull('manuscripts.copy_date_from')->whereNotNull('manuscripts.copy_date_to')
            ->whereRaw('FLOOR((manuscripts.copy_date_from - 1) / 100) = FLOOR((manuscripts.copy_date_to - 1) / 100)');
    }

    /**
     * The share of each subject in the copies of each century (backlog item 107): the main subjects, or the
     * subjects under the scope's own subject. A work of two subjects counts in each.
     */
    private static function subjectCenturies(InsightsScope $scope): array
    {
        [$first, $last] = config('insights.centuries');
        $family = ($subject = $scope->records['subject'] ?? null) ? collect([$subject->id])->concat($subject->children->pluck('id')) : null;
        // The copies per work and century, then the subjects of each work, so no copy is counted twice in one subject
        $copies = fn () => self::inOneCentury($scope)
            ->whereRaw(self::CENTURY . ' BETWEEN ? AND ?', [$first, $last])
            ->selectRaw('manuscripts.work_id, ' . self::CENTURY . ' AS c, COUNT(*) AS n')
            ->groupBy('manuscripts.work_id', 'c');
        $subjects = DB::table('subject_work')->join('subjects', 'subjects.id', '=', 'subject_work.subject_id')
            ->when($family, fn ($q) => $q->whereIn('subject_work.subject_id', $family))
            ->distinct()->selectRaw('subject_work.work_id, ' . ($family ? 'subject_work.subject_id' : 'COALESCE(subjects.parent_id, subjects.id)') . ' AS s');

        $cells = DB::query()->fromSub($copies(), 'm')->joinSub($subjects, 'r', 'r.work_id', '=', 'm.work_id')
            ->selectRaw('r.s, m.c, SUM(m.n) AS n')->groupBy('r.s', 'm.c')->get();
        $totals = DB::query()->fromSub($copies(), 'm')
            ->whereIn('m.work_id', DB::table('subject_work')->when($family, fn ($q) => $q->whereIn('subject_id', $family))->select('work_id'))
            ->selectRaw('m.c, SUM(m.n) AS n')->groupBy('m.c')->pluck('n', 'c')->map(fn ($n) => (int) $n)->all();

        $bySubject = [];
        foreach ($cells as $cell) {
            $bySubject[$cell->s][(int) $cell->c] = (int) $cell->n;
        }
        uasort($bySubject, fn ($a, $b) => array_sum($b) <=> array_sum($a));
        $shown = array_slice($bySubject, 0, config('insights.subjects_shown'), true);
        $names = \App\Models\Subject::whereIn('id', array_keys($shown))->get(['id', 'name', 'slug'])->keyBy('id');
        $rows = [];
        foreach ($shown as $id => $counts) {
            $rows[] = ['key' => (int) $id, 'name' => $names[$id]->name ?? '', 'total' => array_sum($counts), 'cells' => $counts];
        }

        return self::centuryGrid($rows, $totals, $first, $last) + ['kinds' => count($bySubject), 'children' => (bool) $family];
    }

    /** The share of the illuminated, corrected, collated, annotated and autograph copies in each century (backlog item 107) */
    private static function featureCenturies(InsightsScope $scope): array
    {
        [$first, $last] = config('insights.centuries');
        $features = config('insights.features');
        $sums = implode(', ', array_map(fn ($f) => "SUM(manuscripts.{$f}) AS {$f}", $features));
        $byCentury = self::inOneCentury($scope)
            ->whereRaw(self::CENTURY . ' BETWEEN ? AND ?', [$first, $last])
            ->selectRaw(self::CENTURY . " AS c, COUNT(*) AS n, {$sums}")
            ->groupBy('c')
            ->get()
            ->keyBy(fn ($r) => (int) $r->c);

        $rows = [];
        foreach ($features as $feature) {
            $cells = $byCentury->map(fn ($r) => (int) $r->$feature)->filter()->all();
            $rows[] = ['key' => $feature, 'name' => SearchFilters::FLAGS[$feature], 'total' => array_sum($cells), 'cells' => $cells];
        }

        return self::centuryGrid($rows, $byCentury->map(fn ($r) => (int) $r->n)->all(), $first, $last);
    }

    /** Rows of counts per century as shares of each century's copies, for a grid of centuries */
    private static function centuryGrid(array $rows, array $totals, int $first, int $last): array
    {
        $centuries = [];
        for ($c = $first; $c <= $last; $c++) {
            $centuries[] = ['century' => $c, 'count' => $totals[$c] ?? 0];
        }
        foreach ($rows as &$row) {
            $shares = [];
            foreach ($centuries as ['century' => $c, 'count' => $n]) {
                $shares[$c] = ['count' => $row['cells'][$c] ?? 0, 'share' => self::share($row['cells'][$c] ?? 0, $n)];
            }
            $row['cells'] = $shares;
        }

        return ['rows' => $rows, 'centuries' => $centuries, 'counted' => array_sum($totals)];
    }

    private static function scripts(InsightsScope $scope, int $total): array
    {
        $rows = $scope->whereManuscript(DB::table('manuscript_script'), 'manuscript_script.manuscript_id')
            ->join('scripts', 'scripts.id', '=', 'manuscript_script.script_id')
            ->where('manuscript_script.is_primary', true)
            ->selectRaw('scripts.id, scripts.name, COUNT(*) AS n')
            ->groupBy('scripts.id', 'scripts.name')
            ->orderByDesc('n')
            ->get();

        $named = $rows->take(config('insights.scripts_shown'));
        $items = $named->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'count' => (int) $r->n])->values()->all();
        $counted = (int) $rows->sum('n');

        return [
            'items' => $items,
            'other' => $counted - (int) $named->sum('n'),
            'other_kinds' => $rows->count() - $named->count(),
            'counted' => $counted,
            'coverage' => self::share($counted, $total),
        ];
    }

    private static function languages(InsightsScope $scope, int $totalWorks): array
    {
        $links = fn () => $scope->whereWork(DB::table('language_work'), 'language_work.work_id');
        $rows = $links()
            ->join('languages', 'languages.id', '=', 'language_work.language_id')
            ->selectRaw('languages.id, languages.name, COUNT(*) AS works')
            ->groupBy('languages.id', 'languages.name')
            ->orderByDesc('works')
            ->get();
        // The copies of each language: of the whole data, the works' counts; of a scope, its own copies
        $copies = $scope->isEmpty()
            ? DB::table('language_work')->join('works', 'works.id', '=', 'language_work.work_id')
                ->selectRaw('language_work.language_id AS lang, SUM(works.manuscripts_count) AS n')->groupBy('language_work.language_id')->pluck('n', 'lang')
            : DB::table('language_work')->join($scope->manuscriptsTable(), 'manuscripts.work_id', '=', 'language_work.work_id')
                ->selectRaw('language_work.language_id AS lang, COUNT(*) AS n')->groupBy('language_work.language_id')->pluck('n', 'lang');
        $rows->each(fn ($r) => $r->manuscripts = (int) ($copies[$r->id] ?? 0));

        $shown = config('insights.languages_shown');
        $items = $rows->take($shown)->map(fn ($r) => [
            'id' => $r->id, 'name' => $r->name, 'works' => (int) $r->works, 'manuscripts' => $r->manuscripts,
        ])->all();
        $withLanguage = $links()->distinct()->count('work_id');

        return [
            'items' => $items,
            'other' => [
                'works' => (int) $rows->skip($shown)->sum('works'),
                'manuscripts' => (int) $rows->skip($shown)->sum('manuscripts'),
                'kinds' => max(0, $rows->count() - $shown),
            ],
            'with_language' => $withLanguage,
            'multilingual' => $links()->select('work_id')->groupBy('work_id')->havingRaw('COUNT(*) > 1')->get()->count(),
            'coverage' => self::share($withLanguage, $totalWorks),
        ];
    }

    /** The works with the most copies: of the whole data, by their count; of a scope, by their copies in it */
    private static function topWorks(InsightsScope $scope): array
    {
        $generic = collect(config('insights.generic_titles'))->map(fn ($t) => TextNormalizer::normalize($t))->all();
        $limit = config('insights.top_works');
        $columns = ['id', 'slug', 'primary_title', 'author_id', 'author_name', 'manuscripts_count', 'composition_year_hijri'];

        if ($scope->isEmpty()) {
            $candidates = Work::query()->with('author:id,name,slug')->orderByDesc('manuscripts_count')->limit($limit * 4)->get($columns);
            $count = fn (Work $work) => $work->manuscripts_count;
        } else {
            $counts = $scope->manuscripts()->whereNotNull('work_id')
                ->selectRaw('work_id, COUNT(*) AS n')->groupBy('work_id')->orderByDesc('n')->limit($limit * 4)
                ->pluck('n', 'work_id');
            $candidates = Work::query()->with('author:id,name,slug')->whereIn('id', $counts->keys())->get($columns)
                ->sortByDesc(fn (Work $work) => $counts[$work->id])->values();
            $count = fn (Work $work) => (int) $counts[$work->id];
        }

        $works = [];
        $skipped = [];
        $candidates->each(function (Work $work) use (&$works, &$skipped, $generic, $limit, $count) {
            if (count($works) >= $limit) {
                return false;
            }
            $row = [
                'key' => $work->getRouteKey(),
                'title' => $work->primary_title,
                'count' => $count($work),
            ];
            if (! $work->author_id && in_array(TextNormalizer::normalize($work->primary_title), $generic, true)) {
                $skipped[] = $row;

                return;
            }
            $works[] = $row + [
                'author' => $work->author?->name ?? $work->author_name,
                'author_key' => $work->author?->getRouteKey(),
            ];
        });

        return ['items' => $works, 'skipped' => $skipped];
    }

    /** @return array{0: float, 1: float} SVG coordinates on the map frame */
    public static function project(float $lat, float $lon): array
    {
        $m = self::MAP;
        $k = $m['width'] / (($m['lon1'] - $m['lon0']) * cos(deg2rad(30)));

        return [
            round(($lon - $m['lon0']) * $k * cos(deg2rad(30)), 1),
            round(($m['lat1'] - $lat) * $k, 1),
        ];
    }

    private static function share(int $part, int $whole): float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : 0.0;
    }
}
