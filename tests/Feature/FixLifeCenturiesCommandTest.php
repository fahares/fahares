<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\Work;
use App\Support\SearchFilters;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Life dates that name only a century are the century the person lived in, not a range of the death, and the middle
 * of a century is no death year (step 14.213)
 */
class FixLifeCenturiesCommandTest extends TestCase
{
    private function person(string $name, ?string $raw, array $cols = []): int
    {
        return DB::table('people')->insertGetId(['name' => $name, 'normalized_name' => $name, 'slug' => 'life-' . md5($name), 'life_dates_raw' => $raw] + $cols);
    }

    private function cols(int $id): array
    {
        return array_values((array) DB::table('people')->where('id', $id)->first(['death_year_hijri', 'century_hijri', 'death_from', 'death_to', 'death_kind']));
    }

    public function test_the_range_and_the_middle_year_are_emptied_only_with_apply_and_a_run_can_be_repeated()
    {
        $century = ['death_year_hijri' => 1250, 'century_hijri' => 13, 'death_from' => 1201, 'death_to' => 1300, 'death_kind' => 'between'];
        $life = $this->person('مراغی آزمایشی', 'ق13 قمری', $century);
        $noYear = $this->person('بی‌سال آزمایشی', 'قرن 12 قمری', ['death_from' => 1101, 'death_to' => 1200, 'death_kind' => 'between']);
        $realYear = $this->person('سال‌دار آزمایشی', 'ق 11 قمری', ['death_year_hijri' => 1004, 'century_hijri' => 11, 'death_from' => 1001, 'death_to' => 1100, 'death_kind' => 'between']);
        $deathCentury = $this->person('گیلانی آزمایشی', '-قرن 11ق', ['death_year_hijri' => 1050, 'century_hijri' => 11, 'death_from' => 1001, 'death_to' => 1100, 'death_kind' => 'between']);
        $narrowed = $this->person('کازرونی آزمایشی', '1217ق-13 قمری', ['century_hijri' => 13, 'death_from' => 1217, 'death_to' => 1300, 'death_kind' => 'between']);
        $year = $this->person('نائینی آزمایشی', '1188-1250 قمری', ['death_year_hijri' => 1250, 'century_hijri' => 13, 'death_from' => 1250, 'death_to' => 1250]);
        $solar = $this->person('شمسی آزمایشی', 'ق 14 شمسی', ['death_year_hijri' => 1350, 'century_hijri' => 14, 'death_from' => 1350, 'death_to' => 1350]);

        $this->artisan('fahares:fix-life-centuries')->expectsOutputToContain('Dry run, nothing written: 3 centuries of a life, 1 mid-century death years')->assertSuccessful();
        $this->assertSame([1250, 13, 1201, 1300, 'between'], $this->cols($life));

        $this->artisan('fahares:fix-life-centuries', ['--apply' => true])->assertSuccessful();
        $this->assertSame([null, 13, null, null, null], $this->cols($life));
        $this->assertSame([null, 12, null, null, null], $this->cols($noYear));
        // a year that is not the middle of the century is a death year, and a range of its own
        $this->assertSame([1004, 11, 1004, 1004, null], $this->cols($realYear));
        // after a dash the century is the death's: only the made-up year goes
        $this->assertSame([null, 11, 1001, 1100, 'between'], $this->cols($deathCentury));
        $this->assertSame([null, 13, 1217, 1300, 'between'], $this->cols($narrowed));
        $this->assertSame([1250, 13, 1250, 1250, null], $this->cols($year));
        // another calendar is fahares:fix-calendar-deaths' to mend
        $this->assertSame([1350, 14, 1350, 1350, null], $this->cols($solar));

        $this->artisan('fahares:fix-life-centuries', ['--apply' => true])
            ->expectsOutputToContain('Written: 0 centuries of a life, 0 mid-century death years, 3 already right')->assertSuccessful();
    }

    public function test_saving_a_person_reads_the_century_of_the_life_and_drops_the_middle_year()
    {
        $person = Person::create(['name' => 'ذخیرۀ سده آزمایشی', 'normalized_name' => 'ذخیرۀ سده آزمایشی', 'slug' => 'life-save', 'life_dates_raw' => 'ق 13 قمری', 'death_year_hijri' => 1250]);
        $this->assertSame([null, 13, null, null, null], $this->cols($person->id));
        $this->assertSame('سدۀ ۱۳ ق', $person->fresh()->life_span);

        $person->update(['death_year_hijri' => 1262]);
        $this->assertSame([1262, 13, 1262, 1262, null], $this->cols($person->id));
    }

