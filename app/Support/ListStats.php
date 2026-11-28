<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The small summary of a long list on a record page (backlog item 106): the copies of an author's works, of a work,
 * a library, a subject or a scribe, with the three first cities of copying, the copies of each century and the share
 * of autographs and illuminated copies. It is the light part of the scope's insights page (item 107), read in one
 * pass over the scope's copies with no temporary table, built when asked for and kept a day per record.
 *
 * The count of the copies, which decides whether a page shows the summary and its button, is an indexed count and is
 * read with the page. The summary itself reads every copy of the list (13 s for a library of 44 000 copies on a cold
 * buffer), so a page draws it only from the cache; otherwise the page asks for it after it is shown (the route
 * list-stats), and the first reader of the day builds it under a lock.
 */
class ListStats
{
    /** The roles a record page shows a summary for: the parameter of /insights */
    public const PARAMS = ['author', 'work', 'library', 'subject', 'scribe'];

    private const CENTURY = 'CASE WHEN copy_date_from IS NOT NULL AND copy_date_to IS NOT NULL AND FLOOR((copy_date_from - 1) / 100) = FLOOR((copy_date_to - 1) / 100) THEN FLOOR((copy_date_from - 1) / 100) + 1 END';

    /** @var array<string, mixed> what this request read already: the button and the summary of a page read it once */
    private static array $read = [];

    public static function valid(string $param, int $id): bool
    {
        return in_array($param, self::PARAMS, true) && $id > 0;
    }

    public static function min(): int
    {
        return (int) config('insights.list_stats_min', 30);
    }

    /** The copies of the list, kept a day; 0 for an unknown record */
    public static function total(string $param, int $id): int
    {
        if (! self::valid($param, $id)) {
            return 0;
        }
        $key = self::key('total', $param, $id);

        return self::$read[$key] ??= (int) Cache::remember($key, self::ttl(), function () use ($param, $id) {
            $scope = InsightsScope::fromArray([$param => $id]);

            return $scope->isEmpty() ? 0 : $scope->count();
        });
    }

    /** Whether the list is long enough for a summary and the way to its insights page */
    public static function shown(string $param, int $id): bool
    {
        return self::total($param, $id) >= self::min();
    }

    /** The summary if it is in the cache, without building it */
    public static function cached(string $param, int $id): ?array
    {
        if (! self::shown($param, $id)) {
            return null;
        }
        $key = self::key('summary', $param, $id);

        return self::$read[$key] ??= Cache::get($key);
    }

    /**
     * The summary, built if need be, or null for a list shorter than config insights.list_stats_min.
     *
     * @return array{param: string, id: int, total: int, cities: array<int, array{city: string, count: int}>, placed: int, centuries: array<int, int>, dated: int, autograph: int, illuminated: int}|null
     */
    public static function for(string $param, int $id): ?array
    {
        if (($stats = self::cached($param, $id)) !== null || ! self::shown($param, $id)) {
            return $stats;
        }
        $key = self::key('summary', $param, $id);

        // One reader builds it; the others wait for it rather than read the same copies again
        return self::$read[$key] = Cache::lock($key . '.lock', 120)->block(90, fn () => Cache::get($key)
            ?? tap(self::build($param, $id), fn ($stats) => Cache::put($key, $stats, self::ttl())));
    }

    public static function forget(string $param, int $id): void
    {
        foreach (['total', 'summary'] as $what) {
            unset(self::$read[self::key($what, $param, $id)]);
            Cache::forget(self::key($what, $param, $id));
        }
    }

    /** The link to the scope's insights page, with more filters (a city, a century) if given */
    public static function url(string $param, int $id, array $more = []): string
    {
        return route('insights', [$param => $id] + $more);
    }

    public static function build(string $param, int $id): array
    {
        $scope = InsightsScope::fromArray([$param => $id]);
        $stats = ['param' => $param, 'id' => $id, 'total' => 0, 'cities' => [], 'placed' => 0, 'centuries' => [], 'dated' => 0, 'autograph' => 0, 'illuminated' => 0];
        if ($scope->isEmpty()) {
            return $stats;
        }

        // One pass over the copies: by the text of the place and the century, with the flags summed
        $rows = $scope->copies()
            ->selectRaw("NULLIF(copy_place, '') AS p, " . self::CENTURY . ' AS c, COUNT(*) AS n, COALESCE(SUM(is_autograph), 0) AS a, COALESCE(SUM(is_illuminated), 0) AS i')
            ->groupBy('p', 'c')->get();

        [$first, $last] = config('insights.centuries');
        $cities = [];
        $cityOf = [];
        foreach ($rows as $row) {
            $n = (int) $row->n;
            $stats['total'] += $n;
            $stats['autograph'] += (int) $row->a;
            $stats['illuminated'] += (int) $row->i;
            // The city of the place as the map reads it (CopyPlaces)
            if ($row->p !== null && ($city = $cityOf[$row->p] ??= (CopyPlaces::cityOf((string) $row->p) ?? ''))) {
                $cities[$city] = ($cities[$city] ?? 0) + $n;
            }
            // The copies dated within one century, as the timeline of the insights page counts them
            if ($row->c !== null && $row->c >= $first && $row->c <= $last) {
                $stats['centuries'][(int) $row->c] = ($stats['centuries'][(int) $row->c] ?? 0) + $n;
            }
        }
        ksort($stats['centuries']);
        $stats['dated'] = array_sum($stats['centuries']);
        $stats['placed'] = array_sum($cities);
        uksort($cities, fn ($a, $b) => [$cities[$b], $a] <=> [$cities[$a], $b]);
        $stats['cities'] = collect($cities)->take(3)->map(fn ($n, $city) => ['city' => $city, 'count' => $n])->values()->all();

        return $stats;
    }

    /** A share in per cent for the page: «۱۲٫۵» below ten, whole above; «کمتر از ۰٫۱» for a few in many */
    public static function percent(int $n, int $total): string
    {
        if ($total <= 0 || $n <= 0) {
            return '۰';
        }
        $p = 100 * $n / $total;
        if ($p < 0.1) {
            return 'کمتر از ۰٫۱';
        }

        return Fa::digits(str_replace('.', '٫', (string) ($p < 10 ? round($p, 1) : round($p))));
    }

    private static function key(string $what, string $param, int $id): string
    {
        return "list-stats:v1:{$what}:{$param}:{$id}";
    }

    private static function ttl(): int
    {
        return (int) config('insights.list_stats_ttl', 86400);
    }
}
