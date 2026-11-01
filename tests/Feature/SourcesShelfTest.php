<?php

namespace Tests\Feature;

use App\Models\Cataloger;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\Work;
use App\Support\PrintedPage;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «منابع و مراجع» and the ways into a source's page text (step 13.80): the sources by kind (/sources), one source with
 * its volumes (/sources/{code}), its catalogues on a library's page, the sitemap, and «متن صفحه» / «متن» beside a
 * page image on a record's page and in the page viewer.
 */
class SourcesShelfTest extends TestCase
{
    public function test_the_index_lists_the_sources_the_site_has_something_of_by_kind()
    {
        [$catalogue] = $this->catalogue();
        Source::create(['code' => 'empty-test', 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 20, 'title' => 'منبعی بی‌داده']);

        $this->get('/sources')->assertOk()
            ->assertSee('منابع و مراجع', false)
            ->assertSee('data-source-kind="' . Source::KIND_LIBRARY_CATALOGUE . '"', false)
            ->assertSee('data-source="' . $catalogue->code . '"', false)
            ->assertSee(route('sources.show', $catalogue->code), false)
            ->assertDontSee('data-source="empty-test"', false);
    }

    public function test_a_source_page_shows_its_volumes_with_where_to_read_and_its_library()
    {
        [$catalogue, $volume, $library] = $this->catalogue();

        $this->get('/sources/' . $catalogue->code)->assertOk()
            ->assertSee('<meta name="description"', false)
            ->assertDontSee('noindex', false)
            ->assertSee('فهرست نسخه‌های خطی کتابخانه آزمون', false)
            ->assertSee(route('libraries.show', $library), false)
            ->assertSee('data-volume="1"', false)
            ->assertSee(route('source-pages.show', [$catalogue->code, 1, 17]), false);
        $this->get('/sources/nothing-here')->assertNotFound();

        // the library's page lists its catalogue
        $this->get(route('libraries.show', $library))->assertOk()->assertSee('data-library-catalogues', false)
            ->assertSee(route('sources.show', $catalogue->code), false);
    }

    public function test_bibliographies_and_biographies_come_first()
    {
        $this->catalogue();
        $bib = Source::create(['code' => 'bib-test', 'kind' => Source::KIND_BIBLIOGRAPHY, 'rank' => 20, 'title' => 'کتاب‌شناسی آزمون']);
        SourceEntry::create(['citable_type' => Work::class, 'citable_id' => Work::first()->id, 'source_id' => $bib->id, 'volume_number' => 1, 'page_start' => 1,
            'role' => SourceEntry::ROLE_CITATION, 'origin' => 'bibliography', 'status' => SourceEntry::STATUS_VERIFIED]);

        $html = $this->get('/sources')->assertOk()->getContent();
        $this->assertLessThan(strpos($html, 'data-source-kind="' . Source::KIND_LIBRARY_CATALOGUE . '"'), strpos($html, 'data-source-kind="' . Source::KIND_BIBLIOGRAPHY . '"'));
    }

    public function test_a_union_catalogue_is_listed_among_the_libraries_catalogues()
    {
        [$catalogue] = $this->catalogue();
        $union = Source::create(['code' => 'union-test', 'kind' => Source::KIND_UNION_CATALOGUE, 'rank' => 90, 'title' => 'فهرستگان آزمون']);
        SourceEntry::create(['citable_type' => Work::class, 'citable_id' => Work::first()->id, 'source_id' => $union->id, 'volume_number' => 1, 'page_start' => 1,
            'role' => SourceEntry::ROLE_CITATION, 'origin' => 'bibliography', 'status' => SourceEntry::STATUS_VERIFIED]);

        $html = $this->get('/sources')->assertOk()->assertDontSee('data-source-kind="' . Source::KIND_UNION_CATALOGUE . '"', false)->getContent();
        $section = strpos($html, 'data-source-kind="' . Source::KIND_LIBRARY_CATALOGUE . '"');
        $this->assertLessThan(strpos($html, 'data-source="union-test"'), $section);
    }

