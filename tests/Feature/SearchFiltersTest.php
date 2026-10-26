<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Person;
use App\Models\SearchLog;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Subject;
use App\Models\Work;
use App\Support\SearchFilters;
use Illuminate\Http\Request;
use Tests\TestCase;

/** The filters of the search tabs, defined once for the engine and the database (backlog item 83, phase 5) */
class SearchFiltersTest extends TestCase
{
    private function work(array $attrs = []): Work
    {
        return Work::create($attrs + ['primary_title' => 'اثر فیلتر', 'clean_title' => 'اثر فیلتر', 'slug' => 'filter-work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function manuscript(Work $work, string $shelfmark, array $attrs = []): Manuscript
    {
        return Manuscript::create($attrs + ['work_id' => $work->id, 'shelfmark' => $shelfmark, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'raw_text' => 'نسخه']);
    }

    public function test_values_are_cleaned_and_turned_into_one_engine_filter(): void
    {
        $subject = Subject::create(['name' => 'فقه', 'slug' => 'f-fiqh-' . uniqid()]);
        Subject::create(['name' => 'اصول فقه', 'slug' => 'f-usul-' . uniqid(), 'parent_id' => $subject->id]);
        $request = Request::create('/search', 'GET', ['subject_id' => $subject->id, 'century' => '9', 'year_to' => '۸۵۰', 'facsimile' => '0',
            'flags' => ['is_bita', 'bogus'], 'flag' => 'is_autograph', 'folios_from' => 'abc', 'city' => 'قم', 'library_id' => '999999']);
        $filters = SearchFilters::fromRequest($request, 'manuscripts');

        $this->assertSame(['city', 'subject_id', 'century', 'year_to', 'facsimile', 'flags'], array_keys($filters->values), 'unknown library, bad number and flag dropped');
        $this->assertSame(['is_bita', 'is_autograph'], $filters->get('flags'));
        $this->assertSame('(city = "قم") AND (subjects IN ["فقه", "اصول فقه"]) AND (is_facsimile = false) AND (is_bita = true AND is_autograph = true) AND (copy_date_hijri_year >= 801 AND copy_date_hijri_year <= 850)',
            $filters->engine());

        $people = SearchFilters::fromRequest(Request::create('/search', 'GET', ['role' => 'scribe', 'century' => '11', 'subject_id' => $subject->id]), 'people');
        $this->assertSame('(is_scribe = true) AND (century_hijri = 11)', $people->engine(), 'a filter of another tab is ignored');
    }

    public function test_manuscripts_are_browsed_by_the_new_filters(): void
    {
        $subject = Subject::create(['name' => 'منطق', 'slug' => 'f-logic-' . uniqid()]);
        $logic = $this->work();
        $logic->subjects()->attach($subject);
        $other = $this->work();
        $qom = Library::create(['name' => 'کتابخانه‌ی فیلتر', 'city' => 'قم', 'slug' => 'f-lib-' . uniqid(), 'manuscripts_count' => 2]);
        $this->manuscript($logic, 'F-1', ['library_id' => $qom->id, 'city' => 'قم', 'copy_date_hijri_year' => 820, 'folios' => 250]);
        $this->manuscript($logic, 'F-2', ['city' => 'تهران', 'copy_date_hijri_year' => 1100, 'is_facsimile' => true, 'folios' => 40]);
        $this->manuscript($other, 'F-3', ['city' => 'قم', 'is_bita' => true]);
        $see = fn (array $params, array $yes, array $no) => tap($this->get(route('search', ['type' => 'manuscripts'] + $params))->assertOk(),
            fn ($r) => [array_map(fn ($s) => $r->assertSee($s), $yes), array_map(fn ($s) => $r->assertDontSee($s), $no)]);

        $see(['subject_id' => $subject->id], ['F-1', 'F-2', 'موضوع: منطق'], ['F-3']);
        $see(['city' => 'قم'], ['F-1', 'F-3', 'شهر کتابخانه: قم'], ['F-2']);
        $see(['year_from' => '800', 'year_to' => '۹۰۰'], ['F-1', 'سال: از ۸۰۰ تا ۹۰۰'], ['F-2', 'F-3']);
        $see(['facsimile' => '1'], ['F-2', 'فقط عکسی'], ['F-1', 'F-3']);
        $see(['folios_from' => '100'], ['F-1'], ['F-2', 'F-3']);
        $see(['flags' => ['is_bita']], ['F-3', 'بی‌تاریخ'], ['F-1', 'F-2']);
    }

    public function test_works_are_filtered_by_era_autograph_and_bibliography_and_sorted_by_author(): void
    {
        $early = Person::create(['name' => 'مؤلف کهن', 'normalized_name' => 'مؤلف کهن', 'slug' => 'f-early-' . uniqid(), 'death_year_hijri' => 1050]);
        $late = Person::create(['name' => 'مؤلف متأخر', 'normalized_name' => 'مؤلف متأخر', 'slug' => 'f-late-' . uniqid(), 'death_year_hijri' => 1250]);
        $dated = $this->work(['primary_title' => 'اثر تاریخ‌دار', 'composition_year_hijri' => 1020, 'author_id' => $late->id]);
        $byDeath = $this->work(['primary_title' => 'اثر بی‌تاریخ کهن', 'author_id' => $early->id]);
        $lateWork = $this->work(['primary_title' => 'اثر بی‌تاریخ متأخر', 'author_id' => $late->id]);
        $this->manuscript($byDeath, 'A-1', ['is_autograph' => true]);
        $dharia = Source::create(['code' => 'f-dharia-' . uniqid(), 'kind' => Source::KIND_BIBLIOGRAPHY, 'title' => 'الذریعة آزمون', 'short_name' => 'الذریعة آزمون']);
        SourceEntry::create(['citable_type' => Work::class, 'citable_id' => $lateWork->id, 'source_id' => $dharia->id, 'role' => SourceEntry::ROLE_CITATION,
            'origin' => SourceEntry::ORIGIN_BIBLIOGRAPHY, 'status' => SourceEntry::STATUS_VERIFIED]);
        $url = fn (array $params) => route('search', ['type' => 'works'] + $params);

        // The 11th century: composed then, or by an author who died then when the work has no date
        $this->get($url(['century' => 11]))->assertOk()->assertSee('اثر تاریخ‌دار')->assertSee('اثر بی‌تاریخ کهن')->assertDontSee('اثر بی‌تاریخ متأخر');
        $this->get($url(['has_autograph' => 1]))->assertOk()->assertSee('اثر بی‌تاریخ کهن')->assertDontSee('اثر تاریخ‌دار');
        $this->get($url(['source_id' => $dharia->id]))->assertOk()->assertSee('یادشده در الذریعة آزمون')->assertSee('اثر بی‌تاریخ متأخر')->assertDontSee('اثر بی‌تاریخ کهن');
        $this->get($url(['sort' => 'author_oldest']))->assertOk()->assertSeeInOrder(['اثر بی‌تاریخ کهن', 'اثر تاریخ‌دار']);
        // The old «frequency» filter is gone and ignored
        $this->get($url(['manuscripts_range' => 'single']))->assertOk()->assertDontSee('فراوانی نسخ');
    }

    public function test_people_by_role_and_century_and_removing_a_chip_keeps_the_rest(): void
    {
        Person::create(['name' => 'کاتب سده یازده', 'normalized_name' => 'کاتب سده یازده', 'slug' => 'f-scribe-' . uniqid(), 'is_scribe' => true, 'death_year_hijri' => 1070]);
        Person::create(['name' => 'مؤلف سده یازده', 'normalized_name' => 'مؤلف سده یازده', 'slug' => 'f-author-' . uniqid(), 'is_author' => true, 'death_year_hijri' => 1080]);

        $page = $this->get(route('search', ['type' => 'people', 'role' => 'scribe', 'century' => 11]))->assertOk()
            ->assertSee('کاتب سده یازده')->assertDontSee('مؤلف سده یازده')->assertSee('نقش: کاتب');
        $page->assertSee(e(route('search', ['type' => 'people', 'century' => 11])), false);
    }

    public function test_an_export_is_logged_apart_from_searches(): void
    {
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 Firefox/131.0'])->get(route('search.export', ['type' => 'people', 'format' => 'csv']))->assertOk();

        $log = SearchLog::sole();
        $this->assertSame([SearchLog::KIND_EXPORT, 'csv', 'people'], [$log->kind, $log->format, $log->type]);
    }
}
