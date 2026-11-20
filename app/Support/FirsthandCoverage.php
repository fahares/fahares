<?php

namespace App\Support;

use App\Console\Commands\FirsthandCoverageCommand;
use App\Models\Library;
use Illuminate\Support\Facades\Cache;

/**
 * The public face of `fahares:firsthand-coverage` (backlog item 147, step 14.256): how many copies have their core
 * fields standing on the library's own printed catalogue, read from the report the command keeps in the cache (built
 * each night). An empty or malformed cache shows nothing; the pages never run the measure themselves.
 */
class FirsthandCoverage
{
    /** The libraries the insights page lists, those with the most copies read first */
    public const SHOWN = 15;

    /** The report, or null when there is none yet */
    public static function report(): ?array
    {
        $report = Cache::get(FirsthandCoverageCommand::CACHE_KEY);
        if (! is_array($report) || ! isset($report['total']['copies'], $report['libraries']) || ! is_array($report['libraries'])) {
            return null;
        }

        return $report;
    }

    /**
     * For the insights page: the totals and the libraries with copies read, or null when none was read.
     *
     * @return array{measured_at: ?string, copies: int, read: int, complete: int, libraries: list<array>, more: int}|null
     */
    public static function summary(): ?array
    {
        if (! $report = self::report()) {
            return null;
        }
        $libraries = collect($report['libraries'])->filter(fn ($l) => ($l['was_read'] ?? 0) > 0 && ($l['copies'] ?? 0) > 0)
            ->sortByDesc('was_read')->values()->map(fn ($l) => self::row($l));
        // the libraries as they are now: a library merged or removed since the measure drops out
        $models = Library::whereIn('id', $libraries->pluck('id'))->get(['id', 'name', 'city', 'slug'])->keyBy('id');
        $libraries = $libraries->filter(fn ($l) => isset($models[$l['id']]))->map(fn ($l) => [
            'key' => $models[$l['id']]->getRouteKey(), 'name' => $models[$l['id']]->name, 'city' => $models[$l['id']]->city,
        ] + $l)->values();
        if ($libraries->isEmpty()) {
            return null;
        }

        return [
            'measured_at' => $report['measured_at'] ?? null,
            'copies' => (int) $report['total']['copies'],
            'read' => (int) ($report['total']['was_read'] ?? 0),
            'complete' => (int) ($report['total']['complete'] ?? 0),
            'libraries' => $libraries->take(self::SHOWN)->all(),
            'more' => max(0, $libraries->count() - self::SHOWN),
        ];
    }

    /** For a library's page: its row, only when some of its copies were read */
    public static function forLibrary(int $libraryId): ?array
    {
        $row = collect(self::report()['libraries'] ?? [])->first(fn ($l) => (int) ($l['library_id'] ?? 0) === $libraryId);

        return $row && ($row['was_read'] ?? 0) > 0 && ($row['copies'] ?? 0) > 0 ? self::row($row) : null;
    }

    /** The share of $n in $of, in percent with one decimal */
    public static function share(int $n, int $of): float
    {
        return $of > 0 ? round($n * 100 / $of, 1) : 0.0;
    }

    private static function row(array $l): array
    {
        $copies = (int) $l['copies'];
        $complete = (int) ($l['complete'] ?? 0);

        return [
            'id' => (int) $l['library_id'], 'name' => (string) ($l['name'] ?? '—'), 'city' => $l['city'] ?? null,
            'copies' => $copies, 'read' => (int) $l['was_read'], 'complete' => $complete, 'share' => self::share($complete, $copies),
        ];
    }
}
