<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Keeps the primary person column (works.author_id, manuscripts.scribe_id) and its pivot
 * row at position 0 in step when a record is saved through Eloquent.
 * Bulk query updates bypass this and must update the pivot themselves.
 */
trait SyncsPrimaryContributor
{
    /** @return array{0: string, 1: string, 2: string} pivot table, record key, primary column */
    abstract protected static function primaryContributorPivot(): array;

    /**
     * After a bulk change of the primary column: put that person at position 0 and
     * renumber the others in their existing order.
     */
    public static function normalizeContributorPositions(array $ids): void
    {
        [$table, $key, $column] = static::primaryContributorPivot();

        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $primary = DB::table((new static)->getTable())->whereIn('id', $chunk)->pluck($column, 'id');
            $groups = DB::table($table)->whereIn($key, $chunk)->orderBy('position')->get()->groupBy($key);

            foreach ($groups as $recordId => $rows) {
                $first = (int) ($primary[$recordId] ?? 0);
                $ordered = $rows->sortBy(fn($row) => (int) $row->person_id === $first ? -1 : (int) $row->position)->values();
                foreach ($ordered as $position => $row) {
                    if ((int) $row->position !== $position) {
                        DB::table($table)->where($key, $recordId)->where('person_id', $row->person_id)->update(['position' => $position]);
                    }
                }
            }
        }
    }

    protected static function bootSyncsPrimaryContributor(): void
    {
        static::saved(function ($record) {
            [$table, $key, $column] = static::primaryContributorPivot();
            // wasRecentlyCreated stays true on the instance after later updates, so check the change first
            $changed = $record->wasChanged($column);
            if (! $changed && ! $record->wasRecentlyCreated) {
                return;
            }

            $old = $changed ? ($record->getPrevious()[$column] ?? null) : null;
            $new = $record->{$column};
            $rows = DB::table($table)->where($key, $record->getKey());

            if ($old) {
                (clone $rows)->where('person_id', $old)->delete();
            }
            if ($new) {
                if ((clone $rows)->where('person_id', $new)->exists()) {
                    (clone $rows)->where('person_id', $new)->update(['position' => 0]);
                } else {
                    DB::table($table)->insert([$key => $record->getKey(), 'person_id' => $new, 'position' => 0]);
                }
            }
        });
    }
}