    public function test_a_source_page_lists_the_records_it_is_cited_for_and_searches_their_titles()
    {
        [$catalogue, $volume, $library] = $this->catalogue();
        $work = Work::create(['primary_title' => 'شرح الكافية', 'clean_title' => 'شرح الكافية', 'slug' => 'w-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $manuscript = Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'shelfmark' => '902', 'volume_number' => 1, 'page_start' => 40]);
        SourceEntry::create(['citable_type' => Manuscript::class, 'citable_id' => $manuscript->id, 'source_id' => $catalogue->id, 'source_volume_id' => $volume->id,
            'volume_number' => 1, 'page_start' => 40, 'role' => SourceEntry::ROLE_ENTRY, 'origin' => 'catalogue', 'status' => SourceEntry::STATUS_VERIFIED]);

        // both, in the book's order, each with its page; the page the site has the text of opens it
        $html = $this->get('/sources/' . $catalogue->code)->assertOk()->assertSee('data-source-linked="manuscripts"', false)
            ->assertSee(route('source-pages.show', [$catalogue->code, 1, 17]), false)->getContent();
        $this->assertLessThan(strpos($html, 'شرح الكافية'), strpos($html, 'القرآن الکریم</a>'));

        // a title written with the Persian letters finds the Arabic one
        $this->get('/sources/' . $catalogue->code . '?q=' . urlencode('کافیه'))->assertOk()
            ->assertSee(route('manuscripts.show', $manuscript), false)->assertDontSee('القرآن الکریم</a>', false);
        $this->get('/sources/' . $catalogue->code . '?q=' . urlencode('ناموجود'))->assertOk()->assertSee('چیزی با این عنوان پیدا نشد', false);
    }

    public function test_a_book_in_one_volume_has_no_list_of_volumes_and_each_volume_names_its_catalogers()
    {
        [$catalogue, $volume] = $this->catalogue();
        $cataloger = Cataloger::create(['name' => 'رضا استادی', 'slug' => 'ostadi-' . uniqid()]);
        $supervisor = Cataloger::create(['name' => 'سید محمود مرعشی', 'slug' => 'marashi-' . uniqid()]);
        // a source volume shares its id with its catalog volume, which holds the catalogers
        DB::table('catalog_volumes')->insert(['id' => $volume->id, 'title' => $catalogue->title, 'volume_number' => 1]);
        DB::table('catalog_volume_cataloger')->insert([
            ['catalog_volume_id' => $volume->id, 'cataloger_id' => $cataloger->id, 'role' => 'cataloger'],
            ['catalog_volume_id' => $volume->id, 'cataloger_id' => $supervisor->id, 'role' => 'supervisor'],
        ]);

        // two volumes: a list, each with its catalogers
        $catalogue->update(['volumes_count' => 2]);
        $this->get('/sources/' . $catalogue->code)->assertOk()->assertSee('data-source-volumes', false)
            ->assertSee('data-volume-catalogers', false)->assertSee('رضا استادی', false)->assertSee('زیر نظر', false)
            ->assertSee(route('catalogers.show', $cataloger), false);

        // a book in one volume: its buttons and catalogers in the header
        $catalogue->update(['volumes_count' => 1]);
        $this->get('/sources/' . $catalogue->code)->assertOk()->assertDontSee('data-source-volumes', false)
            ->assertSee('data-read-volume', false)->assertSee('فهرست‌نگار:', false);
    }

    public function test_a_source_shows_its_cover_and_a_printing_without_one_shows_the_books()
    {
        [$catalogue] = $this->catalogue();
        $edition = Source::create(['code' => $catalogue->code . '-e', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'rank' => 10, 'title' => 'چاپ دیگر', 'edition_of_id' => $catalogue->id]);
        $this->assertNull($catalogue->coverUrl());

        $file = public_path("images/sources/covers/{$catalogue->code}.webp");
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, 'webp');
        try {
            $this->assertSame(asset("images/sources/covers/{$catalogue->code}.webp"), $catalogue->coverUrl());
            $this->assertSame($catalogue->coverUrl(), $edition->fresh()->coverUrl());
            $this->get('/sources/' . $catalogue->code)->assertOk()->assertSee('data-source-cover', false);
        } finally {
            @unlink($file);
        }
    }

    public function test_a_volume_shows_its_manuscripts_and_its_table_of_contents()
    {
        [$catalogue, $volume] = $this->catalogue();
        $catalogue->update(['volumes_count' => 2]);
        $this->artisan('sources:import-toc', ['file' => $this->tocFile([$catalogue->code => [1 => [
            ['title' => 'بخش شانزدهم فلسفه', 'page' => 17, 'level' => 1],
            ['title' => '«آ»', 'page' => 17, 'level' => 2],
            ['title' => 'نمایه‌ها', 'page' => 900, 'level' => 1],
        ]]])])->assertSuccessful();

        $html = $this->get('/sources/' . $catalogue->code)->assertOk()
            ->assertSee('۱ نسخه', false)
            ->assertSee('data-source-toc', false)
            ->assertSee('href="' . route('source-pages.show', [$catalogue->code, 1, 17]) . '" class="flex-1 min-w-0 text-ink hover:text-cinnabar">بخش شانزدهم فلسفه</a>', false)
            ->getContent();
        // a heading whose page the site lacks stays plain
        $this->assertStringContainsString('<span class="flex-1 min-w-0 text-muted">نمایه‌ها</span>', $html);
        $this->assertSame(3, count($volume->fresh()->metadata['toc']));

        // a book in one volume has its table of contents open under its header
        $catalogue->update(['volumes_count' => 1]);
        $html = $this->get('/sources/' . $catalogue->code)->assertOk()->assertSee('فهرست مطالب', false)->getContent();
        $this->assertMatchesRegularExpression('/<details[^>]*\sopen\s[^>]*data-source-toc/u', $html);
    }

    public function test_a_source_without_a_cover_shows_a_binding_with_its_title()
    {
        [$catalogue] = $this->catalogue();

        $this->get('/sources')->assertOk()->assertSee('data-source-cover-plain', false);
        $this->get('/sources/' . $catalogue->code)->assertOk()->assertSee('data-source-cover-plain', false);
    }

    public function test_the_page_text_turns_and_goes_to_a_page_from_a_bar_over_the_sheet()
    {
        [$catalogue] = $this->catalogue();

        $html = $this->get(route('source-pages.show', [$catalogue->code, 1, 17]))->assertOk()->assertSee('data-page-nav', false)->getContent();
        // the page to go to sits in the bar over the sheet, before it
        $this->assertLessThan(strpos($html, 'data-printed-page'), strpos($html, 'id="go-to-page"'));
    }

    private function tocFile(array $toc): string
    {
        $file = tempnam(sys_get_temp_dir(), 'toc');
        file_put_contents($file, json_encode($toc, JSON_UNESCAPED_UNICODE));

        return $file;
    }

    public function test_the_sitemap_lists_the_index_and_the_sources_but_not_their_page_texts()
    {
        [$catalogue] = $this->catalogue();

        $xml = $this->get(route('sitemap.chunk', ['type' => 'static', 'page' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString(route('sources.index'), $xml);
        $this->assertStringContainsString(route('sources.show', $catalogue->code), $xml);
        $this->assertStringNotContainsString('/page/', $xml);
    }

    public function test_a_records_page_text_is_reached_from_its_source_box_and_side_card()
    {
        [$catalogue, $volume] = $this->catalogue();
        $manuscript = Manuscript::where('shelfmark', '714')->sole();

        $this->get(route('manuscripts.show', $manuscript))->assertOk()
            ->assertSee('href="' . route('source-pages.show', [$catalogue->code, 1, 17]) . '"', false)
            ->assertSee('data-page-text', false);
        $this->assertSame(route('source-pages.show', [$catalogue->code, 1, 17]),
            PrintedPage::urlForViewer(['catalog' => $volume->facsimile_code, 'volume' => 1, 'page' => 17]));
        $this->assertNull(PrintedPage::urlForViewer(['catalog' => $volume->facsimile_code, 'volume' => 1, 'page' => 99]));
    }

    public function test_the_viewer_learns_the_address_of_a_volumes_page_text()
    {
        [$catalogue, $volume] = $this->catalogue();
        $dir = storage_path('app/public/catalogs/' . $volume->facsimile_code . '/v01');
        @mkdir($dir, 0777, true);
        touch($dir . '/p0017.webp');
        try {
            $this->getJson('/api/page-images/' . $volume->facsimile_code . '/1')->assertOk()
                ->assertJson(['first' => 17, 'last' => 17, 'text' => url("/sources/{$catalogue->code}/1/page") . '/']);
        } finally {
            @unlink($dir . '/p0017.webp');
            @rmdir($dir);
            @rmdir(dirname($dir));
        }
    }

    /** A library's catalogue with one volume, page 17 of its text and a copy made from it. */
    private function catalogue(): array
    {
        $library = Library::create(['name' => 'کتابخانه آزمون', 'city' => 'قم', 'slug' => 'test-lib-' . uniqid()]);
        $work = Work::create(['primary_title' => 'القرآن الکریم', 'clean_title' => 'القرآن الکریم', 'slug' => 'w-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $manuscript = Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'shelfmark' => '714', 'volume_number' => 1, 'page_start' => 17]);
        $code = 'catalogue-test-' . substr(uniqid(), -6);
        $source = Source::create(['code' => $code, 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'rank' => 10, 'title' => 'فهرست نسخه‌های خطی کتابخانه آزمون', 'library_id' => $library->id]);
        $volume = SourceVolume::create(['source_id' => $source->id, 'volume_number' => 1, 'facsimile_code' => $code]);
        DB::table('source_pages')->insert(['source_volume_id' => $volume->id, 'page' => 17, 'text' => "1- القرآن الکریم\nش 714، نسخ، بی کا، بی تا."]);
        SourceEntry::create(['citable_type' => Manuscript::class, 'citable_id' => $manuscript->id, 'source_id' => $source->id, 'source_volume_id' => $volume->id,
            'volume_number' => 1, 'page_start' => 17, 'text_page' => 17, 'shelfmark_page' => 17, 'entry_text' => '1- القرآن الکریم', 'copy_text' => 'ش 714، نسخ، بی کا، بی تا.',
            'role' => SourceEntry::ROLE_ENTRY, 'origin' => 'catalogue', 'status' => SourceEntry::STATUS_VERIFIED, 'text_status' => 'auto']);

        return [$source, $volume, $library];
    }
}
