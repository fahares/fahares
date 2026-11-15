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
 * The works' century filter and order by composition with estimated dates, by the reader's choice (step 14.197)
 */
class SearchEstimatesTest extends TestCase
{
    private Work $dated;

    private Work $estimated;

    private Work $loose;

    protected function setUp(): void
    {
        parent::setUp();
        $library = Library::create(['name' => 'کتابخانه‌ی جستجوی تخمین', 'city' => 'قم', 'slug' => 'lib-' . uniqid()]);
        $author = Person::create(['name' => 'مؤلف آزمایشی جستجوی تخمین', 'normalized_name' => 'مؤلف آزمایشی جستجوی تخمین', 'slug' => 'se-' . uniqid(), 'birth_year_hijri' => 1010]);
        $work = function (string $title, ?int $year, ?int $copy, bool $byAuthor) use ($library, $author) {
            $w = Work::create(['primary_title' => $title, 'clean_title' => $title, 'slug' => 'se-' . uniqid(), 'volume_number' => 1, 'page_start' => 1,
                'composition_date_raw' => $year ? "{$year}ق" : null, 'composition_year_hijri' => $year]);
            DB::table('works')->where('id', $w->id)->update(['composition_date_from' => $year, 'composition_date_to' => $year]);
            if ($byAuthor) {
                DB::table('work_people')->insert(['work_id' => $w->id, 'person_id' => $author->id, 'role' => 'author', 'position' => 0]);
            }
            if ($copy) {
                $m = Manuscript::create(['work_id' => $w->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'library_id' => $library->id,
                    'library' => $library->name, 'city' => 'قم', 'shelfmark' => 'جستجو ' . $w->id, 'raw_text' => '1. قم', 'copy_date_raw' => "{$copy}ق"]);
                DB::table('manuscripts')->where('id', $m->id)->update(['copy_date_from' => $copy, 'copy_date_to' => $copy]);
            }

            return $w;
        };
        $this->dated = $work('اثر تاریخ‌دار جستجوی تخمین', 1090, null, false);
        // by an author born in 1010, with a copy of 1060: composed between 1025 and 1060
        $this->estimated = $work('اثر تخمینی جستجوی تخمین', null, 1060, true);
        // only a copy: «before 1080», which names no century
        $this->loose = $work('اثر یک‌طرفه‌ی جستجوی تخمین', null, 1080, false);

        $this->artisan('fahares:estimate-dates', ['--apply' => true])->assertSuccessful();
    }

    private function filters(array $query): SearchFilters
    {
        return SearchFilters::fromRequest(Request::create('/search', 'GET', $query), 'works');
    }

    private function ids(array $query): array
    {
        return $this->filters($query)->apply(Work::query())->whereIn('works.id', [$this->dated->id, $this->estimated->id, $this->loose->id])
            ->orderBy('works.id')->pluck('works.id')->all();
    }

    public function test_the_century_takes_estimated_works_only_when_asked()
    {
        $this->assertSame([$this->dated->id], $this->ids(['century' => '11']));
        $this->assertSame([$this->dated->id, $this->estimated->id], $this->ids(['century' => '11', 'estimates' => '1']));
        // the estimate reaches no year of the 12th century
        $this->assertSame([], $this->ids(['century' => '12', 'estimates' => '1']));

        $this->assertSame('(era_year >= 1001 AND era_year <= 1100)', $this->filters(['century' => '11'])->engine());
        $this->assertSame('((era_year >= 1001 AND era_year <= 1100) OR (era_estimate_from <= 1100 AND era_estimate_to >= 1001))',
            $this->filters(['century' => '11', 'estimates' => '1'])->engine());
        // alone it narrows nothing
        $this->assertNull($this->filters(['estimates' => '1'])->engine());
        $this->assertCount(3, $this->ids(['estimates' => '1']));
    }

