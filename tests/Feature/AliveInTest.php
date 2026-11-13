<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use App\Support\DateEstimates\EstimateText;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «زنده در» on the page of a person with no death date (step 14.172)
 */
class AliveInTest extends TestCase
{
    private Library $library;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'کتابخانه‌ی زندگی آزمایشی', 'city' => 'قم', 'slug' => 'lib-' . uniqid()]);
    }

    private function person(string $name, ?int $death = null): Person
    {
        $person = Person::create(['name' => $name, 'normalized_name' => $name, 'slug' => 'alive-' . md5($name), 'death_year_hijri' => $death]);
        DB::table('people')->where('id', $person->id)->update(['death_from' => $death, 'death_to' => $death]);

        return $person;
    }

    private function work(string $title, ?int $year, Person $author): Work
    {
        $work = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'alive-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        DB::table('works')->where('id', $work->id)->update(['composition_date_from' => $year, 'composition_date_to' => $year]);
        DB::table('work_people')->insert(['work_id' => $work->id, 'person_id' => $author->id, 'role' => 'author', 'position' => 0]);

        return $work;
    }

    private function copy(Work $work, int $from, int $to, Person $scribe): int
    {
        $n = ++$this->sequence;
        $copy = Manuscript::create(['work_id' => $work->id, 'sequence_number' => $n, 'volume_number' => 1, 'page_start' => 1, 'library_id' => $this->library->id,
            'library' => $this->library->name, 'city' => 'قم', 'shelfmark' => "زندگی {$n}", 'raw_text' => "{$n}. قم"]);
        DB::table('manuscripts')->where('id', $copy->id)->update(['copy_date_from' => $from, 'copy_date_to' => $to]);
        DB::table('manuscript_people')->insert(['manuscript_id' => $copy->id, 'person_id' => $scribe->id, 'role' => 'scribe', 'position' => 0]);
        // people.scribed_from and scribed_to are the years of the scribe's copies dated by a year, not by a century
        if ($from === $to) {
            DB::table('people')->where('id', $scribe->id)->update([
                'scribed_from' => DB::raw("LEAST(COALESCE(scribed_from, {$from}), {$from})"), 'scribed_to' => DB::raw("GREATEST(COALESCE(scribed_to, {$to}), {$to})")]);
        }

        return $copy->id;
    }

    public function test_an_author_is_alive_in_the_year_of_the_latest_dated_work()
    {
        $author = $this->person('مؤلف آزمایشی زنده');
        $this->work('اثر نخست آزمایشی زنده', 1200, $author);
        $latest = $this->work('اثر پسین آزمایشی زنده', 1240, $author);
        $dead = $this->person('مؤلف آزمایشی درگذشته', 1250);
        $this->work('اثر مؤلف درگذشته', 1230, $dead);
        $unknown = $this->person('شخص بی‌قرینه‌ی آزمایشی');

        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();

        $alive = EstimateText::aliveIn($author->id);
        $this->assertSame(['زنده در 1240 ق', 'اثرش را در این سال تألیف کرده است:', 'اثر پسین آزمایشی زنده'], [$alive['text'], $alive['reasons'][0]['text'], $alive['reasons'][0]['title']]);
        $this->get(route('people.show', $author))->assertOk()
            ->assertSeeInOrder(['وفات', 'زنده در ۱۲۴۰ ق', 'تخمینی', 'چرا؟', 'اثر پسین آزمایشی زنده'])->assertSee(route('works.show', $latest->id), false);

        // a documented death stands alone, and with nothing dated the row says «نامشخص» as before
        $this->assertNull(EstimateText::aliveIn($dead->id));
        $this->get(route('people.show', $dead))->assertOk()->assertSee('۱۲۵۰ ق')->assertDontSee('تخمینی');
        $this->assertNull(EstimateText::aliveIn($unknown->id));
        $this->get(route('people.show', $unknown))->assertOk()->assertSee('نامشخص')->assertDontSee('تخمینی');
    }

    public function test_a_scribe_is_alive_in_the_year_of_a_copy_but_not_when_the_copies_span_generations()
    {
        $work = $this->work('اثر کتابت‌شده‌ی آزمایشی', null, $this->person('مؤلف آزمایشی کتابت'));
        $scribe = $this->person('کاتب آزمایشی زنده');
        $this->copy($work, 1085, 1085, $scribe);
        // a copy dated by its century: alive at some time in it
        $century = $this->person('کاتب آزمایشی سده');
        $this->copy($work, 1201, 1300, $century);
        // copies 150 years apart: several namesakes in one record
        $mixed = $this->person('کاتب آزمایشی قاطی');
        $this->copy($work, 1000, 1000, $mixed);
        $this->copy($work, 1150, 1150, $mixed);

        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();

        $this->assertSame(['زنده در 1085 ق', 'نسخه‌ای را در این سال کتابت کرده است:'], [EstimateText::aliveIn($scribe->id)['text'], EstimateText::aliveIn($scribe->id)['reasons'][0]['text']]);
        $this->assertSame(['وفات پس از 1201 ق', 'نسخه‌ای را زودتر از این سال کتابت نکرده است:'], [EstimateText::aliveIn($century->id)['text'], EstimateText::aliveIn($century->id)['reasons'][0]['text']]);
        $this->assertNull(EstimateText::aliveIn($mixed->id));
        $this->get(route('people.show', $scribe))->assertOk()->assertSee('زنده در ۱۰۸۵ ق')->assertSee('ش زندگی ۱');
    }
}
