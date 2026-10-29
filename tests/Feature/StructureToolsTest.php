<?php

namespace Tests\Feature;

use App\Auth\Permissions;
use App\Models\AuditLog;
use App\Models\EntityRedirect;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\ManuscriptPerson;
use App\Models\Person;
use App\Models\User;
use App\Models\Work;
use App\Suggestions\Contributions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The admin's structural tools run directly from the panel (backlog item 45): a preview of the change, the reviewer's
 * own approval form as the confirmation, and the same code as an approved suggestion, logged for undo.
 */
class StructureToolsTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Permissions::flush();
        $this->admin = User::create(['name' => 'مدیر کل', 'email' => uniqid('st-admin-') . '@example.test', 'password' => 'password123', 'role' => 'admin']);
    }

    protected function library(string $name, string $city = 'قم'): Library
    {
        return Library::create(['name' => $name, 'city' => $city, 'slug' => 'st-lib-' . uniqid()]);
    }

    protected function work(array $attributes = []): Work
    {
        return Work::create($attributes + ['primary_title' => 'اثر ' . uniqid(), 'clean_title' => 'اثر', 'slug' => 'st-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    protected function manuscript(array $attributes = []): Manuscript
    {
        return Manuscript::create($attributes + ['work_id' => $attributes['work_id'] ?? $this->work()->id, 'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1]);
    }

    protected function person(string $name): Person
    {
        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'st-p-' . uniqid()]);
    }

    protected function preview($record, array $query)
    {
        $type = \App\Suggestions\SuggestionRegistry::typeKey($record::class);

        return $this->actingAs($this->admin)->get(route('admin.structure.show', [$type, $record->id]) . '?' . http_build_query($query));
    }

    protected function apply($record, array $data, ?User $user = null)
    {
        $type = \App\Suggestions\SuggestionRegistry::typeKey($record::class);

        return $this->actingAs($user ?? $this->admin)->post(route('admin.structure.apply', [$type, $record->id]), $data);
    }

    protected function direct(): FieldSuggestion
    {
        return FieldSuggestion::where('kind', 'relation')->where('payload->direct', true)->latest('id')->firstOrFail();
    }

    protected function assertDirectlyApplied(FieldSuggestion $s): void
    {
        $this->assertSame(['approved', $this->admin->id, $this->admin->id], [$s->status, $s->user_id, $s->reviewed_by]);
        $this->assertTrue($s->resolution['applied']);
        // Logged with the action, for the audit log and the undo, and shown publicly as the editors' work
        $this->assertTrue(AuditLog::activeForSuggestion($s->id)->new_values['direct']);
        $this->assertSame(Contributions::STAFF_LABEL, Contributions::contributor($s)['name']);
        Notification::assertNothingSent();
    }

    public function test_only_the_admin_holds_the_direct_tools_by_default_and_each_tool_keeps_its_permission()
    {
        $editor = User::create(['name' => 'ویراستار', 'email' => uniqid('st-ed-') . '@example.test', 'password' => 'password123', 'role' => 'editor']);
        Permissions::save(['editor' => Permissions::defaults()['editor']]);
        $library = $this->library('کتابخانه‌ی دسترسی');
        $other = $this->library('کتابخانه‌ی دیگر');
        $ms = $this->manuscript();

        $this->assertNotContains('structure.direct', Permissions::defaults()['editor']);
        $this->actingAs($editor)->get(route('admin.structure.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('admin.structure.show', ['manuscript', $ms->id]))->assertForbidden();
        $this->apply($library, ['relation' => 'duplicate', 'target' => (string) $other->id], $editor)->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.structure.index'))->assertOk()->assertSee('ابزارهای ساختاری');

        // Given the direct tools, the editor still runs only the tools their role may approve
        Permissions::save(['editor' => array_merge(Permissions::defaults()['editor'], ['structure.direct'])]);
        $this->actingAs($editor)->get(route('admin.structure.show', ['manuscript', $ms->id]))->assertOk()
            ->assertSee('انتقال نسخه به اثر دیگر')->assertDontSee('حذف رکورد زائد');
        $this->actingAs($editor)->get(route('admin.structure.show', ['library', $library->id]))->assertOk()->assertDontSee('ادغام کتابخانه‌ی تکراری');
        $this->apply($library, ['relation' => 'duplicate', 'target' => (string) $other->id], $editor)->assertForbidden();
        $this->apply($ms, ['relation' => 'redundant'], $editor)->assertForbidden();
        $this->assertTrue(Library::whereKey($library->id)->exists());
        $this->assertSame(0, FieldSuggestion::count());

        // The public form offers the panel's tools to those who hold them
        $this->actingAs($this->admin)->get(route('manuscripts.show', $ms))->assertSee('structure\\\\\\/manuscript\\\\\\/' . $ms->id . '\\u0022', false);
        $this->actingAs(User::create(['name' => 'کاربر', 'email' => uniqid('st-u-') . '@example.test', 'password' => 'password123', 'role' => 'user']))
            ->get(route('manuscripts.show', $ms))->assertSee('structureUrl\u0022:null', false);
    }

    public function test_a_duplicate_library_is_merged_directly_after_a_preview_and_the_merge_undone()
    {
        $dup = $this->library('کتابخانه‌ی تکراری');
        $kept = $this->library('کتابخانه‌ی ماندنی');
        $m = $this->manuscript(['library_id' => $dup->id, 'library' => $dup->name, 'city' => 'قم', 'shelfmark' => '7']);

        $this->preview($dup, ['relation' => 'duplicate', 'target' => 'fahares.net/libraries/' . $kept->id])->assertOk()
            ->assertSee('پیش‌نمایش: ادغام کتابخانه‌ی تکراری')->assertSee('۱ نسخه و ۰ جلد فهرست از «کتابخانه‌ی تکراری» منتقل می‌شوند')
            ->assertSee('کدام کتابخانه بماند؟')->assertSee(route('admin.structure.apply', ['library', $dup->id]), false);
        $this->assertSame(0, FieldSuggestion::count(), 'a preview files nothing');

        $this->apply($dup, ['relation' => 'duplicate', 'target' => (string) $kept->id, 'keep' => 'target'])->assertSessionHasNoErrors()->assertRedirect();
        $s = $this->direct();
        $this->assertDirectlyApplied($s);
        $this->assertNull(Library::find($dup->id));
        $this->assertSame($kept->id, $m->fresh()->library_id);
        $this->assertSame($kept->id, EntityRedirect::resolveTargetId('libraries', $dup->id));
        $this->actingAs($this->admin)->get("/admin/suggestions/{$s->id}")->assertOk()->assertSee('اقدام مستقیم، به دست مدیر کل');

        // Undone like an approval, it does not return to the review queue
        $this->actingAs($this->admin)->post("/admin/suggestions/{$s->id}/reopen")->assertSessionHas('success');
        $this->assertTrue(Library::whereKey($dup->id)->exists());
        $this->assertSame($dup->id, $m->fresh()->library_id);
        $this->assertSame('undone', $s->fresh()->status);
        $this->assertSame(0, FieldSuggestion::inQueue()->count());
    }

    public function test_a_library_is_renamed_directly()
    {
        $library = $this->library('نام قدیم');
        $this->manuscript(['library_id' => $library->id, 'library' => $library->name]);
        $this->preview($library, ['relation' => 'renamed'])->assertOk()->assertSee('نام تازه‌ی کتابخانه را بنویسید');
        $this->preview($library, ['relation' => 'renamed', 'suggested_value' => 'نام تازه'])->assertOk()->assertSee('نام کتابخانه در ۱ نسخه‌اش هم به‌روز می‌شود');
        $this->apply($library, ['relation' => 'renamed', 'suggested_value' => 'نام تازه', 'name' => 'نام تازه', 'keep_former_name' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(['نام تازه', 'نام تازه'], [$library->fresh()->name, Manuscript::where('library_id', $library->id)->value('library')]);
        $this->assertDirectlyApplied($this->direct());
    }

    public function test_a_duplicate_work_is_merged_directly_and_the_preview_names_what_moves()
    {
        $library = $this->library('کتابخانه‌ی آثار');
        $dup = $this->work(['primary_title' => 'اثر تکراری', 'description' => 'معرفی تکراری']);
        $kept = $this->work(['primary_title' => 'اثر ماندنی', 'description' => 'معرفی ماندنی']);
        $same = ['library_id' => $library->id, 'library' => $library->name, 'city' => 'قم', 'shelfmark' => '5'];
        $folded = $this->manuscript(['work_id' => $dup->id] + $same);
        $this->manuscript(['work_id' => $kept->id] + $same);
        $moved = $this->manuscript(['work_id' => $dup->id, 'shelfmark' => '6'] + $same);

        $this->preview($dup, ['relation' => 'duplicate', 'target' => (string) $kept->id])->assertOk()
            ->assertSee('۱ نسخه منتقل و ۱ نسخه‌ی هم‌نشانی ادغام می‌شود')
            ->assertSee('۱ مقدار ناسازگار به تصمیم شما در جدول مقایسه می‌ماند')->assertSee('مقایسه‌ی فیلدها');
        $this->apply($dup, ['relation' => 'duplicate', 'target' => (string) $kept->id, 'keep' => 'target'])->assertSessionHasNoErrors();

        $this->assertNull(Work::find($dup->id));
        $this->assertSame($kept->id, $moved->fresh()->work_id);
        $this->assertNull(Manuscript::find($folded->id));
        $this->assertDirectlyApplied($this->direct());
    }

    public function test_a_duplicate_manuscript_is_merged_and_a_manuscript_moved_to_another_work_directly()
    {
        $work = $this->work();
        $dup = $this->manuscript(['work_id' => $work->id, 'shelfmark' => '1', 'paper' => 'ترمه']);
        $kept = $this->manuscript(['work_id' => $work->id, 'shelfmark' => '1']);
        $this->apply($dup, ['relation' => 'duplicate', 'target' => "https://fahares.net/m/{$kept->id}", 'keep' => 'target'])->assertSessionHasNoErrors();
        $this->assertNull(Manuscript::find($dup->id));
        $this->assertSame('ترمه', $kept->fresh()->paper);
        $this->assertDirectlyApplied($this->direct());

        $to = $this->work(['primary_title' => 'اثر مقصد']);
        $this->preview($kept, ['relation' => 'move_to_work', 'target' => (string) $to->id])->assertOk()->assertSee('به اثر «اثر مقصد»');
        $this->apply($kept, ['relation' => 'move_to_work', 'target' => (string) $to->id])->assertSessionHasNoErrors();
        $this->assertSame($to->id, $kept->fresh()->work_id);
        $this->assertDirectlyApplied($this->direct());

        // The same record cannot be both sides
        $this->apply($kept, ['relation' => 'duplicate', 'target' => (string) $kept->id])->assertSessionHasErrors('target');
        $this->preview($kept, ['relation' => 'duplicate'])->assertOk()->assertSee('رکورد مقصد را انتخاب کنید');
    }

    public function test_a_conflated_work_and_person_are_split_directly()
    {
        $work = $this->work(['primary_title' => 'اثر خلط‌شده']);
        $this->manuscript(['work_id' => $work->id]);
        $go = $this->manuscript(['work_id' => $work->id, 'shelfmark' => 'دیگر']);
        $query = ['relation' => 'conflated', 'suggested_value' => 'نسخه‌ی «دیگر» از رساله‌ی دیگری است'];
        $this->preview($work, $query)->assertOk()->assertSee('عنوان اثر تازه')->assertSee('نسخه‌هایی که از اثر تازه‌اند');
        $this->apply($work, $query + ['new_title' => 'رساله‌ی جدا', 'author' => 'none', 'manuscript_ids' => [$go->id]])->assertSessionHasNoErrors();
        $created = Work::where('primary_title', 'رساله‌ی جدا')->sole();
        $this->assertSame($created->id, $go->fresh()->work_id);
        $this->assertDirectlyApplied($this->direct());

        $person = $this->person('شخص خلط‌شده');
        $authored = $this->work(['author_id' => $person->id, 'author_name' => $person->name]);
        $this->apply($person, ['relation' => 'conflated', 'suggested_value' => 'دو نفرند', 'new_name' => 'شخص جدا', 'work_ids' => [$authored->id]])->assertSessionHasNoErrors();
        $this->assertSame(Person::where('name', 'شخص جدا')->value('id'), $authored->fresh()->author_id);
        $this->assertDirectlyApplied($this->direct());
    }

    public function test_a_redundant_record_is_removed_directly_only_once_nothing_depends_on_it()
    {
        $work = $this->work();
        $ms = $this->manuscript(['work_id' => $work->id]);
        $this->preview($work, ['relation' => 'redundant'])->assertOk()->assertSee('این رکورد هنوز به ۱ نسخه پیوند دارد');
        $this->apply($work, ['relation' => 'redundant'])->assertSessionHasErrors('applied_value');
        $this->assertTrue(Work::whereKey($work->id)->exists());
        $this->assertSame(0, FieldSuggestion::count(), 'a refused action leaves no suggestion behind');

        $this->apply($ms, ['relation' => 'redundant'])->assertSessionHasNoErrors();
        $this->assertNull(Manuscript::find($ms->id));
        $s = $this->direct();
        $this->assertDirectlyApplied($s);

        // Restored from the audit log, the action is closed as undone
        $log = AuditLog::where('auditable_type', Manuscript::class)->where('auditable_id', $ms->id)->where('action', 'remove')->sole();
        $this->actingAs($this->admin)->post("/admin/audit-logs/{$log->id}/rollback")->assertSessionHas('success');
        $this->assertTrue(Manuscript::whereKey($ms->id)->exists());
        $this->assertSame('undone', $s->fresh()->status);
    }

    public function test_people_scribes_relations_and_locations_are_linked_directly()
    {
        $library = $this->library('کتابخانه‌ی نخست');
        $newLibrary = $this->library('کتابخانه‌ی تازه');
        $ms = $this->manuscript(['library_id' => $library->id, 'library' => $library->name, 'shelfmark' => '1']);
        $other = $this->manuscript(['work_id' => $ms->work_id]);
        $scribe = $this->person('کاتب درست');
        $owner = $this->person('مالک نسخه');

        $this->preview($ms, ['relation' => 'scribe_is', 'target' => (string) $scribe->id])->assertOk()->assertSee('«کاتب درست» کاتب اصلی می‌شود');
        $this->apply($ms, ['relation' => 'scribe_is', 'target' => (string) $scribe->id])->assertSessionHasNoErrors();
        $this->assertSame($scribe->id, $ms->fresh()->scribe_id);

        $this->apply($ms, ['relation' => 'person_role', 'target' => (string) $owner->id, 'role' => 'owner', 'role_link' => 'target'])->assertSessionHasNoErrors();
        $this->assertTrue(ManuscriptPerson::where('manuscript_id', $ms->id)->where('person_id', $owner->id)->where('role', 'owner')->exists());

        $this->apply($ms, ['relation' => 'manuscript_relation', 'target' => (string) $other->id, 'role' => 'collated_with'])->assertSessionHasNoErrors();
        $this->assertTrue(DB::table('manuscript_relations')->where('type', 'collated_with')->exists());

        $this->apply($ms, ['relation' => 'relocated', 'target' => (string) $newLibrary->id, 'shelfmark' => '99', 'library_choice' => 'target'])->assertSessionHasNoErrors();
        $this->assertSame([$newLibrary->id, '99'], [$ms->fresh()->library_id, $ms->fresh()->shelfmark]);

        $person = $this->person('شخص نام‌دار');
        $this->apply($person, ['relation' => 'alias', 'suggested_value' => 'شهرت دیگر', 'alias' => 'شهرت دیگر'])->assertSessionHasNoErrors();
        $this->assertContains('شهرت دیگر', (array) $person->fresh()->aliases);

        $this->assertSame(5, FieldSuggestion::where('status', 'approved')->where('payload->direct', true)->count());
        $this->assertSame(0, FieldSuggestion::inQueue()->count());
        Notification::assertNothingSent();
    }

    public function test_people_duplicates_go_to_the_people_merge_tool()
    {
        $a = $this->person('شخص یک');
        $b = $this->person('شخص دو');
        $this->preview($a, ['relation' => 'duplicate', 'target' => (string) $b->id])->assertOk()
            ->assertSee('رفتن به ابزار ادغام اشخاص')->assertSee(route('admin.people.merge', ['source_id' => $a->id, 'target_id' => $b->id]));
        $this->apply($a, ['relation' => 'duplicate', 'target' => (string) $b->id])->assertStatus(422);
        $this->assertSame(0, FieldSuggestion::count());
    }

    public function test_the_record_is_found_by_its_link_and_every_tool_of_the_registry_is_offered()
    {
        $work = $this->work();
        $this->actingAs($this->admin)->get(route('admin.structure.index', ['type' => 'work', 'ref' => "https://fahares.net/w/{$work->id}-x"]))
            ->assertRedirect(route('admin.structure.show', ['work', $work->id]));
        $this->actingAs($this->admin)->from(route('admin.structure.index'))->get(route('admin.structure.index', ['type' => 'work', 'ref' => '99999999']))
            ->assertSessionHasErrors('ref');

        $records = ['manuscript' => $this->manuscript(), 'work' => $work, 'person' => $this->person('شخص'), 'library' => $this->library('کتابخانه')];
        foreach (\App\Suggestions\SuggestionRegistry::RELATIONS as $type => $relations) {
            $page = $this->actingAs($this->admin)->get(route('admin.structure.show', [$type, $records[$type]->id]))->assertOk();
            foreach ($relations as $relation) {
                $page->assertSee($relation['action']);
            }
        }
    }
}