    public function test_the_engine_takes_the_century_as_bounds_that_no_later_copy_breaks()
    {
        $library = Library::create(['name' => 'کتابخانۀ آزمایشی سده', 'city' => 'قم', 'slug' => 'lib-' . uniqid()]);
        $author = Person::create(['name' => 'معاصر آزمایشی', 'normalized_name' => 'معاصر آزمایشی', 'slug' => 'life-author', 'life_dates_raw' => 'ق 14 قمری']);
        $scribe = Person::create(['name' => 'کاتب معاصر آزمایشی', 'normalized_name' => 'کاتب معاصر آزمایشی', 'slug' => 'life-scribe', 'life_dates_raw' => 'ق 14 قمری']);
        $work = function (string $title, ?int $year) use ($author) {
            $w = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'life-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
            DB::table('works')->where('id', $w->id)->update(['composition_date_from' => $year, 'composition_date_to' => $year]);
            DB::table('work_people')->insert(['work_id' => $w->id, 'person_id' => $author->id, 'role' => 'author', 'position' => 0]);

            return $w;
        };
        // a work and a copy after the end of the century: the person lived on into the next
        $late = $work('اثر پس از سده آزمایشی', 1410);
        $undated = $work('اثر بی‌تاریخ آزمایشی', null);
        $copy = Manuscript::create(['work_id' => $late->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'library_id' => $library->id,
            'library' => $library->name, 'city' => 'قم', 'shelfmark' => 'سده 1', 'raw_text' => '1. قم']);
        DB::table('manuscripts')->where('id', $copy->id)->update(['copy_date_from' => 1425, 'copy_date_to' => 1425]);
        DB::table('manuscript_people')->insert(['manuscript_id' => $copy->id, 'person_id' => $scribe->id, 'role' => 'scribe', 'name' => $scribe->name, 'position' => 0]);

        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('date_conflicts')->count());
        $estimate = fn (string $type, int $id, string $field) => array_values((array) DB::table('date_estimates')
            ->where(['subject_type' => $type, 'subject_id' => $id, 'field' => $field])->first(['year_from', 'year_to']));
        // the death is after the last work, and within a life of the birth: 15 years before the work, or by the century's end
        $this->assertSame([1410, 1495], $estimate('person', $author->id, 'death'));
        $this->assertSame([1425, 1500], $estimate('person', $scribe->id, 'death'));
        // an undated work: 15 years after the earliest birth (a death from 1410 is a birth from 1310), before the death
        $this->assertSame([1325, 1495], $estimate('work', $undated->id, 'composition'));
    }

    public function test_the_lists_order_and_filter_by_the_middle_of_the_century()
    {
        $person = Person::create(['name' => 'مؤلف سدۀ سیزده آزمایشی', 'normalized_name' => 'مؤلف سدۀ سیزده آزمایشی', 'slug' => 'life-era', 'life_dates_raw' => 'ق 13 قمری', 'is_author' => true]);
        $work = Work::create(['primary_title' => 'اثر سدۀ سیزده آزمایشی', 'clean_title' => 'اثر سدۀ سیزده آزمایشی', 'slug' => 'life-era-work', 'volume_number' => 1, 'page_start' => 1,
            'author_id' => $person->id]);

        $document = $person->fresh()->toSearchableArray();
        $this->assertSame([null, 13, 1250], [$document['death_year_hijri'], $document['century_hijri'], $document['era_year']]);
        $this->assertSame(1250, $person->fresh()->eraYear());
        $indexed = $work->fresh()->load('author')->toSearchableArray();
        $this->assertSame([1250, 1250], [$indexed['author_death_year'], $indexed['era_year']]);

        $ids = fn (array $query) => SearchFilters::fromRequest(Request::create('/search', 'GET', $query), 'works')->apply(Work::query())->whereKey($work->id)->pluck('works.id')->all();
        $this->assertSame([$work->id], $ids(['century' => '13']));
        $this->assertSame([], $ids(['century' => '14']));
    }
}
