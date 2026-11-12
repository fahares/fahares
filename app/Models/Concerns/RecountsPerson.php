<?php

namespace App\Models\Concerns;

use App\Models\Person;

/**
 * A row of work_people or manuscript_people that is added, removed, or given to another person or role changes
 * what that person is: the counts and the author and scribe flags of the people it names follow (step 14.153).
 * Rows written with DB::table run no hook; their writers call Person::recountMany themselves.
 */
trait RecountsPerson
{
    protected static function bootRecountsPerson(): void
    {
        static::saved(function ($row) {
            if ($row->wasRecentlyCreated || $row->wasChanged(['person_id', 'role'])) {
                Person::recountMany([$row->person_id, $row->getPrevious()['person_id'] ?? null]);
            }
        });
        static::deleted(fn ($row) => Person::recountMany([$row->person_id]));
    }
}
