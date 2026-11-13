<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use App\Support\DateEstimates\Loader;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * fahares:estimate-dates: ranges for undocumented dates, and the documented dates that conflict (step 14.166)
 */
class EstimateDatesCommandTest extends TestCase
{
    private Library $library;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'کتابخانه‌ی آزمایشی تاریخ', 'city' => 'قم', 'slug' => 'lib-' . uniqid()]);
    }

    private function person(string $name): Person
    {
        return Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'estimate-' . md5($name)]);
    }

    private function work(string $title, ?int $year, ?Person $author = null, ?string $certainty = null): Work
    {
        $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'estimate-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        DB::table('works')->where('id', $work->id)->update(['composition_date_from' => $year, 'composition_date_to' => $year]);
        if ($author) {
            DB::table('work_people')->insert(['work_id' => $work->id, 'person_id' => $author->id, 'role' => 'author', 'position' => 0, 'certainty' => $certainty]);
        }

        return $work;
    }

    private function copy(Work $work, ?int $year, ?string $hedge = null): int
    {
        $n = ++$this->sequence;
        $copy = Manuscript::create(['work_id' => $work->id, 'sequence_number' => $n, 'volume_number' => 1, 'page_start' => 1,
            'library_id' => $this->library->id, 'library' => $this->library->name, 'city' => 'قم', 'shelfmark' => "تاریخ {$n}", 'raw_text' => "{$n}. قم"]);
        DB::table('manuscripts')->where('id', $copy->id)->update(['copy_date_from' => $year, 'copy_date_to' => $year, 'copy_date_hedge_raw' => $hedge]);

        return $copy->id;
    }

    private function estimate(string $type, int $id, string $field): ?object
    {
        return DB::table('date_estimates')->where(['subject_type' => $type, 'subject_id' => $id, 'field' => $field])->first();
    }

    public function test_it_only_counts_without_apply()
    {
        $this->work('اثر تاریخ‌دار آزمایشی', 1200, $this->person('مؤلف آزمایشی شمارش'));

        $this->artisan('fahares:estimate-dates')->expectsOutputToContain('Counted only. Use --apply to write.')->assertSuccessful();

        $this->assertSame(0, DB::table('date_estimates')->count());
        $this->assertSame(0, DB::table('date_conflicts')->count());
    }

    public function test_estimates_carry_their_rule_evidence_and_depth()
    {
        $author = $this->person('مؤلف آزمایشی تخمین');
        $dated = $this->work('اثر تاریخ‌دار آزمایشی', 1200, $author);
        $undated = $this->work('اثر بی‌تاریخ آزمایشی', null, $author);
        $copy = $this->copy($undated, 1230);
        $undatedCopy = $this->copy($undated, null);
        // a guessed author gives nothing, nor does a copy dated by «the author's time»
        $guessed = $this->person('مؤلف آزمایشی مشکوک');
        $this->work('اثر منسوب آزمایشی', 1100, $guessed, 'doubtful');
        $relative = $this->work('اثر عصر مؤلف آزمایشی', null);
        $this->copy($relative, 1050, 'در عصر مؤلف');

        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();

        $death = $this->estimate('person', $author->id, 'death');
        $this->assertSame([1200, 1285], [$death->year_from, $death->year_to]);
        $this->assertSame([Loader::RULE_AUTHOR, 'work', $dated->id, 'composition', 1], [$death->from_rule, $death->from_evidence_type, $death->from_evidence_id, $death->from_evidence_field, $death->from_depth]);
        $this->assertSame([Loader::RULE_LIFE, 'person', $author->id, 'birth', 2], [$death->to_rule, $death->to_evidence_type, $death->to_evidence_id, $death->to_evidence_field, $death->to_depth]);

        $birth = $this->estimate('person', $author->id, 'birth');
        $this->assertSame([1100, 1185], [$birth->year_from, $birth->year_to]);

        // the undated work: after its author's youth (three rules away), before its oldest copy
        $composition = $this->estimate('work', $undated->id, 'composition');
        $this->assertSame([1115, 1230, 3, 1], [$composition->year_from, $composition->year_to, $composition->from_depth, $composition->to_depth]);
        $this->assertSame([Loader::RULE_COPY, 'manuscript', $copy], [$composition->to_rule, $composition->to_evidence_type, $composition->to_evidence_id]);

        // documented dates get no estimate; the undated copy has no bound within three rules
        $this->assertNull($this->estimate('work', $dated->id, 'composition'));
        $this->assertNull($this->estimate('manuscript', $copy, 'copy'));
        $this->assertNull($this->estimate('manuscript', $undatedCopy, 'copy'));
        $this->assertNull($this->estimate('person', $guessed->id, 'death'));
        $this->assertNull($this->estimate('work', $relative->id, 'composition'));
        $this->assertSame(0, DB::table('date_conflicts')->count());
    }

    public function test_a_conflict_is_listed_kept_with_its_dismissal_and_dropped_once_mended()
    {
        $author = $this->person('مؤلف آزمایشی تناقض');
        $work = $this->work('اثر متناقض آزمایشی', 1300, $author);
        $copy = $this->copy($work, 1250);

        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();

        $conflict = DB::table('date_conflicts')->sole();
        $this->assertSame([Loader::RULE_COPY, 1, 'work', $work->id, 'composition', 1300, 'manuscript', $copy, 'copy', 1250, 50],
            [$conflict->rule, $conflict->direct, $conflict->a_type, $conflict->a_id, $conflict->a_field, $conflict->a_from, $conflict->b_type, $conflict->b_id, $conflict->b_field, $conflict->b_to, $conflict->gap]);
        // neither date is used: the author is not «alive in 1300»
        $this->assertNull($this->estimate('person', $author->id, 'death'));
        $this->assertNull($this->estimate('work', $work->id, 'composition'));

        // a reviewer's dismissal survives the next run, and the changed year is taken up
        DB::table('date_conflicts')->where('id', $conflict->id)->update(['dismissed_at' => now(), 'dismiss_note' => 'تاریخ نسخه‌ی اصل']);
        DB::table('manuscripts')->where('id', $copy)->update(['copy_date_from' => 1260, 'copy_date_to' => 1260]);
        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();

        $again = DB::table('date_conflicts')->sole();
        $this->assertSame([$conflict->id, 'تاریخ نسخه‌ی اصل', 1260, 40], [$again->id, $again->dismiss_note, $again->b_to, $again->gap]);

        // mended: the conflict goes, and the dates give estimates again
        DB::table('manuscripts')->where('id', $copy)->update(['copy_date_from' => 1310, 'copy_date_to' => 1310]);
        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('date_conflicts')->count());
        $this->assertSame(1300, $this->estimate('person', $author->id, 'death')->year_from);
    }

    public function test_bounds_that_leave_no_year_are_an_indirect_conflict()
    {
        $author = $this->person('مؤلف آزمایشی غیرمستقیم');
        DB::table('people')->where('id', $author->id)->update(['birth_year_hijri' => 1000]);
        $work = $this->work('اثر بی‌تاریخ غیرمستقیم', null, $author);
        $copy = $this->copy($work, 1005);

        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();

        $conflict = DB::table('date_conflicts')->where('via_id', $work->id)->where('via_type', 'work')->sole();
        $this->assertSame([0, 'person', $author->id, 'birth', 1015, 'manuscript', $copy, 1005, 10],
            [$conflict->direct, $conflict->a_type, $conflict->a_id, $conflict->a_field, $conflict->a_from, $conflict->b_type, $conflict->b_id, $conflict->b_to, $conflict->gap]);
        $this->assertNull($this->estimate('work', $work->id, 'composition'));
    }

    public function test_the_hold_out_test_writes_nothing()
    {
        $this->work('اثر تاریخ‌دار آزمایشی', 1200, $this->person('مؤلف آزمایشی سنجش'));

        $this->artisan('fahares:estimate-dates', ['--validate' => true])->expectsOutputToContain('two-sided')->assertSuccessful();

        $this->assertSame(0, DB::table('date_estimates')->count());
    }
}
