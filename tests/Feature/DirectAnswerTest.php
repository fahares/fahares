<?php

namespace Tests\Feature;

use App\Models\Library;
use App\Models\Manuscript;
use App\Models\SearchLog;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Work;
use Tests\TestCase;

/** Searches that name a shelfmark, a bare number or a catalogue page (backlog item 83, phase 2) */
class DirectAnswerTest extends TestCase
{
    private Library $majlis;

    private Library $qom;

    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        // The answer comes from the database; the engine's own results are not under test here (the collection
        // engine cannot run the search page's Meilisearch callbacks)
        config(['scout.driver' => 'null']);
        $this->majlis = Library::create(['name' => 'مجلس', 'full_name' => 'کتابخانه مجلس (تهران)', 'city' => 'تهران', 'slug' => 'da-majlis-' . uniqid(), 'manuscripts_count' => 900]);
        $this->qom = Library::create(['name' => 'مجلس', 'full_name' => 'کتابخانه مجلس (قم)', 'city' => 'قم', 'slug' => 'da-majlis-qom-' . uniqid(), 'manuscripts_count' => 1]);
        $this->work = Work::create(['primary_title' => 'تحریر المساکن', 'clean_title' => 'تحریر المساکن', 'slug' => 'da-work-' . uniqid(), 'volume_number' => 12, 'page_start' => 345, 'page_end' => 346]);
    }

    private function manuscript(Library $library, string $shelfmark, array $attrs = []): Manuscript
    {
        return Manuscript::create($attrs + ['work_id' => $this->work->id, 'library_id' => $library->id, 'library' => $library->name,
            'shelfmark' => $shelfmark, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'raw_text' => 'نسخه‌ی آزمایشی']);
    }

    public function test_a_library_and_number_list_the_shelfmark_its_parts_and_marks_but_not_longer_numbers(): void
    {
        $this->manuscript($this->majlis, '1189/2');
        $this->manuscript($this->majlis, '1189 ط');
        $this->manuscript($this->majlis, '1189/1');
        $this->manuscript($this->majlis, '1189ع');
        $this->manuscript($this->majlis, '11890');
        $this->manuscript($this->qom, '1189');

        $this->get(route('search', ['q' => 'کتابخانه‌ی مجلس، شماره‌ی ۱۱۸۹']))->assertOk()
            ->assertSeeInOrder(['شماره‌ی', 'کتابخانه مجلس (تهران)', '1189/1', '1189/2', '1189 ط', '1189ع', 'کتابخانه‌ی دیگری با همین نام', 'کتابخانه مجلس (قم)'])
            ->assertDontSee('11890');

        // The city picks the other library of the same name
        $this->get(route('search', ['q' => 'مجلس قم 1189']))->assertRedirect(route('manuscripts.show', Manuscript::where('library_id', $this->qom->id)->sole()));
    }

    public function test_a_single_manuscript_is_opened_at_once_and_the_search_is_logged(): void
    {
        $ms = $this->manuscript($this->majlis, '1189/4');
        $this->manuscript($this->majlis, '1189/5');

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 Firefox/131.0'])->get(route('search', ['q' => 'مجلس ۱۱۸۹/۴']))
            ->assertRedirect(route('manuscripts.show', $ms));
        $log = SearchLog::sole();
        $this->assertSame([1, 1], [$log->results_count, $log->clicked_position]);

        // A filter or a later page means the user is browsing, not naming one record
        $this->get(route('search', ['q' => 'مجلس ۱۱۸۹/۴', 'type' => 'manuscripts', 'century' => 11]))->assertOk();
    }

    public function test_a_bare_number_shows_the_libraries_that_hold_it(): void
    {
        $this->manuscript($this->majlis, '1189/1');
        $this->manuscript($this->majlis, '1189/2');
        $this->manuscript($this->qom, '1189');

        $this->get(route('search', ['q' => '۱۱۸۹']))->assertOk()
            ->assertSee('نسخه‌ای با شماره‌ی')->assertSee('در ۲ کتابخانه هست')
            ->assertSee(e(route('search', ['q' => 'مجلس تهران 1189', 'type' => 'manuscripts'])), false);

        // A number inside a title is not a shelfmark
        $this->get(route('search', ['q' => '۱۰۰ کلمه']))->assertOk()->assertDontSee('نسخه‌ای با شماره‌ی');
    }

    public function test_a_catalogue_page_finds_its_entries(): void
    {
        $listed = $this->manuscript($this->majlis, '5005');
        $rejected = $this->manuscript($this->majlis, '5006');
        $source = Source::create(['code' => 'da-cat-' . uniqid(), 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'فهرست مجلس', 'library_id' => $this->majlis->id]);
        foreach ([[$listed, SourceEntry::STATUS_VERIFIED], [$rejected, SourceEntry::STATUS_REJECTED]] as [$ms, $status]) {
            SourceEntry::create(['citable_type' => Manuscript::class, 'citable_id' => $ms->id, 'source_id' => $source->id, 'volume_number' => 33, 'page_start' => 294,
                'origin' => SourceEntry::ORIGIN_MATCH, 'status' => $status]);
        }

        $this->get(route('search', ['q' => 'فهرست مجلس ج ۳۳ ص ۲۹۴']))->assertOk()
            ->assertSee('ج ۳۳، ص ۲۹۴')->assertSee('5005')->assertDontSee('5006');

        // The union catalogue: the volume and page are the records' own
        $this->manuscript($this->majlis, '7007', ['volume_number' => 12, 'page_start' => 345, 'page_end' => 346]);
        $this->get(route('search', ['q' => 'فنخا ج ۱۲ ص ۳۴۶']))->assertOk()
            ->assertSee('فنخا، ج ۱۲، ص ۳۴۶')->assertSee('7007')->assertSee('تحریر المساکن');
    }

    public function test_instant_search_lists_the_named_manuscripts_first(): void
    {
        $this->manuscript($this->majlis, '1189/1');
        $this->manuscript($this->majlis, '1189/2');

        $this->getJson(route('search.api', ['q' => 'مجلس ۱۱۸۹']))->assertOk()
            ->assertJsonPath('manuscripts.0.shelfmark', '1189/1')->assertJsonPath('manuscripts.1.shelfmark', '1189/2');
    }
}
