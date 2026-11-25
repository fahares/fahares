<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkPerson;
use App\Suggestions\ProtectedColumns;
use App\Suggestions\RecordMerger;
use App\Suggestions\RecordRemover;
use App\Suggestions\RecordSplitter;
use App\Suggestions\StructurePreview;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * People connected with a work other than its authors (work_people, backlog item 35): the work and person
 * pages, suggestions to add or remove one, the structural tools that carry the rows along.
 */
class WorkPeopleTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->admin = User::create(['name' => 'Admin Tester', 'email' => 'test-admin@fahares.net', 'password' => 'password123', 'role' => 'admin']);
        $this->admin->forceFill(['email_verified_at' => now()])->save();
    }

    private function person(string $name, array $attributes = []): Person
    {
        return Person::create($attributes + ['name' => $name, 'normalized_name' => \App\Helpers\TextNormalizer::normalize($name), 'slug' => 'test-wp-' . uniqid()]);
    }

    private function work(array $attributes = []): Work
    {
        return Work::create($attributes + [
            'primary_title' => 'اثر آزمایشی ' . uniqid(),
            'clean_title' => 'اثر آزمایشی',
            'slug' => 'test-wp-work-' . uniqid(),
            'volume_number' => 1,
            'page_start' => 1,
        ]);
    }

    private function relate($record, string $key, $other, array $extra = [])
    {
        return $this->suggestJson($extra + [
            'type' => \App\Suggestions\SuggestionRegistry::typeKey($record::class), 'id' => $record->id, 'kind' => 'relation',
            'field_name' => $key, 'target_id' => $other?->id, 'suggested_value' => '', 'rationale_citation' => 'دیباچۀ اثر',
        ]);
    }

    private function pendingFor($record): FieldSuggestion
    {
        return FieldSuggestion::where('suggestable_type', $record::class)->where('suggestable_id', $record->id)->where('status', 'pending')->latest('id')->firstOrFail();
    }

    private function approve(FieldSuggestion $s, array $data = [])
    {
        return $this->actingAs($this->admin)->post("/admin/suggestions/{$s->id}/approve", $data + [
            'applied_value' => $s->suggested_value,
            'revision_category' => 'scholarly_correction',
        ]);
    }

    private function rollbackLatest($record): void
    {
        $log = AuditLog::where('auditable_type', $record::class)->where('auditable_id', $record->id)->where('action', 'verify')->latest('id')->first();
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
    }

    public function test_people_connected_with_a_work_in_other_roles()
    {
        $translator = $this->person('قسطا بن لوقا');
        $work = $this->work();
        $this->get(route('works.show', $work))->assertOk()->assertSee('اشخاص مرتبط با اثر')->assertSee('افزودن مترجم، تقدیم‌شده به');

        // A work's role, and a person from the list or a name
        $this->relate($work, 'person_role', null, ['role' => 'translator'])->assertStatus(422)->assertJsonValidationErrors('role_name');
        $this->relate($work, 'person_role', $translator, ['role' => 'owner'])->assertStatus(422)->assertJsonValidationErrors('role');
        $this->relate($work, 'person_role', $translator, ['role' => 'translator', 'credit_name' => 'پژوهشگر آزمون'])->assertOk();
        $linked = $this->pendingFor($work);
        $this->relate($work, 'person_role', null, ['role' => 'dedicatee', 'role_name' => 'فتحعلی شاه'])->assertOk();
        $named = $this->pendingFor($work);

        $this->actingAs($this->admin)->get("/admin/suggestions/{$linked->id}")->assertOk()->assertSee('اشخاص مرتبط با اثر')->assertSee('قطعیت انتساب');
        $this->approve($linked, ['role' => 'translator', 'role_link' => 'target', 'certainty' => 'probable'])->assertRedirect('/admin/suggestions');
        $this->approve($named, ['role' => 'dedicatee', 'role_link' => 'name', 'role_name' => 'فتحعلی شاه'])->assertRedirect('/admin/suggestions');

        $this->assertTrue($translator->fresh()->is_translator);
        $this->assertSame('probable', WorkPerson::where('work_id', $work->id)->where('role', 'translator')->value('certainty'));
        $this->get(route('works.show', $work))->assertOk()
            ->assertSee('مترجم')->assertSee('قسطا بن لوقا')->assertSee('محتمل')->assertSee('به پیشنهاد پژوهشگر آزمون')
            ->assertSee('تقدیم‌شده به')->assertSee('فتحعلی شاه');
        // The person page opens a tab per role that has works; the empty roles have none
        $this->get(route('people.show', $translator))->assertOk()->assertSee('ترجمه‌ها')->assertSee($work->primary_title)
            ->assertDontSee('آثار تقدیم‌شده به او')->assertSee("activeTab: 'role-translator'", false);

        // The same person in the same role twice is refused; a rollback removes the row
        $this->relate($work, 'person_role', $translator, ['role' => 'translator'])->assertOk();
        $this->approve($this->pendingFor($work), ['role' => 'translator', 'role_link' => 'target'])->assertSessionHasErrors('applied_value');
        $this->rollbackLatest($work);
        $this->assertSame([$translator->name], WorkPerson::where('work_id', $work->id)->get()->map->display_name->all());
        $this->assertSame('pending', $named->fresh()->status);

        // A reviewed change of the work's people protects them from a corpus sync
        $guard = ProtectedColumns::for(Work::class);
        $this->assertTrue($guard->protectsRows($work->id, 'people'));
        $this->assertSame(['primary_title' => 'x'], $guard->strip($work->id, ['primary_title' => 'x']));
    }

    public function test_a_wrongly_recorded_person_is_removed_and_restored()
    {
        $work = $this->work();
        $row = WorkPerson::create(['work_id' => $work->id, 'name' => 'محمد بن علی', 'role' => 'translator', 'certainty' => 'probable', 'hedge_raw' => 'ظاهراً']);
        $this->get(route('works.show', $work))->assertOk()->assertSee('ظاهراً')->assertSee('پیشنهاد حذف');

        $this->relate($work, 'person_role_removed', null, ['role' => 'translator', 'role_name' => 'محمد بن علی', 'role_row_id' => $row->id])->assertOk();
        $suggestion = $this->pendingFor($work);
        $this->assertSame($row->id, $suggestion->payload['row_id']);
        $this->actingAs($this->admin)->get("/admin/suggestions/{$suggestion->id}")->assertOk()->assertSee('شخص مرتبطی که حذف می‌شود')->assertSee('محمد بن علی');
        $this->approve($suggestion, ['role_row_id' => $row->id])->assertRedirect('/admin/suggestions');
        $this->assertNull(WorkPerson::find($row->id));
        $this->assertTrue($suggestion->fresh()->resolution['removed']);

        $this->rollbackLatest($work);
        $back = WorkPerson::find($row->id);
        $this->assertSame(['محمد بن علی', 'ظاهراً', 'probable'], [$back->name, $back->hedge_raw, $back->certainty]);
    }

    public function test_work_roles_follow_merges_splits_and_removal()
    {
        $source = $this->person('مترجم تکراری');
        $target = $this->person('مترجم اصلی');
        $work = $this->work();
        $row = WorkPerson::create(['work_id' => $work->id, 'person_id' => $source->id, 'role' => 'translator']);
        $this->assertSame(1, StructurePreview::counts($work)['شخص مرتبط']);
        $this->assertSame(1, StructurePreview::counts($source)['نقش در اثر']);
        $this->assertSame(['اثر (نقش‌های دیگر)' => 1], app(RecordRemover::class)->blockers($source));

        // A person merge carries the row, and its rollback brings it back
        $log = app(\App\Services\PersonMerger::class)->merge($source, $target, $this->admin->id);
        $this->assertSame($target->id, $row->fresh()->person_id);
        $this->assertTrue($target->fresh()->is_translator);
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $this->assertSame($source->id, $row->fresh()->person_id);

        // A split person takes the picked rows, and the undo returns them
        $result = app(RecordSplitter::class)->splitPerson($source, ['name' => 'مترجم دوم'], ['work_roles' => [$row->id]]);
        $this->assertSame($result['created_id'], $row->fresh()->person_id);
        app(RecordSplitter::class)->undo(Person::class, $source->id, $result);
        $this->assertSame($source->id, $row->fresh()->person_id);

        // A merged work moves its rows to the kept one, except a person it already lists in that role
        $kept = $this->work();
        WorkPerson::create(['work_id' => $kept->id, 'person_id' => $source->id, 'role' => 'translator']);
        $dedicatee = WorkPerson::create(['work_id' => $work->id, 'name' => 'فتحعلی شاه', 'role' => 'dedicatee']);
        $merge = app(RecordMerger::class)->mergeWork($work, $kept);
        $this->assertSame(['dedicatee', 'translator'], WorkPerson::where('work_id', $kept->id)->orderBy('role')->pluck('role')->all());
        $this->assertNull(WorkPerson::find($row->id));
        app(RecordMerger::class)->undoWorkMerge($kept, $merge);
        $this->assertSame($work->id, $row->fresh()->work_id);
        $this->assertSame($work->id, $dedicatee->fresh()->work_id);
        $this->assertSame(1, WorkPerson::where('work_id', $kept->id)->count());

        // A removed work takes its rows along; the undo restores them
        $kept->workPeople()->delete();
        Manuscript::where('work_id', $kept->id)->delete();
        $removed = app(RecordRemover::class)->remove($kept->fresh());
        $this->assertSame(0, WorkPerson::where('work_id', $kept->id)->count());
        $this->assertIsArray($removed);
    }

    public function test_the_people_of_a_work_are_in_its_search_document()
    {
        $naser = $this->person('ناصرالدین قاجار، شاه ایران', ['aliases' => ['ناصرالدین شاه']]);
        $work = $this->work();
        app(\App\Suggestions\ContributorRelations::class)->addRole($work, 'dedicatee', $naser, null, null, null);
        app(\App\Suggestions\ContributorRelations::class)->addRole($work, 'translator', null, 'آوانس ارمنی', null, null);

        $doc = $work->fresh()->toSearchableArray();
        $this->assertSame(['ناصرالدین قاجار، شاه ایران', 'ناصرالدین شاه', 'اوانس ارمنی'], $doc['related_people']);
        $this->assertSame([$naser->id], $doc['related_person_ids']);
        // Searched by default, after the titles and the author, and on its own as a field scope
        $this->assertSame('related_people', last(\App\Http\Controllers\SearchController::scopeAttributes('works', 'titles_names')));
        $this->assertSame(['related_people'], \App\Http\Controllers\SearchController::scopeAttributes('works', 'people'));
        // Indexed after the titles and the author, before the incipits
        $settings = config('scout.meilisearch.index-settings')[Work::class];
        $this->assertGreaterThan(array_search('author_aliases', $settings['searchableAttributes']), array_search('related_people', $settings['searchableAttributes']));
        $this->assertLessThan(array_search('incipit_text', $settings['searchableAttributes']), array_search('related_people', $settings['searchableAttributes']));
    }

    public function test_the_works_with_people_can_be_reindexed_alone()
    {
        $work = $this->work();
        WorkPerson::create(['work_id' => $work->id, 'name' => 'آوانس ارمنی', 'role' => 'translator']);
        $this->artisan('fahares:reindex-work-people')->expectsOutput('Reindexed 1 of 1 works')->assertSuccessful();
    }
}
