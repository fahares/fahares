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
        $this->library = Library::create(['name' => 'کتابخانۀ آزمایشی تاریخ', 'city' => 'قم', 'slug' => 'lib-' . uniqid()]);
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

    public function test_a_copying_or_a_composition_that_lasted_is_a_start_and_an_end()
    {
        // a copy written from 1052 to 1057 (step 14.222): the work was there by 1052, the scribe alive in 1057
        $author = $this->person('مؤلف آزمایشی مدت');
        $work = $this->work('اثر بی‌تاریخ آزمایشی مدت', null, $author);
        $copy = $this->copy($work, 1052);
        DB::table('manuscripts')->where('id', $copy)->update(['copy_date_to' => 1057, 'copy_date_kind' => 'span']);
        $scribe = $this->person('کاتب آزمایشی مدت');
        DB::table('manuscript_people')->insert(['manuscript_id' => $copy, 'person_id' => $scribe->id, 'role' => 'scribe', 'position' => 0]);
        // the same years with no known kind: one date somewhere in them, as before
        $other = $this->work('اثر بی‌تاریخ آزمایشی نامعلوم', null, $this->person('مؤلف آزمایشی نامعلوم'));
        $plain = $this->copy($other, 1052);
        DB::table('manuscripts')->where('id', $plain)->update(['copy_date_to' => 1057]);
        $plainScribe = $this->person('کاتب آزمایشی نامعلوم');
        DB::table('manuscript_people')->insert(['manuscript_id' => $plain, 'person_id' => $plainScribe->id, 'role' => 'scribe', 'position' => 0]);
        // a work written from 1100 to 1110: its author was alive in 1110 and fifteen by 1100
        $lasting = $this->person('مؤلف آزمایشی تألیف مدت');
        $long = $this->work('اثر آزمایشی تألیف مدت', 1100, $lasting);
        DB::table('works')->where('id', $long->id)->update(['composition_date_to' => 1110, 'composition_date_kind' => 'span']);
        // a scribe who died before the copying ended: a conflict, though he outlived its start
        $early = $this->person('کاتب آزمایشی زودمرده');
        DB::table('people')->where('id', $early->id)->update(['death_year_hijri' => 1055, 'death_from' => 1055, 'death_to' => 1055]);
        // (on a copy of its own: two dates that conflict give no bound to anything else)
        $late = $this->copy($work, 1052);
        DB::table('manuscripts')->where('id', $late)->update(['copy_date_to' => 1057, 'copy_date_kind' => 'span']);
        DB::table('manuscript_people')->insert(['manuscript_id' => $late, 'person_id' => $early->id, 'role' => 'scribe', 'position' => 0]);

        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();

        $this->assertSame(1052, (int) $this->estimate('work', $work->id, 'composition')->year_to);
        $this->assertSame(1057, (int) $this->estimate('work', $other->id, 'composition')->year_to);
        $this->assertSame([1057, 1042], [(int) $this->estimate('person', $scribe->id, 'death')->year_from, (int) $this->estimate('person', $scribe->id, 'birth')->year_to]);
        $this->assertSame([1052, 1047], [(int) $this->estimate('person', $plainScribe->id, 'death')->year_from, (int) $this->estimate('person', $plainScribe->id, 'birth')->year_to]);
        $this->assertSame([1110, 1085], [(int) $this->estimate('person', $lasting->id, 'death')->year_from, (int) $this->estimate('person', $lasting->id, 'birth')->year_to]);
        // a documented date gets no estimate, whether it is one date or two
        $this->assertNull($this->estimate('manuscript', $copy, 'copy'));
        $conflict = DB::table('date_conflicts')->where('rule', Loader::RULE_SCRIBE)->where('b_id', $early->id)->sole();
        $this->assertSame(['manuscript', $late, 'copy', 1057, 'death', 1055, 2], [$conflict->a_type, (int) $conflict->a_id, $conflict->a_field, (int) $conflict->a_from, $conflict->b_field, (int) $conflict->b_to, (int) $conflict->gap]);
    }

    public function test_the_approved_periods_of_a_life_bound_the_birth_and_the_death()
    {
        $period = fn (Person $person, array $date, array $more = []) => \App\Models\PersonPeriod::create(array_merge(['person_id' => $person->id, 'kind' => 'residence', 'place' => 'اصفهان'],
            \App\Models\PersonPeriod::dateColumns($date), $more));
        // two periods: born by the start of the first, alive at the end of the last (step 14.226)
        $person = $this->person('شخص آزمایشی دوره و تخمین');
        $period($person, ['mode' => 'span', 'start' => 1000, 'start_to' => 1005, 'end' => 1030]);
        $period($person, ['mode' => 'point', 'start' => 1040]);
        // what says nothing: a proposal, a rejected reading, a period marked uncertain
        $period($person, ['mode' => 'point', 'start' => 1090], ['status' => 'proposed']);
        $period($person, ['mode' => 'point', 'start' => 900], ['status' => 'rejected']);
        $period($person, ['mode' => 'point', 'start' => 1095], ['period_certainty' => 'probable']);
        // nor do the place of a birth or a death with the year a biography gives it, nor the place the person was from:
        // they are no stretch of the life (step 14.358)
        $period($person, ['mode' => 'point', 'start' => 1098], ['kind' => 'death']);
        $period($person, ['mode' => 'point', 'start' => 990], ['kind' => 'birth']);
        $period($person, ['mode' => 'point', 'start' => 1099], ['kind' => 'origin']);
        // a period after the death, and one before the birth: conflicts of the person's own dates
        $dead = $this->person('شخص آزمایشی دوره پس از وفات');
        DB::table('people')->where('id', $dead->id)->update(['death_year_hijri' => 1020, 'death_from' => 1020, 'death_to' => 1020, 'birth_year_hijri' => 1010]);
        $period($dead, ['mode' => 'span', 'start' => 1000, 'end' => 1030]);
        // a biography's year of death two years off the record's is no conflict of a period
        $near = $this->person('شخص آزمایشی وفات زندگی‌نامه');
        DB::table('people')->where('id', $near->id)->update(['death_year_hijri' => 1240, 'death_from' => 1240, 'death_to' => 1240]);
        $period($near, ['mode' => 'point', 'start' => 1242], ['kind' => 'death']);

        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();

        $death = $this->estimate('person', $person->id, 'death');
        $birth = $this->estimate('person', $person->id, 'birth');
        $this->assertSame([1040, 'period', 'person', $person->id], [(int) $death->year_from, $death->from_rule, $death->from_evidence_type, (int) $death->from_evidence_id]);
        $this->assertSame([1005, 'period'], [(int) $birth->year_to, $birth->to_rule]);
        // the periods themselves are documented: no estimate is written of them
        $this->assertSame(0, DB::table('date_estimates')->where('field', 'period')->count());
        $this->assertSame(['زنده در 1040 ق', 'دوره‌ای از زندگی‌اش که در همین صفحه ثبت است تا این سال بوده است.'],
            [\App\Support\DateEstimates\EstimateText::aliveIn($person->id)['text'], \App\Support\DateEstimates\EstimateText::aliveIn($person->id)['reasons'][0]['text']]);

        $conflicts = DB::table('date_conflicts')->where('rule', Loader::RULE_PERIOD)->where('a_id', $dead->id)->orderBy('a_field')->get();
        $this->assertSame([['birth', 1010, 'period', 1000, 10], ['period', 1030, 'death', 1020, 10]],
            $conflicts->map(fn ($c) => [$c->a_field, (int) $c->a_from, $c->b_field, (int) $c->b_to, (int) $c->gap])->all());
        $this->assertSame(['دوره‌ای از زندگی پیش از تولد', 'دوره‌ای از زندگی پس از وفات'], \App\Models\DateConflict::whereIn('id', $conflicts->pluck('id'))->orderBy('a_field')->get()->map->label->all());

        $this->assertSame(0, DB::table('date_conflicts')->where('rule', Loader::RULE_PERIOD)->where('a_id', $near->id)->count());

        // the pages that show a conflict take the new field: the person's own page, and the panel's list and queue
        $this->get(route('people.show', $person))->assertOk()->assertSee('زنده در ۱۰۴۰ ق');
        $this->get(route('people.show', $dead))->assertOk()->assertSee('دوره‌ای از زندگی پس از وفات')->assertSee('دوره‌ای از زندگی پیش از تولد');
        $admin = \App\Models\User::create(['name' => 'Admin Tester', 'email' => 'period-engine-' . uniqid() . '@fahares.net', 'password' => 'password123', 'role' => 'admin']);
        $this->actingAs($admin)->get(route('admin.date-conflicts.index', ['rule' => Loader::RULE_PERIOD]))->assertOk()->assertSee('دورۀ زندگی')->assertSee('۱۰۰۰–۱۰۳۰');
        $card = (new \App\Review\DateConflictQueue)->card(\App\Models\DateConflict::find($conflicts[1]->id));
        $this->assertSame(['دورۀ زندگی', '1000–1030', 'وفات'], [$card['sides']['a']['field'], $card['sides']['a']['date'], $card['sides']['b']['field']]);
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
        DB::table('date_conflicts')->where('id', $conflict->id)->update(['dismissed_at' => now(), 'dismiss_note' => 'تاریخ نسخۀ اصل']);
        DB::table('manuscripts')->where('id', $copy)->update(['copy_date_from' => 1260, 'copy_date_to' => 1260]);
        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();

        $again = DB::table('date_conflicts')->sole();
        $this->assertSame([$conflict->id, 'تاریخ نسخۀ اصل', 1260, 40], [$again->id, $again->dismiss_note, $again->b_to, $again->gap]);

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
