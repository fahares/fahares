<?php

namespace Tests\Feature;

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
 * «منابع و مراجع» and the ways into a source's page text (step 13.79): the sources by kind (/sources), one source with
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
