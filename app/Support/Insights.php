<?php

namespace App\Support;

use App\Helpers\TextNormalizer;
use App\Models\Work;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the statistical insights page (/insights). They read the whole manuscripts
 * table, so they are built once a day by `insights:build` and kept in the cache; a page view
 * builds them only when the cache is empty.
 */
class Insights
{
    // v2: the timelines read the date ranges and their hedges (backlog item 104)
    private const CACHE_KEY = 'insights.v2';

    // Map frame (degrees) and its SVG size, the same frame as resources/views/insights/map-base
    public const MAP = ['lon0' => 28.0, 'lon1' => 90.0, 'lat0' => 10.0, 'lat1' => 44.0, 'width' => 1000, 'height' => 633];

    public static function get(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => self::build());
    }

    public static function refresh(): array
    {
        CopyPlaces::variantMap(fresh: true);
        $data = self::build();
        Cache::forever(self::CACHE_KEY, $data);

        return $data;
    }

    public static function build(): array
    {
        $totals = [
            'manuscripts' => DB::table('manuscripts')->count(),
            'works' => DB::table('works')->count(),
        ];

        $copies = self::copyDates();

        return [
            'built_at' => now()->toIso8601String(),
            'totals' => $totals,
            'timeline' => self::timeline($totals['manuscripts'], $copies['ranges']),
            'authors' => self::authors(),
            'compositions' => self::compositions($totals['works']),
            'copy_gap' => self::copyGap($copies['gaps']),
            'places' => self::places($totals['manuscripts']),
            'scripts' => self::scripts($totals['manuscripts']),
            'languages' => self::languages($totals['works']),
            'top_works' => self::topWorks(),
        ];
    }

    /**
     * The copy dates in one pass over the manuscripts table: the ranges ({f, t, d, n}: from, to, doubtful, count) for
     * the timeline, and the gaps to the author's death ({lo, hi, d, n}) for copyGap. A join of the manuscripts to their
     * authors in SQL reads the copies row by row through the work index and takes several times longer.
     */
    private static function copyDates(): array
    {
        $span = (int) config('insights.exact_span');
        // A range is a known year (its middle) or the whole range: [earliest, latest]
        $ends = fn (?int $f, ?int $t) => $t - $f <= $span ? [intdiv($f + $t, 2), intdiv($f + $t, 2)] : [$f, $t];
        $deaths = DB::table('works')->join('people', 'people.id', '=', 'works.author_id')
            ->whereNotNull('people.death_from')->whereNotNull('people.death_to')
            ->select('works.id', 'people.death_from', 'people.death_to', 'people.death_certainty')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->id => [...$ends((int) $r->death_from, (int) $r->death_to), $r->death_certainty !== null]])
            ->all();

        $ranges = [];
        $gaps = [];
        $rows = DB::table('manuscripts')
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
    private static function authors(): array
    {
        [$from, $to] = config('insights.author_centuries');
        $rows = DB::table('people')
            ->where(fn ($q) => $q->whereNotNull('death_from')->orWhereNotNull('death_to'))
            ->whereExists(fn ($q) => $q->from('works')->whereColumn('works.author_id', 'people.id'))
            ->selectRaw('death_from AS f, death_to AS t, death_certainty IS NOT NULL AS d, COUNT(*) AS n')
            ->groupBy('f', 't', 'd')
            ->get();
        $authors = DB::table('works')->whereNotNull('author_id')->distinct()->count('author_id');
        $layers = self::centuryLayers($rows, $from, $to);

        return $layers + ['total' => $authors, 'coverage' => self::share($layers['counted'], $authors)];
    }

    /** Works per century of composition (composition_date_from/to, step 13.91). */
    private static function compositions(int $totalWorks): array
    {
        [$from, $to] = config('insights.author_centuries');
        $rows = DB::table('works')
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

    private static function places(int $total): array
    {
        [$from, $to] = config('insights.centuries');
        $rows = DB::table('manuscripts')
            ->whereNotNull('copy_place')->where('copy_place', '!=', '')
            ->selectRaw('copy_place, FLOOR((copy_date_hijri_year - 1) / 100) + 1 AS century, COUNT(*) AS n')
            ->groupBy('copy_place', 'century')
            ->get();

        $cities = [];
        $withPlace = 0;
        $mapped = 0;
        $cityOf = [];
        foreach ($rows as $row) {
            $withPlace += $row->n;
            $city = $cityOf[$row->copy_place] ??= (CopyPlaces::cityOf($row->copy_place) ?? '');
            if ($city === '') {
                continue;
            }
            $mapped += $row->n;
            $cities[$city] ??= ['count' => 0, 'centuries' => []];
            $cities[$city]['count'] += $row->n;
            $c = (int) $row->century;
            if ($row->century !== null && $c >= $from && $c <= $to) {
                $cities[$city]['centuries'][$c] = ($cities[$city]['centuries'][$c] ?? 0) + $row->n;
            }
        }

        $config = CopyPlaces::cities();
        $list = [];
        foreach ($cities as $name => $info) {
            [$x, $y] = self::project($config[$name]['lat'], $config[$name]['lon']);
            $list[] = ['name' => $name, 'x' => $x, 'y' => $y] + $info;
        }
        usort($list, fn ($a, $b) => $b['count'] <=> $a['count']);

        return [
            'cities' => $list,
            'with_place' => $withPlace,
            'mapped' => $mapped,
            'coverage' => self::share($mapped, $total),
            'place_coverage' => self::share($withPlace, $total),
            'mapped_share' => self::share($mapped, $withPlace),
        ];
    }

    private static function scripts(int $total): array
    {
        $rows = DB::table('manuscript_script')
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

    private static function languages(int $totalWorks): array
    {
        $rows = DB::table('language_work')
            ->join('languages', 'languages.id', '=', 'language_work.language_id')
            ->join('works', 'works.id', '=', 'language_work.work_id')
            ->selectRaw('languages.id, languages.name, COUNT(*) AS works, SUM(works.manuscripts_count) AS manuscripts')
            ->groupBy('languages.id', 'languages.name')
            ->orderByDesc('works')
            ->get();

        $shown = config('insights.languages_shown');
        $items = $rows->take($shown)->map(fn ($r) => [
            'id' => $r->id, 'name' => $r->name, 'works' => (int) $r->works, 'manuscripts' => (int) $r->manuscripts,
        ])->all();
        $withLanguage = DB::table('language_work')->distinct()->count('work_id');

        return [
            'items' => $items,
            'other' => [
                'works' => (int) $rows->skip($shown)->sum('works'),
                'manuscripts' => (int) $rows->skip($shown)->sum('manuscripts'),
                'kinds' => max(0, $rows->count() - $shown),
            ],
            'with_language' => $withLanguage,
            'multilingual' => DB::table('language_work')->select('work_id')->groupBy('work_id')->havingRaw('COUNT(*) > 1')->get()->count(),
            'coverage' => self::share($withLanguage, $totalWorks),
        ];
    }

    private static function topWorks(): array
    {
        $generic = collect(config('insights.generic_titles'))->map(fn ($t) => TextNormalizer::normalize($t))->all();
        $limit = config('insights.top_works');

        $works = [];
        $skipped = [];
        Work::query()->with('author:id,name,slug')
            ->orderByDesc('manuscripts_count')
            ->limit($limit * 4)
            ->get(['id', 'slug', 'primary_title', 'author_id', 'author_name', 'manuscripts_count', 'composition_year_hijri'])
            ->each(function (Work $work) use (&$works, &$skipped, $generic, $limit) {
                if (count($works) >= $limit) {
                    return false;
                }
                $row = [
                    'key' => $work->getRouteKey(),
                    'title' => $work->primary_title,
                    'count' => $work->manuscripts_count,
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
