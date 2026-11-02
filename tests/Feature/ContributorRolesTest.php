<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Manuscript;
use App\Models\ManuscriptPerson;
use App\Models\Person;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkPerson;
use App\Suggestions\ContributorRelations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A work's authors and a copy's scribes are rows of work_people and manuscript_people (step 13.151). */
class ContributorRolesTest extends TestCase
{
    private function person(string $name): Person
    {
        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'test-roles-' . uniqid()]);
    }

    private function work(array $attributes = []): Work
    {
        return Work::create($attributes + ['primary_title' => 'اثر آزمون نقش‌ها', 'clean_title' => 'اثر آزمون نقش‌ها',
            'slug' => 'test-roles-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    public function test_the_authors_and_scribes_stay_apart_from_the_other_roles()
    {
        $author = $this->person('مؤلف آزمون');
        $dedicatee = $this->person('اهداشده‌ی آزمون');
        $work = $this->work(['author_id' => $author->id, 'author_name' => $author->name]);
        app(ContributorRelations::class)->addRole($work, 'dedicatee', $dedicatee, null, null, null);
        $scribe = $this->person('کاتب آزمون');
        $copy = Manuscript::create(['work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'shelfmark' => '۱',
            'scribe_id' => $scribe->id, 'scribe_name' => $scribe->name]);
        app(ContributorRelations::class)->addRole($copy, 'owner', null, 'مالک آزمون', null, null);

        $this->assertSame([[WorkPerson::AUTHOR, $author->id, 0]], WorkPerson::where('work_id', $work->id)->where('role', WorkPerson::AUTHOR)->get()
            ->map(fn ($r) => [$r->role, $r->person_id, $r->position])->all());
        $work->refresh();
        $this->assertSame([$author->id], $work->authorEntries->pluck('person_id')->all());
        $this->assertSame(['dedicatee'], $work->workPeople->pluck('role')->all());
        $this->assertSame([[$author->id], [$dedicatee->id]], [$work->toSearchableArray()['author_ids'], $work->toSearchableArray()['related_person_ids']]);
        $this->assertSame([[$work->id], []], [$author->authoredWorks()->pluck('works.id')->all(), $author->workRoles()->pluck('id')->all()]);
        $this->assertSame(1, Person::withCount('works')->find($author->id)->works_count);

        $this->assertSame([$scribe->id], $copy->fresh()->scribeEntries->pluck('person_id')->all());
        $this->assertSame(['owner'], $copy->otherPeople()->pluck('role')->all());
        $this->assertSame([[$copy->id], []], [$scribe->scribedManuscripts()->pluck('manuscripts.id')->all(), $scribe->manuscriptRoles()->pluck('id')->all()]);
        $this->assertSame(['شخص مرتبط' => 1], array_intersect_key(\App\Suggestions\StructurePreview::counts($work), ['شخص مرتبط' => 0]));
    }

    public function test_a_deleted_person_takes_their_author_and_scribe_rows_and_leaves_the_other_roles()
    {
        $person = $this->person('شخص حذفی آزمون');
        $work = $this->work();
        $other = $this->work();
        $author = DB::table('work_people')->insertGetId(['work_id' => $work->id, 'person_id' => $person->id, 'role' => WorkPerson::AUTHOR, 'position' => 1]);
        $dedicatee = DB::table('work_people')->insertGetId(['work_id' => $other->id, 'person_id' => $person->id, 'role' => 'dedicatee', 'position' => 1]);

        $person->delete();

        $this->assertFalse(DB::table('work_people')->where('id', $author)->exists());
        $this->assertTrue(DB::table('work_people')->where('id', $dedicatee)->whereNull('person_id')->exists());
    }

    public function test_the_migration_moves_the_old_rows_with_their_ids_and_a_rollback_of_an_old_log_finds_them()
    {
        $first = $this->person('مؤلف نخست آزمون');
        $second = $this->person('مؤلف دوم آزمون');
        $work = $this->work();
        DB::table('works')->where('id', $work->id)->update(['author_id' => $first->id]);
        $old = [];
        foreach ([[$first->id, null, 0, null], [$second->id, null, 1, 'جلد دوم'], [null, 'نام بی‌پیوند', 2, null]] as [$personId, $name, $position, $note]) {
            $old[] = DB::table('work_author')->insertGetId(['work_id' => $work->id, 'person_id' => $personId, 'name' => $name, 'position' => $position, 'note' => $note]);
        }

        (require database_path('migrations/2026_11_02_100001_fold_authors_and_scribes_into_people_roles.php'))->up();

        $rows = DB::table('work_people')->where('work_id', $work->id)->orderBy('position')->get();
        $this->assertSame(array_map(fn ($id) => $id + ContributorRelations::LEGACY_ID_OFFSET, $old), $rows->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([[WorkPerson::AUTHOR, $second->id, 1, 'جلد دوم'], [WorkPerson::AUTHOR, null, 2, null]],
            $rows->slice(1)->map(fn ($r) => [$r->role, $r->person_id === null ? null : (int) $r->person_id, (int) $r->position, $r->note])->values()->all());
        $this->assertSame('نام بی‌پیوند', $rows->last()->name);

        // A log written before the move names the old table and its row id
        $log = AuditLog::create(['auditable_type' => Work::class, 'auditable_id' => $work->id, 'action' => 'verify',
            'old_values' => ['contributors' => [$first->id]], 'new_values' => ['added_contributor' => ['table' => 'work_author', 'id' => $old[1], 'person_id' => $second->id], 'direct_edit' => true]]);
        $admin = User::firstOrCreate(['email' => 'test-admin@fahares.net'], ['name' => 'Admin Tester', 'password' => 'password123', 'role' => 'admin']);
        $this->actingAs($admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');

        $this->assertSame([$first->id, null], $work->fresh()->authorEntries->pluck('person_id')->all());
        $this->assertSame(3, DB::table('work_author')->where('work_id', $work->id)->count(), 'the old table is left as it was');
    }

    public function test_a_manuscript_merge_logged_before_the_move_is_undone()
    {
        $scribe = $this->person('کاتب ادغام کهن');
        $work = $this->work();
        $kept = Manuscript::create(['work_id' => $work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'shelfmark' => '۱']);
        $dup = Manuscript::create(['work_id' => $work->id, 'sequence_number' => 2, 'volume_number' => 1, 'page_start' => 1, 'shelfmark' => '۲']);
        $snapshot = ['attributes' => $dup->getAttributes(), 'pivots' => [
            'manuscript_scribe' => [['id' => 500, 'manuscript_id' => $dup->id, 'person_id' => $scribe->id, 'name' => null, 'position' => 0, 'note' => null]],
            'manuscript_script' => [], 'manuscript_people' => []]];
        // The merge copied the scribe to the kept copy (old row 501, moved with the offset) and deleted the duplicate
        DB::table('manuscript_people')->insert(['id' => 501 + ContributorRelations::LEGACY_ID_OFFSET, 'manuscript_id' => $kept->id, 'person_id' => $scribe->id,
            'role' => ManuscriptPerson::SCRIBE, 'position' => 0]);
        $dup->delete();

        app(\App\Suggestions\RecordMerger::class)->undoManuscriptMerge($kept, ['source_snapshot' => $snapshot, 'added_scribe_ids' => [501]]);

        $this->assertSame([], $kept->fresh()->scribeEntries->pluck('person_id')->all());
        $this->assertSame([[500 + ContributorRelations::LEGACY_ID_OFFSET, $scribe->id]],
            Manuscript::find($dup->id)->scribeEntries->map(fn ($r) => [$r->id, $r->person_id])->all());
    }
}
