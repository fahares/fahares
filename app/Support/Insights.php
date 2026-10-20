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
    private const CACHE_KEY = 'insights.v1';

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

        return [
            'built_at' => now()->toIso8601String(),
            'totals' => $totals,
            'timeline' => self::timeline($totals['manuscripts']),
            'places' => self::places($totals['manuscripts']),
            'scripts' => self::scripts($totals['manuscripts']),
            'languages' => self::languages($totals['works']),
            'top_works' => self::topWorks(),
        ];
    }

    private static function timeline(int $total): array
    {
        [$from, $to] = config('insights.centuries');
        $rows = DB::table('manuscripts')
            ->whereNotNull('copy_date_hijri_year')
            ->selectRaw('FLOOR((copy_date_hijri_year - 1) / 10) AS decade, COUNT(*) AS n')
            ->groupBy('decade')
            ->pluck('n', 'decade');

        $centuries = [];
        for ($c = $from; $c <= $to; $c++) {
            $decades = [];
            for ($d = 0; $d < 10; $d++) {
                $decades[] = (int) ($rows[($c - 1) * 10 + $d] ?? 0);
            }
            $centuries[] = ['century' => $c, 'count' => array_sum($decades), 'decades' => $decades];
        }

        $dated = (int) $rows->sum();
        $shown = array_sum(array_column($centuries, 'count'));

        return [
            'centuries' => $centuries,
            'dated' => $dated,
            'shown' => $shown,
            'outside' => $dated - $shown,
            'coverage' => self::share($shown, $total),
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
