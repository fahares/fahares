<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\FieldSuggestion;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\SourceEntry;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkPerson;
use App\Suggestions\ProtectedColumns;
use App\Suggestions\RecordMerger;
use App\Suggestions\RecordRemover;
use App\Suggestions\RecordSplitter;
use App\Suggestions\StructurePreview;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * People connected with a work other than its authors (work_people, backlog item 35): the work and person
 * pages, suggestions to add or remove one, the structural tools that carry the rows along, and the backfill
 * from the parser v2 JSON.
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
            'field_name' => $key, 'target_id' => $other?->id, 'suggested_value' => '', 'rationale_citation' => 'دیباچه‌ی اثر',
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

    public function test_backfill_from_the_parser_v2_json()
    {
        $qusta = $this->person('قسطا بن لوقا', ['century_hijri' => 3, 'death_year_hijri' => 250]);
        $this->person('دمشقی، سعید بن یعقوب');
        $this->person('دمشقی، سعید بن یعقوب');
        $this->person('حکیم باشی، میرزا علی اکبر', ['century_hijri' => 13]);
        $works = [];
        foreach (range(1, 8) as $n) {
            $works[$n] = $this->work(['slug' => "test-backfill-v1-{$n}"]);
        }
        // Saving a work records its Fankha entry
        $talibov = $this->person('طالبوف، عبدالرحیم بن ابوطالب');
        $works[10] = $this->work(['slug' => 'test-backfill-v1-10']);
        $entry = SourceEntry::where('citable_type', Work::class)->where('citable_id', $works[1]->id)->where('origin', SourceEntry::ORIGIN_FANKHA)->firstOrFail();
        // A reviewer already changed the people of work 8
        AuditLog::create(['user_id' => $this->admin->id, 'auditable_type' => Work::class, 'auditable_id' => $works[8]->id, 'action' => 'verify', 'new_values' => ['added_person_role' => 1]]);

        $person = fn ($name, $hedge = null, $century = null) => ['name' => $name, 'name_raw' => $name, 'hedge' => $hedge, 'death' => $century ? ['century' => $century] : null];
        $json = ['works' => [
            ['key' => ['vol' => 1, 'ordinal' => 1], 'translator' => $person('قسطا بن لوقا', null, 3)],
            ['key' => ['vol' => 1, 'ordinal' => 2], 'translator' => $person('دمشقی، سعید بن یعقوب', '؟')],
            ['key' => ['vol' => 1, 'ordinal' => 3], 'translator' => $person('برجیس انگلیسی، این کتاب حاوی نام شهرهای بزرگ عالم است. مطالب بر حسب')],
            ['key' => ['vol' => 1, 'ordinal' => 4], 'translator' => $person('جان طیلر و ابوالقاسم')],
            ['key' => ['vol' => 1, 'ordinal' => 5], 'translator' => $person('نامعلوم')],
            ['key' => ['vol' => 1, 'ordinal' => 6], 'translator' => $person('حکیم باشی، میرزا علی اکبر', 'محتملا', 10)],
            ['key' => ['vol' => 1, 'ordinal' => 7], 'original_author' => $person('ابوالقاسم')],
            ['key' => ['vol' => 1, 'ordinal' => 8], 'translator' => $person('قسطا بن لوقا')],
            ['key' => ['vol' => 1, 'ordinal' => 9], 'translator' => $person('قسطا بن لوقا')],
            ['key' => ['vol' => 1, 'ordinal' => 10], 'translator' => $person('طالبوف ، عبدالرحیم بن ابوطالب (ظاهراً از روسی)')],
        ]];
        $dir = storage_path('framework/testing/fankha_v2_' . uniqid());
        File::ensureDirectoryExists($dir);
        File::put("{$dir}/fankha_vol_01.json", json_encode($json, JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:backfill-work-people', ['--dry-run' => true, '--source' => $dir, '--volume' => 1])->assertSuccessful();
        $this->assertSame(0, WorkPerson::count());

        $this->artisan('fahares:backfill-work-people', ['--source' => $dir, '--volume' => 1])->assertSuccessful();
        $rows = WorkPerson::orderBy('work_id')->get()->keyBy('work_id');
        // A unique name with an agreeing century links; the row cites the work's Fankha entry
        $this->assertSame([$qusta->id, 'translator', $entry->id], [$rows[$works[1]->id]->person_id, $rows[$works[1]->id]->role, $rows[$works[1]->id]->source_entry_id]);
        $this->assertTrue($qusta->fresh()->is_translator);
        // Two people of that name: kept as text, with the hedge
        $this->assertSame([null, 'دمشقی، سعید بن یعقوب', '؟', 'doubtful'], [$rows[$works[2]->id]->person_id, $rows[$works[2]->id]->name, $rows[$works[2]->id]->hedge_raw, $rows[$works[2]->id]->certainty]);
        // Run into the description, or «نامعلوم»: left out
        $this->assertFalse($rows->has($works[3]->id));
        $this->assertFalse($rows->has($works[5]->id));
        // Several people, a disagreeing century, one word: text only
        $this->assertNull($rows[$works[4]->id]->person_id);
        $this->assertSame([null, 'probable'], [$rows[$works[6]->id]->person_id, $rows[$works[6]->id]->certainty]);
        $this->assertSame([null, 'original_author'], [$rows[$works[7]->id]->person_id, $rows[$works[7]->id]->role]);
        // The reviewed work is left alone; ordinal 9 has no work
        $this->assertFalse($rows->has($works[8]->id));
        // The space before the comma does not hide the person; the parenthesis becomes the note
        $this->assertSame([$talibov->id, 'ظاهراً از روسی'], [$rows[$works[10]->id]->person_id, $rows[$works[10]->id]->note]);
        $this->assertSame(6, WorkPerson::count());

        // A second run adds nothing
        $this->artisan('fahares:backfill-work-people', ['--source' => $dir, '--volume' => 1])->assertSuccessful();
        $this->assertSame(6, WorkPerson::count());
        File::deleteDirectory($dir);
    }

    public function test_a_name_with_alef_madda_matches_and_an_earlier_text_row_is_linked()
    {
        // normalized_name keeps «آ» as written
        $avanes = Person::create(['name' => 'آوانس ارمنی تبریزی', 'normalized_name' => 'آوانس ارمنی تبریزی', 'slug' => 'test-wp-' . uniqid()]);
        $work = $this->work(['slug' => 'test-backfill-v2-1']);
        // An earlier run kept the name as text; a reviewer's text row of another work stays as it is
        $old = WorkPerson::create(['work_id' => $work->id, 'name' => 'آوانس ارمنی تبریزی', 'role' => 'translator']);
        $dir = storage_path('framework/testing/fankha_v2_' . uniqid());
        File::ensureDirectoryExists($dir);
        File::put("{$dir}/fankha_vol_02.json", json_encode(['works' => [
            ['key' => ['vol' => 2, 'ordinal' => 1], 'translator' => ['name' => 'آوانس ارمنی تبریزی', 'hedge' => null, 'death' => null]],
        ]], JSON_UNESCAPED_UNICODE));

        $this->artisan('fahares:backfill-work-people', ['--source' => $dir, '--volume' => 2])->assertSuccessful();
        $this->assertSame([[$old->id, $avanes->id, null]], WorkPerson::where('work_id', $work->id)->get()->map(fn ($r) => [$r->id, $r->person_id, $r->name])->all());
        $this->assertTrue($avanes->fresh()->is_translator);
        File::deleteDirectory($dir);
    }

    public function test_backfill_of_dedicatees_and_commissioners()
    {
        $naser = $this->person('ناصرالدین قاجار، شاه ایران', ['death_year_hijri' => 1313]);
        // A scribe who only shares the name: no death year in the field confirms him
        $this->person('شمس الدین محمد بن علی');
        $dated = $this->person('رستم پاشا وزیر', ['death_year_hijri' => 968]);
        $w1 = $this->work(['dedication' => 'ناصر الدین شاه (1264-1313ق) و وزیرش آقاخان نوری']);
        $w2 = $this->work(['dedication' => 'به درخواست حاجی میرزا آقاسی']);
        $w3 = $this->work(['dedication' => 'شاه عباس صفوی']);
        $w4 = $this->work(['dedication' => 'شمس الدین محمد بن علی؛ رستم پاشا وزیر (-968ق)']);
        $w5 = $this->work(['dedication' => 'ناصرالدین شاه (شاید)']);
        $reviewed = $this->work(['dedication' => 'ناصرالدین شاه']);
        AuditLog::create(['user_id' => $this->admin->id, 'auditable_type' => Work::class, 'auditable_id' => $reviewed->id, 'action' => 'verify', 'new_values' => ['added_person_role' => 1]]);
        $only = implode(',', [$w1->id, $w2->id, $w3->id, $w4->id, $w5->id, $reviewed->id]);

        $this->artisan('fahares:backfill-work-dedications', ['--dry-run' => true, '--only' => $only])->assertSuccessful();
        $this->assertSame(0, WorkPerson::count());
        $this->assertFalse(Person::where('name', 'حاج میرزا آقاسی، عباس بن مسلم')->exists());

        $this->artisan('fahares:backfill-work-dedications', ['--only' => $only])->assertSuccessful();
        $rows = fn (Work $w) => WorkPerson::where('work_id', $w->id)->orderBy('id')->get()->map(fn ($r) => [$r->role, $r->person_id, $r->name, $r->note])->all();
        // A ruler by any spelling links to the authority record; the dates go to the note
        $this->assertSame([['dedicatee', $naser->id, null, '1264-1313ق'], ['dedicatee', null, 'وزیرش آقاخان نوری', null]], $rows($w1));
        // A ruler with no record gets one, with the death year
        $aqasi = Person::where('name', 'حاج میرزا آقاسی، عباس بن مسلم')->firstOrFail();
        $this->assertSame([1265, 13], [$aqasi->death_year_hijri, $aqasi->century_hijri]);
        $this->assertSame([['commissioner', $aqasi->id, null, null]], $rows($w2));
        // An ambiguous spelling stays text; a namesake links only when the field's dates confirm him
        $this->assertSame([['dedicatee', null, 'شاه عباس صفوی', null]], $rows($w3));
        $this->assertSame([['dedicatee', null, 'شمس الدین محمد بن علی', null], ['dedicatee', $dated->id, null, '-968ق']], $rows($w4));
        $this->assertSame(['شاید', 'doubtful'], [WorkPerson::where('work_id', $w5->id)->value('hedge_raw'), WorkPerson::where('work_id', $w5->id)->value('certainty')]);
        $this->assertSame([], $rows($reviewed));
        $this->get(route('people.show', $naser))->assertOk()->assertSee('آثار تقدیم‌شده به او');
        $this->get(route('people.show', $aqasi))->assertOk()->assertSee('آثار به سفارش او');

        // A second run adds nothing, and makes no second record
        $count = WorkPerson::count();
        $this->artisan('fahares:backfill-work-dedications', ['--only' => $only])->assertSuccessful();
        $this->assertSame($count, WorkPerson::count());
        $this->assertSame(1, Person::where('name', 'حاج میرزا آقاسی، عباس بن مسلم')->count());
    }
}

