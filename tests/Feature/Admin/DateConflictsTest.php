<?php

namespace Tests\Feature\Admin;

use App\Models\DateConflict;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\User;
use App\Models\Work;
use App\Support\DateEstimates\Loader;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The panel's «تناقض‌های تاریخی»: the conflicts fahares:estimate-dates found, to open, mend or dismiss (step 14.167)
 */
class DateConflictsTest extends TestCase
{
    private User $editor;

    private Work $work;

    private int $copy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->editor = User::create(['name' => 'داور آزمایشی', 'email' => 'conflict-editor@fahares.net', 'password' => 'password123', 'role' => 'editor']);
        $author = Person::create(['name' => 'مؤلف آزمایشی تناقض‌نما', 'normalized_name' => 'مؤلف آزمایشی تناقض‌نما', 'slug' => 'conflict-' . uniqid()]);
        DB::table('people')->where('id', $author->id)->update(['death_from' => 1280, 'death_to' => 1280]);
        $this->work = Work::create(['primary_title' => 'اثر متناقض‌نمای آزمایشی', 'clean_title' => 'اثر متناقض‌نمای آزمایشی', 'slug' => 'conflict-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        DB::table('works')->where('id', $this->work->id)->update(['composition_date_from' => 1300, 'composition_date_to' => 1300]);
        DB::table('work_people')->insert(['work_id' => $this->work->id, 'person_id' => $author->id, 'role' => 'author', 'position' => 0]);
        $library = Library::create(['name' => 'کتابخانه‌ی تناقض آزمایشی', 'city' => 'قم', 'slug' => 'lib-' . uniqid()]);
        $copy = Manuscript::create(['work_id' => $this->work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1,
            'library_id' => $library->id, 'library' => $library->name, 'city' => 'قم', 'shelfmark' => 'تناقض ۱', 'raw_text' => '1. قم']);
        DB::table('manuscripts')->where('id', $copy->id)->update(['copy_date_from' => 1250, 'copy_date_to' => 1250, 'copy_date_raw' => '1250ق']);
        $this->copy = $copy->id;

        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();
    }

    public function test_only_reviewers_open_the_page()
    {
        $researcher = User::create(['name' => 'پژوهشگر آزمایشی', 'email' => 'conflict-researcher@fahares.net', 'password' => 'password123', 'role' => 'researcher']);
        $conflict = DateConflict::firstOrFail();

        $this->get(route('admin.date-conflicts.index'))->assertRedirect();
        $this->actingAs($researcher)->get(route('admin.date-conflicts.index'))->assertForbidden();
        $this->actingAs($researcher)->post(route('admin.date-conflicts.dismiss', $conflict))->assertForbidden();
        $this->actingAs($this->editor)->get(route('admin.date-conflicts.index'))->assertOk();
    }

    public function test_conflicts_are_listed_widest_first_with_their_records_and_filtered_by_rule()
    {
        // the work of 1300 against its copy of 1250 (50 years) and its author's death in 1280 (20 years)
        $this->actingAs($this->editor)->get(route('admin.date-conflicts.index'))->assertOk()
            ->assertSeeInOrder(['کتابت نسخه پیش از تألیف اثر', 'تألیف پس از وفات مؤلف'])
            ->assertSee('اثر متناقض‌نمای آزمایشی')->assertSee('مؤلف آزمایشی تناقض‌نما')->assertSee('کتابخانه‌ی تناقض آزمایشی')
            ->assertSee(route('manuscripts.permalink', $this->copy))->assertSee('«۱۲۵۰ق»', false);

        $this->actingAs($this->editor)->get(route('admin.date-conflicts.index', ['sort' => 'small']))
            ->assertSeeInOrder(['تألیف پس از وفات مؤلف', 'کتابت نسخه پیش از تألیف اثر']);

        $this->actingAs($this->editor)->get(route('admin.date-conflicts.index', ['rule' => Loader::RULE_AUTHOR]))
            ->assertSee('تألیف پس از وفات مؤلف')->assertDontSee('کتابت نسخه پیش از تألیف اثر');

        $this->actingAs($this->editor)->get(route('admin.date-conflicts.index', ['kind' => 'indirect']))->assertOk()->assertSee('موردی نیست');
    }

    public function test_a_dismissed_conflict_leaves_the_list_stays_dismissed_after_a_run_and_can_come_back()
    {
        $conflict = DateConflict::where('rule', Loader::RULE_COPY)->sole();

        $this->actingAs($this->editor)->post(route('admin.date-conflicts.dismiss', $conflict), ['note' => 'تاریخ نسخه‌ی اصل است'])->assertRedirect();
        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();

        $conflict->refresh();
        $this->assertSame([$this->editor->id, 'تاریخ نسخه‌ی اصل است'], [$conflict->dismissed_by, $conflict->dismiss_note]);
        $this->actingAs($this->editor)->get(route('admin.date-conflicts.index'))->assertDontSee('کتابت نسخه پیش از تألیف اثر');
        $this->actingAs($this->editor)->get(route('admin.date-conflicts.index', ['view' => 'dismissed']))
            ->assertSee('کتابت نسخه پیش از تألیف اثر')->assertSee('تاریخ نسخه‌ی اصل است')->assertSee('داور آزمایشی');

        $this->actingAs($this->editor)->post(route('admin.date-conflicts.reopen', $conflict))->assertRedirect();
        $this->assertNull($conflict->refresh()->dismissed_at);
        $this->actingAs($this->editor)->get(route('admin.date-conflicts.index'))->assertSee('کتابت نسخه پیش از تألیف اثر');
    }
}
