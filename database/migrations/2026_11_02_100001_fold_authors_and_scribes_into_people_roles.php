<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A work's authors join its other people in work_people with the role «author», and a copy's scribes join
     * manuscript_people with the role «scribe» (backlog item 35, step 13.151): one role-bearing table per record
     * type. Each row keeps its person or name, its position and its note. The old tables' unique (record, person)
     * is kept by the code that writes the rows: a unique (record, role, person) would also refuse the twin owners
     * or dedicatees a merge of two records may bring together. works.author_id/author_name and
     * manuscripts.scribe_id/scribe_name stay as the primary person and the printed name. A row's id is its old id
     * plus 10,000,000 (ContributorRelations::LEGACY_ID_OFFSET), so an audit log written before this step finds the
     * row it names.
     * work_author and manuscript_scribe are left as they were, unused, and go in a later step.
     */
    public function up(): void
    {
        $offset = 10_000_000; // ContributorRelations::LEGACY_ID_OFFSET
        DB::statement("INSERT INTO work_people (id, work_id, person_id, name, role, note, position)
            SELECT id + {$offset}, work_id, person_id, name, 'author', note, position FROM work_author ORDER BY id");
        DB::statement("INSERT INTO manuscript_people (id, manuscript_id, person_id, name, role, note, position)
            SELECT id + {$offset}, manuscript_id, person_id, name, 'scribe', note, position FROM manuscript_scribe ORDER BY id");
    }

    public function down(): void
    {
        foreach ([['work_author', 'work_people', 'work_id', 'author'], ['manuscript_scribe', 'manuscript_people', 'manuscript_id', 'scribe']] as [$old, $new, $key, $role]) {
            DB::table($old)->delete();
            DB::statement("INSERT IGNORE INTO {$old} ({$key}, person_id, name, note, position)
                SELECT {$key}, person_id, name, note, LEAST(position, 255) FROM {$new} WHERE role = '{$role}' ORDER BY id");
            DB::table($new)->where('role', $role)->delete();
        }
    }
};
