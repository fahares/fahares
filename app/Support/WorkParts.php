<?php

namespace App\Support;

use App\Models\Work;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The whole-and-part tree of the works (step 14.308): the rows of work_relations of type «part», "{work_id} is a part
 * of {related_work_id}". A part reads its author, subject and language from the whole where it has none of its own
 * (Work::traitSource); nothing is copied into the part's columns, so taking the relation away leaves nothing behind.
 *
 * A work inherits only when it is a part of exactly one work, and a chain (a part of a part) is followed for
 * MAX_DEPTH steps and never twice through one work.
 *
 * The rows are read once for a request or a queued job (a scoped instance of the container); every write of a «part»
 * row through the application calls flush().
 */
class WorkParts
{
    public const TYPE = 'part';

    /** How many wholes above a part are asked: a section of a chapter of a volume of a work */
    public const MAX_DEPTH = 5;

    /** What a part reads from its whole */
    public const TRAITS = ['author', 'subject', 'language', 'composition'];

    private const KEY = 'work-parts.map';

    private const DATES_KEY = 'work-parts.dates';

    /** @return array{up: array<int, list<int>>, down: array<int, list<int>>} the wholes of each part, the parts of each whole */
    private static function map(): array
    {
        if (! app()->bound(self::KEY)) {
            app()->scoped(self::KEY, function () {
                $map = ['up' => [], 'down' => []];
                foreach (DB::table('work_relations')->where('type', self::TYPE)->orderBy('id')->get(['work_id', 'related_work_id']) as $row) {
                    $map['up'][(int) $row->work_id][] = (int) $row->related_work_id;
                    $map['down'][(int) $row->related_work_id][] = (int) $row->work_id;
                }

                return $map;
            });
        }

        return app(self::KEY);
    }

    /** After a «part» row is added, removed or moved */
    public static function flush(): void
    {
        app()->forgetInstance(self::KEY);
        app()->forgetInstance(self::DATES_KEY);
    }

    /** After a work's own date or author changed: the dates the parts read are worked out again */
    public static function flushDates(): void
    {
        app()->forgetInstance(self::DATES_KEY);
    }

    /**
     * The parts that read their date of composition from a whole (step 14.315), for what reads the dates of many
     * works at once: the lists ordered or filtered in the database, the floors of the copies' dates and the estimates.
     *
     * @return array<int, Work> part id => the work its date is read from
     */
    public static function inheritedCompositions(): array
    {
        if (! app()->bound(self::DATES_KEY)) {
            app()->scoped(self::DATES_KEY, function () {
                $ids = array_keys(self::map()['up']);
                $found = [];
                foreach ($ids ? Work::whereIn('id', $ids)->orderBy('id')->get() : [] as $part) {
                    $source = $part->traitSource('composition');
                    if (! $source->is($part)) {
                        $found[$part->id] = $source;
                    }
                }

                return $found;
            });
        }

        return app(self::DATES_KEY);
    }

    /**
     * The year of composition of each row of a query on the works, in SQL: the work's own column, or for the parts
     * that read their date from a whole, that whole's year. With no such part it is the column itself.
     */
    public static function compositionYearSql(string $column = 'works.composition_year_hijri', string $id = 'works.id'): string
    {
        $cases = '';
        foreach (self::inheritedCompositions() as $partId => $source) {
            if ($source->composition_year_hijri) {
                $cases .= ' WHEN ' . (int) $partId . ' THEN ' . (int) $source->composition_year_hijri;
            }
        }

        return $cases === '' ? $column : "(CASE {$id}{$cases} ELSE {$column} END)";
    }

    /** The one work this work is a part of; null for a work that is a part of none, or of more than one */
    public static function wholeId(int $workId): ?int
    {
        $wholes = self::map()['up'][$workId] ?? [];

        return count($wholes) === 1 && $wholes[0] !== $workId ? $wholes[0] : null;
    }

    /** @return list<int> every work this work is recorded as a part of */
    public static function wholeIds(int $workId): array
    {
        return self::map()['up'][$workId] ?? [];
    }

    /** @return list<int> the wholes above the work, the nearest first: at most MAX_DEPTH, and none twice */
    public static function chainIds(int $workId): array
    {
        $chain = [];
        $seen = [$workId => true];
        $at = $workId;
        while (count($chain) < self::MAX_DEPTH && ($at = self::wholeId($at)) !== null && ! isset($seen[$at])) {
            $seen[$at] = true;
            $chain[] = $at;
        }

        return $chain;
    }

    /** @return list<int> the works recorded as parts of this work */
    public static function partIds(int $workId): array
    {
        return self::map()['down'][$workId] ?? [];
    }

    /**
     * Every work that may read something from this one: its parts, their parts and so on, as far as a chain is
     * followed. These are sent to the search index again when the work's author, subject or language changes.
     *
     * @return list<int>
     */
    public static function descendantIds(int $workId): array
    {
        $found = [];
        $level = [$workId];
        for ($depth = 0; $depth < self::MAX_DEPTH && $level; $depth++) {
            $next = [];
            foreach ($level as $id) {
                foreach (self::partIds($id) as $part) {
                    if ($part !== $workId && ! isset($found[$part])) {
                        $found[$part] = true;
                        $next[] = $part;
                    }
                }
            }
            $level = $next;
        }

        return array_keys($found);
    }

    /** Would "{part} is a part of {whole}" make a work a part of itself, straight or through its own parts? */
    public static function wouldLoop(int $partId, int $wholeId): bool
    {
        if ($partId === $wholeId) {
            return true;
        }
        // every whole above the new whole, by any of its rows: the new part must not be among them
        $seen = [$wholeId => true];
        $level = [$wholeId];
        while ($level) {
            $next = [];
            foreach ($level as $id) {
                foreach (self::wholeIds($id) as $above) {
                    if ($above === $partId) {
                        return true;
                    }
                    if (! isset($seen[$above])) {
                        $seen[$above] = true;
                        $next[] = $above;
                    }
                }
            }
            $level = $next;
        }

        return false;
    }

    /**
     * The works that read this trait from the given work: its parts with none of their own, and the parts of those.
     *
     * @return Collection<int, Work> in the order of the tree, each with the count of its copies
     */
    public static function inheritors(Work $work, string $trait): Collection
    {
        $ids = self::descendantIds($work->id);
        if (! $ids) {
            return collect();
        }

        return Work::whereIn('id', $ids)->orderBy('id')->get()
            ->filter(fn (Work $part) => $part->traitSource($trait)->is($work))->values();
    }

    /** Sends the parts below a work to the search index again */
    public static function reindexBelow(int $workId): void
    {
        $ids = self::descendantIds($workId);
        if ($ids) {
            Work::whereIn('id', $ids)->get()->searchable();
        }
    }
}
