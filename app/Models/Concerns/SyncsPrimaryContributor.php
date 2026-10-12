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
