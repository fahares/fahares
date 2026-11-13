<?php

namespace Tests\Feature;

use App\Models\DateConflict;
use App\Models\FieldSuggestion;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\User;
use App\Models\Work;
use App\Support\DateEstimates\Loader;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The note beside a conflicting date on the public pages, its answers, and the suggestions tied to the conflict
 * (step 14.169)
 */
class DateConflictNotesTest extends TestCase
{
    private Person $author;

    private Work $work;

    private Manuscript $copy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->author = Person::create(['name' => 'مؤلف آزمایشی یادداشت', 'normalized_name' => 'مؤلف آزمایشی یادداشت', 'slug' => 'note-' . uniqid(), 'death_year_hijri' => 1280]);
        DB::table('people')->where('id', $this->author->id)->update(['death_from' => 1280, 'death_to' => 1280]);
        $this->work = Work::create(['primary_title' => 'اثر یادداشت‌دار آزمایشی', 'clean_title' => 'اثر یادداشت‌دار آزمایشی', 'slug' => 'note-' . uniqid(),
            'volume_number' => 1, 'page_start' => 1, 'composition_date_raw' => '1300ق', 'composition_year_hijri' => 1300]);
        DB::table('works')->where('id', $this->work->id)->update(['composition_date_from' => 1300, 'composition_date_to' => 1300]);
        DB::table('work_people')->insert(['work_id' => $this->work->id, 'person_id' => $this->author->id, 'role' => 'author', 'position' => 0]);
        $library = Library::create(['name' => 'کتابخانه‌ی یادداشت آزمایشی', 'city' => 'قم', 'slug' => 'lib-' . uniqid()]);
        $this->copy = Manuscript::create(['work_id' => $this->work->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1,
            'library_id' => $library->id, 'library' => $library->name, 'city' => 'قم', 'shelfmark' => 'یادداشت ۱', 'raw_text' => '1. قم', 'copy_date_raw' => '1299ق']);
        // a copy one year before the work: a conflict, but within the rounding between calendars
        DB::table('manuscripts')->where('id', $this->copy->id)->update(['copy_date_from' => 1299, 'copy_date_to' => 1299]);

        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();
    }

    private function conflict(string $rule): DateConflict
    {
        return DateConflict::where('rule', $rule)->sole();
    }

    public function test_the_work_and_the_person_show_the_conflict_with_its_answers_to_every_visitor()
    {
        $conflict = $this->conflict(Loader::RULE_AUTHOR);

        // the work's page: its own date opens the form in place, the author's death goes to the author's page
        $this->get(route('works.show', $this->work))->assertOk()
            ->assertSee('ناسازگاری تاریخ:')->assertSee('تألیف پس از وفات مؤلف')->assertSee('مؤلف آزمایشی یادداشت')->assertSee('کدام نادرست است؟')
            ->assertSee('تألیف این اثر نادرست است')->assertSee('وفات «مؤلف آزمایشی یادداشت» نادرست است')
            ->assertSee('این اثر از این شخص نیست')->assertSee('هر دو تاریخ درست‌اند')
            ->assertSee('suggest=field.death_year_hijri&amp;conflict=' . $conflict->id, false)
            // the copy of 1299 is only a year off, so it is left out
            ->assertDontSee('کتابت نسخه پیش از تألیف اثر');

        // the person's page: the tie is the work's, so that answer goes to the work's page with its text
        $this->get(route('people.show', $this->author))->assertOk()
            ->assertSee('تألیف پس از وفات مؤلف')->assertSee('وفات این شخص نادرست است')->assertSee('تألیف «اثر یادداشت‌دار آزمایشی» نادرست است')
            ->assertSee('suggest=general&amp;conflict=' . $conflict->id . '&amp;prefill=', false);

        $this->get(route('manuscripts.show', $this->copy))->assertOk()->assertDontSee('ناسازگاری تاریخ:');
    }

    public function test_a_dismissed_conflict_is_not_shown()
    {
        $this->conflict(Loader::RULE_AUTHOR)->update(['dismissed_at' => now()]);

        $this->get(route('works.show', $this->work))->assertOk()->assertDontSee('ناسازگاری تاریخ:');
    }

    public function test_an_answer_is_stored_with_its_conflict_and_shown_to_the_reviewer()
    {
        $conflict = $this->conflict(Loader::RULE_AUTHOR);
        $other = $this->conflict(Loader::RULE_COPY);

        $this->suggestJson(['type' => 'person', 'id' => $this->author->id, 'kind' => 'field', 'field_name' => 'death_year_hijri',
            'suggested_value' => '1310', 'rationale_citation' => 'اعیان الشیعة، ج ۲', 'date_conflict_id' => $conflict->id])->assertSuccessful();
        // a conflict that does not name the record is not tied
        $this->suggestJson(['type' => 'person', 'id' => $this->author->id, 'kind' => 'general',
            'suggested_value' => 'گزارشی بی‌ربط به آن ناسازگاری', 'date_conflict_id' => $other->id])->assertSuccessful();

        $answer = FieldSuggestion::where('field_name', 'death_year_hijri')->where('suggestable_id', $this->author->id)->sole();
        $this->assertSame($conflict->id, $answer->date_conflict_id);
        $this->assertNull(FieldSuggestion::where('kind', 'general')->where('suggestable_id', $this->author->id)->sole()->date_conflict_id);

        $editor = User::create(['name' => 'داور آزمایشی', 'email' => 'note-editor@fahares.net', 'password' => 'password123', 'role' => 'editor']);
        // answered conflicts come first, each with what readers said
        $this->actingAs($editor)->get(route('admin.date-conflicts.index'))->assertOk()
            ->assertSeeInOrder(['تألیف پس از وفات مؤلف', 'پاسخ خوانندگان:', '1310', 'کتابت نسخه پیش از تألیف اثر']);
        $this->actingAs($editor)->get(route('admin.date-conflicts.index', ['conflict' => $conflict->id]))->assertOk()
            ->assertSee('تألیف پس از وفات مؤلف')->assertDontSee('کتابت نسخه پیش از تألیف اثر');
        $this->actingAs($editor)->get(route('admin.suggestions.show', $answer))->assertOk()
            ->assertSee('پاسخ به یک ناسازگاری تاریخ:')->assertSee('conflict=' . $conflict->id, false);

        // once the dates are mended the conflict goes, and the answer stays without it
        DB::table('people')->where('id', $this->author->id)->update(['death_from' => 1310, 'death_to' => 1310]);
        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();
        $this->assertNull($answer->refresh()->date_conflict_id);
    }
}
