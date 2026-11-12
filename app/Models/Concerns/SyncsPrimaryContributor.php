<?php

namespace App\Models\Concerns;

use App\Models\Person;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the primary person column (works.author_id, manuscripts.scribe_id) and its row of the people
 * table (work_people role author, manuscript_people role scribe) at position 0 in step when a record is
 * saved through Eloquent, and with them the counts and the author or scribe flag of the people it gains or loses,
 * also when the record is deleted (step 14.153). Bulk query updates bypass this and must update the rows themselves.
 */
trait SyncsPrimaryContributor
{
    /** @return array{0: string, 1: string, 2: string, 3: string} people table, record key, primary column, role */
    abstract protected static function primaryContributorPivot(): array;

    /**
     * After a bulk change of the primary column: put that person at position 0 and
     * renumber the others in their existing order.
     */
    public static function normalizeContributorPositions(array $ids): void
    {
        [$table, $key, $column, $role] = static::primaryContributorPivot();

        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $primary = DB::table((new static)->getTable())->whereIn('id', $chunk)->pluck($column, 'id');
            $groups = DB::table($table)->whereIn($key, $chunk)->where('role', $role)->orderBy('position')->get()->groupBy($key);

            foreach ($groups as $recordId => $rows) {
                $first = (int) ($primary[$recordId] ?? 0);
                $ordered = $rows->sortBy(fn($row) => $first && (int) $row->person_id === $first ? -1 : (int) $row->position)->values();
                foreach ($ordered as $position => $row) {
                    if ((int) $row->position !== $position) {
                        DB::table($table)->where('id', $row->id)->update(['position' => $position]);
                    }
                }
            }
        }
    }

    /** The people of a record being deleted, by its key: its rows are gone (cascade) before they can be recounted */
    protected static array $contributorsOfDeleted = [];

    protected static function bootSyncsPrimaryContributor(): void
    {
        static::deleting(function ($record) {
            [$table, $key, , $role] = static::primaryContributorPivot();
            static::$contributorsOfDeleted[$record->getKey()] = DB::table($table)->where($key, $record->getKey())->where('role', $role)
                ->whereNotNull('person_id')->pluck('person_id')->all();
        });
        static::deleted(function ($record) {
            $people = static::$contributorsOfDeleted[$record->getKey()] ?? [];
            unset(static::$contributorsOfDeleted[$record->getKey()]);
            Person::recountMany($people);
        });

        static::saved(function ($record) {
            [$table, $key, $column, $role] = static::primaryContributorPivot();
            // wasRecentlyCreated stays true on the instance after later updates, so check the change first
            $changed = $record->wasChanged($column);
            if (! $changed && ! $record->wasRecentlyCreated) {
                return;
            }

            $old = $changed ? ($record->getPrevious()[$column] ?? null) : null;
            $new = $record->{$column};
            $rows = DB::table($table)->where($key, $record->getKey())->where('role', $role);

            if ($old) {
                (clone $rows)->where('person_id', $old)->delete();
            }
            if ($new) {
                if ((clone $rows)->where('person_id', $new)->exists()) {
                    (clone $rows)->where('person_id', $new)->update(['position' => 0]);
                } else {
                    DB::table($table)->insert([$key => $record->getKey(), 'person_id' => $new, 'role' => $role, 'position' => 0]);
                }
            }
            Person::recountMany([$old, $new]);
        });
    }
}
