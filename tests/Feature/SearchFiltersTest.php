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
            'flags' => ['is_collated', 'bogus'], 'flag' => 'is_autograph', 'folios_from' => 'abc', 'city' => 'قم', 'library_id' => '999999']);
        $filters = SearchFilters::fromRequest($request, 'manuscripts');

        $this->assertSame(['city', 'subject_id', 'century', 'year_to', 'facsimile', 'flags'], array_keys($filters->values), 'unknown library, bad number and flag dropped');
        $this->assertSame(['is_collated', 'is_autograph'], $filters->get('flags'));
        $this->assertSame('(city = "قم") AND (subjects IN ["فقه", "اصول فقه"]) AND (is_facsimile = false) AND (is_collated = true AND is_autograph = true) AND (copy_from >= 801 AND copy_to <= 850)',
            $filters->engine());

        $people = SearchFilters::fromRequest(Request::create('/search', 'GET', ['role' => 'scribe', 'century' => '11', 'subject_id' => $subject->id]), 'authors');
        $this->assertSame('(is_author = true OR is_translator = true OR is_donor = true OR is_scribe = false) AND (is_scribe = true) AND (century_hijri = 11)',
            $people->engine(), 'a filter of another tab is ignored');
        // The scribes by their copies; «donor» is no role of their tab
        $scribes = SearchFilters::fromRequest(Request::create('/search', 'GET', ['role' => 'donor', 'century' => '11', 'copy_city' => 'اصفهان']), 'scribes');
        $this->assertSame('(is_scribe = true) AND (copy_centuries = 11) AND (copy_cities = "اصفهان")', $scribes->engine());
    }

    public function test_manuscripts_are_browsed_by_the_new_filters(): void
    {
        $subject = Subject::create(['name' => 'منطق', 'slug' => 'f-logic-' . uniqid()]);
        $logic = $this->work();
        $logic->subjects()->attach($subject);
        $other = $this->work();
        $qom = Library::create(['name' => 'کتابخانه‌ی فیلتر', 'city' => 'قم', 'slug' => 'f-lib-' . uniqid(), 'manuscripts_count' => 2]);
        $this->manuscript($logic, 'F-1', ['library_id' => $qom->id, 'city' => 'قم', 'copy_date_raw' => '820ق', 'folios' => 250]);
        $this->manuscript($logic, 'F-2', ['city' => 'تهران', 'copy_date_raw' => '1100ق', 'is_facsimile' => true, 'folios' => 40]);
        $this->manuscript($other, 'F-3', ['city' => 'قم', 'is_collated' => true]);
        $see = fn (array $params, array $yes, array $no) => tap($this->get(route('search', ['type' => 'manuscripts'] + $params))->assertOk(),
            fn ($r) => [array_map(fn ($s) => $r->assertSee($s), $yes), array_map(fn ($s) => $r->assertDontSee($s), $no)]);

        $see(['subject_id' => $subject->id], ['F-1', 'F-2', 'موضوع: منطق'], ['F-3']);
        $see(['city' => 'قم'], ['F-1', 'F-3', 'شهر کتابخانه: قم'], ['F-2']);
        $see(['year_from' => '800', 'year_to' => '۹۰۰'], ['F-1', 'سال: از ۸۰۰ تا ۹۰۰'], ['F-2', 'F-3']);
        $see(['facsimile' => '1'], ['F-2', 'فقط عکسی'], ['F-1', 'F-3']);
        $see(['folios_from' => '100'], ['F-1'], ['F-2', 'F-3']);
        $see(['flags' => ['is_collated']], ['F-3', 'مقابله‌شده'], ['F-1', 'F-2']);
    }

    public function test_copies_are_found_by_their_whole_date_and_by_the_author_of_their_work(): void
    {
        $author = Person::create(['name' => 'مؤلف فیلتر نسخه', 'normalized_name' => 'مؤلف فیلتر نسخه', 'slug' => 'f-author-' . uniqid(), 'is_author' => true]);
        $his = $this->work(['author_id' => $author->id]);
        $other = $this->work();
        $year = $this->manuscript($his, 'D-YEAR', ['copy_date_raw' => '820ق']);
        $century = $this->manuscript($his, 'D-CENT', ['copy_date_raw' => 'قرن 9']);
        $across = $this->manuscript($other, 'D-ACROSS', ['copy_date_raw' => 'قرن 9 و 10']);
        $see = fn (array $params, array $yes, array $no) => tap($this->get(route('search', ['type' => 'manuscripts'] + $params))->assertOk(),
            fn ($r) => [array_map(fn ($s) => $r->assertSee($s), $yes), array_map(fn ($s) => $r->assertDontSee($s), $no)]);

        $this->assertSame(['copy_from' => 820, 'copy_to' => 820, 'copy_century' => 9], $year->fresh()->copyBounds());
        $this->assertSame(['copy_from' => 801, 'copy_to' => 900, 'copy_century' => 9], $century->fresh()->copyBounds());
        $this->assertNull($across->fresh()->copyBounds()['copy_century'], 'a range across two centuries is of neither');
        $this->assertSame([$author->id], $century->fresh()->load('work.authors')->toSearchableArray()['author_ids']);

        // A copy dated only by its century is of that century, not of a year within it
        $see(['century' => '9'], ['D-YEAR', 'D-CENT'], ['D-ACROSS']);
        $see(['year_from' => '801', 'year_to' => '1000'], ['D-YEAR', 'D-CENT', 'D-ACROSS'], []);
        $see(['year_from' => '810', 'year_to' => '830'], ['D-YEAR'], ['D-CENT', 'D-ACROSS']);
        $see(['author_id' => $author->id], ['D-YEAR', 'D-CENT', 'نسخه‌های آثار مؤلف فیلتر نسخه'], ['D-ACROSS']);
        $this->assertSame("(author_ids = {$author->id}) AND (copy_from >= 801 AND copy_to <= 900)",
            SearchFilters::fromRequest(Request::create('/search', 'GET', ['author_id' => $author->id, 'century' => '9']), 'manuscripts')->engine());

        // The sidebar keeps the list it narrows when another filter is applied
        $this->get(route('search', ['type' => 'manuscripts', 'author_id' => $author->id]))
            ->assertSee('<input type="hidden" name="author_id" value="' . $author->id . '">', false);
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

    public function test_authors_and_scribes_are_two_tabs_and_someone_who_is_both_is_in_both(): void
    {
        $person = fn (string $name, array $attrs = []) => Person::create($attrs + ['name' => $name, 'normalized_name' => $name, 'slug' => 'f-tab-' . uniqid()]);
        $author = $person('مؤلف تنها', ['death_year_hijri' => 1080]);
        $both = $person('مؤلف کاتب', ['death_year_hijri' => 1070]);
        $scribe = $person('کاتب تنها');
        $later = $person('کاتب متأخر');
        $person('شخص بی‌نقش');
        $work = $this->work(['author_id' => $author->id]);
        $this->work(['author_id' => $both->id]);
        $this->manuscript($work, 'T-1', ['scribe_id' => $both->id, 'copy_date_raw' => '1050ق', 'copy_place' => 'اصفهان']);
        $this->manuscript($work, 'T-2', ['scribe_id' => $scribe->id, 'copy_date_raw' => 'قرن 11']);
        $this->manuscript($work, 'T-3', ['scribe_id' => $scribe->id, 'copy_date_raw' => '1120ق']);
        $this->manuscript($work, 'T-4', ['scribe_id' => $later->id, 'copy_date_raw' => '1250ق']);
        $see = fn (array $params, array $yes, array $no) => tap($this->get(route('search', $params))->assertOk(),
            fn ($r) => [array_map(fn ($s) => $r->assertSee($s), $yes), array_map(fn ($s) => $r->assertDontSee($s), $no)]);

        // Whoever is not only a scribe is among the authors, a person with no work yet too
        $see(['type' => 'authors'], ['مؤلف تنها', 'مؤلف کاتب', 'شخص بی‌نقش'], ['کاتب تنها', 'کاتب متأخر']);
        $see(['type' => 'scribes'], ['مؤلف کاتب', 'کاتب تنها', 'کاتب متأخر'], ['مؤلف تنها', 'شخص بی‌نقش']);
        $see(['type' => 'authors', 'century' => 11, 'role' => 'scribe'], ['مؤلف کاتب', 'سده‌ی ۱۱ (وفات)', 'همچنین کاتب'], ['مؤلف تنها']);

        // A scribe by the copies: their century, their place, their number and their years
        $see(['type' => 'scribes', 'century' => 11], ['مؤلف کاتب', 'کاتب تنها', 'سده‌ی ۱۱ (کتابت)'], ['کاتب متأخر']);
        $see(['type' => 'scribes', 'century' => 12], ['کاتب تنها'], ['مؤلف کاتب', 'کاتب متأخر']);
        $see(['type' => 'scribes', 'copy_city' => 'اصفهان'], ['مؤلف کاتب', 'محل کتابت: اصفهان'], ['کاتب تنها']);
        $see(['type' => 'scribes', 'role' => 'author'], ['مؤلف کاتب', 'همچنین پدیدآور'], ['کاتب تنها']);
        $this->get(route('search', ['type' => 'scribes']))->assertSeeInOrder(['کاتب تنها', '۲ نسخه', 'مؤلف کاتب', 'کتابت ۱۰۵۰ ق', '۱ نسخه', '۱ اثر']);
        $this->get(route('search', ['type' => 'scribes', 'sort' => 'newest']))->assertSeeInOrder(['کاتب متأخر', 'کاتب تنها', 'مؤلف کاتب']);
        $this->assertSame([1001, 1120, 'کتابت سده‌های ۱۱ تا ۱۲ ق'], [$scribe->fresh()->scribed_from, $scribe->fresh()->scribed_to, $scribe->fresh()->scribed_span]);

        // The index holds the same: the centuries a copy lies wholly in, and the cities of the places
        $document = $both->fresh()->toSearchableArray();
        $this->assertSame([[11], ['اصفهان'], 1050, 1050], [$document['copy_centuries'], $document['copy_cities'], $document['scribed_from'], $document['scribed_to']]);
        $this->assertSame([11, 12], $scribe->fresh()->toSearchableArray()['copy_centuries']);

        // The copy's date is edited: its scribe follows
        Manuscript::where('shelfmark', 'T-4')->first()->update(['copy_date_raw' => '950ق']);
        $this->assertSame([950, 950], [$later->fresh()->scribed_from, $later->fresh()->scribed_to]);

        // Removing a chip keeps the rest, and the old tab of all people opens the one it meant
        $this->get(route('search', ['type' => 'scribes', 'century' => 11, 'copy_city' => 'اصفهان']))
            ->assertSee(e(route('search', ['type' => 'scribes', 'copy_city' => 'اصفهان'])), false);
        $this->get('/search?type=people&q=' . urlencode('مؤلف') . '&century=11')->assertRedirect(route('search', ['type' => 'authors', 'q' => 'مؤلف', 'century' => 11]));
        $this->get('/search?type=people&role=scribe&century=11')->assertStatus(301)->assertRedirect(route('search', ['type' => 'scribes']));
    }

    public function test_record_pages_open_their_lists_in_the_search_with_every_author_and_scribe(): void
    {
        $first = Person::create(['name' => 'مؤلف نخست', 'normalized_name' => 'مؤلف نخست', 'slug' => 'f-first-' . uniqid(), 'is_author' => true, 'is_scribe' => true]);
        $second = Person::create(['name' => 'مؤلف دوم', 'normalized_name' => 'مؤلف دوم', 'slug' => 'f-second-' . uniqid(), 'is_author' => true]);
        $own = $this->work(['primary_title' => 'اثر خود او', 'author_id' => $first->id]);
        $shared = $this->work(['primary_title' => 'اثر دونفره', 'author_id' => $second->id]);
        $other = $this->work(['primary_title' => 'اثر دیگری']);
        // The first author is linked by author_id itself; the second is added
        $shared->authors()->syncWithoutDetaching([$first->id => ['position' => 2]]);
        $copied = $this->manuscript($other, 'S-1');
        $copied->scribes()->attach($first->id, ['position' => 1]);
        $this->manuscript($other, 'S-2');
        $library = Library::create(['name' => 'کتابخانه‌ی ترتیب عددی', 'city' => 'قم', 'slug' => 'f-order-' . uniqid(), 'manuscripts_count' => 3]);
        foreach (['10', '2', 'بی‌شماره'] as $shelfmark) {
            $this->manuscript($own, $shelfmark, ['library_id' => $library->id]);
        }

        // The works of a person include those written with another; the manuscripts, those copied with another
        $this->get(route('search', ['type' => 'works', 'author_id' => $first->id]))->assertOk()
            ->assertSee('آثار مؤلف نخست')->assertSee('اثر خود او')->assertSee('اثر دونفره')->assertDontSee('اثر دیگری');
        $this->get(route('search', ['type' => 'manuscripts', 'scribe_id' => $first->id]))->assertOk()
            ->assertSee('کتابت مؤلف نخست')->assertSee('S-1')->assertDontSee('S-2');
        $this->get(route('search', ['type' => 'manuscripts', 'work_id' => $other->id]))->assertOk()
            ->assertSee('نسخه‌های اثر: اثر دیگری')->assertSee('S-1')->assertSee('S-2')->assertDontSee('بی‌شماره');

        $this->get(route('people.show', $first))->assertOk()
            ->assertSee(e(route('search', ['type' => 'works', 'author_id' => $first->id])), false)->assertSee('خروجی اکسل این آثار');
        // A library's manuscripts by number: 2 before 10, the unnumbered last
        $this->get(route('libraries.show', $library))->assertOk()
            ->assertSee(e(route('search', ['type' => 'manuscripts', 'library_id' => $library->id])), false)
            ->assertSeeInOrder(['<span dir="ltr">۲<', '<span dir="ltr">۱۰<', 'بی‌شماره'], false);
    }

    public function test_several_libraries_can_be_chosen_and_removed_one_by_one(): void
    {
        $work = $this->work();
        $libs = collect(['الف', 'ب', 'ج'])->map(fn ($n) => Library::create(['name' => "کتابخانه‌ی {$n}", 'city' => 'قم', 'slug' => 'f-multi-' . uniqid(), 'manuscripts_count' => 1]));
        foreach ($libs as $i => $lib) {
            $this->manuscript($work, "M-{$i}", ['library_id' => $lib->id]);
        }
        $chosen = [$libs[0]->id, $libs[1]->id];

        $filters = SearchFilters::fromRequest(Request::create('/search', 'GET', ['library_id' => [...$chosen, 'x', 999999]]), 'manuscripts');
        $this->assertSame("(library_id IN [{$chosen[0]}, {$chosen[1]}])", $filters->engine());

        $page = $this->get(route('search', ['type' => 'manuscripts', 'library_id' => $chosen]))->assertOk()
            ->assertSee('M-0')->assertSee('M-1')->assertDontSee('M-2')->assertSee('کتابخانه: کتابخانه‌ی الف، قم');
        // Removing one keeps the other
        $page->assertSee(e(route('search', ['type' => 'manuscripts', 'library_id' => [$chosen[1]]])), false);
        // One library, as the record pages and older links send it
        $this->get(route('search', ['type' => 'manuscripts', 'library_id' => $libs[2]->id]))->assertOk()->assertSee('M-2')->assertDontSee('M-0');
    }

    public function test_an_export_is_logged_apart_from_searches(): void
    {
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 Firefox/131.0'])->get(route('search.export', ['type' => 'people', 'format' => 'csv']))->assertOk();

        $log = SearchLog::sole();
        // An export link of the old tab of all people still works, as the authors'
        $this->assertSame([SearchLog::KIND_EXPORT, 'csv', 'authors'], [$log->kind, $log->format, $log->type]);
    }
}