    public function test_the_search_document_carries_the_placed_estimate()
    {
        $document = fn (Work $w) => array_intersect_key($w->fresh()->load(['author', 'manuscripts', 'workPeople', 'subjects', 'languages', 'authors', 'baseWorks', 'sourceEntries'])->toSearchableArray(),
            array_flip(['era_year', 'era_estimate_from', 'era_estimate_to', 'composition_sort_year']));

        $this->assertSame(['era_year' => null, 'era_estimate_from' => 1025, 'era_estimate_to' => 1060, 'composition_sort_year' => 1042], $document($this->estimated));
        $this->assertSame(['era_year' => 1090, 'era_estimate_from' => null, 'era_estimate_to' => null, 'composition_sort_year' => 1090], $document($this->dated));
        $this->assertSame(['era_year' => null, 'era_estimate_from' => null, 'era_estimate_to' => null, 'composition_sort_year' => null], $document($this->loose));

        // an import reads the estimate with the works
        $imported = (new Work)->makeAllSearchableUsing(Work::query())->whereKey($this->estimated->id)->sole();
        $this->assertSame([1025, 1060], $imported->compositionEstimate());
    }

    public function test_the_works_with_the_new_fields_are_reindexed_and_a_run_can_resume()
    {
        $this->artisan('fahares:reindex-work-estimates', ['--from' => $this->dated->id, '--sleep' => 0])
            ->expectsOutputToContain("Reindexed 2 of 2 works (up to #{$this->estimated->id})")->assertSuccessful();
        $this->artisan('fahares:reindex-work-estimates', ['--from' => $this->loose->id + 1, '--sleep' => 0])->doesntExpectOutputToContain('Reindexed')->assertSuccessful();
        // a part of the ids, in batches of one
        $this->artisan('fahares:reindex-work-estimates', ['--from' => $this->dated->id, '--to' => $this->dated->id, '--chunk' => 1, '--sleep' => 0])
            ->expectsOutputToContain("Reindexed 1 of 1 works (up to #{$this->dated->id})")->assertSuccessful();
    }

    public function test_the_people_with_a_death_year_or_a_year_alive_are_reindexed()
    {
        $dead = Person::create(['name' => 'درگذشته‌ی نمایه‌ی آزمایشی', 'normalized_name' => 'درگذشته‌ی نمایه‌ی آزمایشی', 'slug' => 'se-' . uniqid(), 'death_year_hijri' => 1200]);
        $unknown = Person::create(['name' => 'بی‌قرینه‌ی نمایه‌ی آزمایشی', 'normalized_name' => 'بی‌قرینه‌ی نمایه‌ی آزمایشی', 'slug' => 'se-' . uniqid()]);

        $this->artisan('fahares:reindex-work-estimates', ['--people' => true, '--from' => $dead->id, '--sleep' => 0])
            ->expectsOutputToContain("Reindexed 1 of 1 people (up to #{$dead->id})")->assertSuccessful();
        $this->assertGreaterThan($dead->id, $unknown->id);
    }

    public function test_the_page_orders_by_the_estimate_and_marks_it()
    {
        $base = ['type' => 'works', 'century' => '11', 'sort' => 'oldest'];

        $this->get(route('search', $base))->assertOk()->assertSee('اثر تاریخ‌دار جستجوی تخمین')->assertDontSee('اثر تخمینی جستجوی تخمین')
            ->assertSee('با تاریخ‌های تخمینی تألیف');
        // 1042, the middle of 1025–1060, comes before 1090
        $this->get(route('search', $base + ['estimates' => '1']))->assertOk()
            ->assertSeeInOrder(['اثر تخمینی جستجوی تخمین', 'تألیف میان ۱۰۲۵ و ۱۰۶۰ ق (تخمینی)', 'اثر تاریخ‌دار جستجوی تخمین'])
            ->assertDontSee('اثر یک‌طرفه‌ی جستجوی تخمین');
        $this->get(route('search', ['sort' => 'newest'] + $base + ['estimates' => '1']))->assertOk()
            ->assertSeeInOrder(['اثر تاریخ‌دار جستجوی تخمین', 'اثر تخمینی جستجوی تخمین']);
    }
}
