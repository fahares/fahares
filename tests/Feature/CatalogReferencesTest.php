<?php

namespace Tests\Feature;

use App\Console\Commands\ImportFankhaCatalogReferencesCommand as Import;
use App\Models\Catalog;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\ManuscriptCatalogReference as Ref;
use App\Models\Work;
use Tests\TestCase;

class CatalogReferencesTest extends TestCase
{
    private const SECRET_URL = 'https://scan-source.example/book/504138';

    protected Library $library;

    protected CatalogVolume $fankhaVolume;

    protected CatalogVolume $edition;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'مسجد اعظم', 'city' => 'قم', 'slug' => 'masjid-azam-' . uniqid()]);
        $fankha = Catalog::firstOrCreate(['code' => 'fankha'], ['name' => 'فهرستگان نسخه‌های خطی ایران', 'short_name' => 'فنخا']);
        $this->fankhaVolume = CatalogVolume::create(['title' => 'فهرست نسخ خطی کتابخانه مسجد اعظم قم - جلد دوم', 'volume_number' => 2, 'library_id' => $this->library->id, 'catalog_id' => $fankha->id]);
        $this->edition = CatalogVolume::create([
            'title' => 'فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم - جلد دوم', 'volume_number' => 2, 'library_id' => $this->library->id,
            'edition_of_id' => $this->fankhaVolume->id, 'edition_label' => 'چاپ ۱۳۶۵، رضا استادی',
            'facsimile_code' => 'masjid-azam-ostadi', 'page_first' => 1, 'page_last' => 827, 'source_url' => self::SECRET_URL,
        ]);
    }

    protected function manuscript(array $attributes = []): Manuscript
    {
        $work = Work::create(['primary_title' => 'دیوان قدسی', 'clean_title' => 'دیوان قدسی', 'slug' => 'work-ref-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);

        return Manuscript::create($attributes + [
            'work_id' => $work->id, 'library_id' => $this->library->id, 'shelfmark' => '1939',
            'catalog_volume_id' => $this->fankhaVolume->id, 'volume_number' => 1, 'page_start' => 1, 'sequence_number' => 1,
        ]);
    }

    public function test_an_edition_links_to_the_volume_fankha_cites_and_hides_its_source()
    {
        $this->assertTrue($this->edition->editionOf->is($this->fankhaVolume));
        $this->assertTrue($this->fankhaVolume->editions->first()->is($this->edition));
        $this->assertSame('فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم', $this->edition->short_title);
        $this->assertArrayNotHasKey('source_url', $this->edition->toArray());
        $this->assertStringNotContainsString('scan-source', $this->edition->toJson());
    }

    public function test_a_reference_opens_the_page_where_the_entry_starts()
    {
        $ms = $this->manuscript();
        $ref = Ref::create([
            'manuscript_id' => $ms->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2,
            'page_start' => 414, 'shelfmark_page' => 415, 'entry_number' => 606, 'source' => Ref::SOURCE_AI_MATCH, 'confidence' => 90,
        ]);

        $this->assertTrue($ms->catalogReferences->first()->is($ref));
        $this->assertTrue($ref->isAwaitingReview());
        $this->assertSame(1, Ref::awaitingReview()->where('manuscript_id', $ms->id)->count());
        $this->assertSame(['catalog' => 'masjid-azam-ostadi', 'catalogName' => 'فهرست نسخه‌های خطی کتابخانه مسجد اعظم قم', 'volume' => 2, 'page' => 414], $ref->viewer());

        $ref->update(['catalog_volume_id' => $this->fankhaVolume->id]);
        $this->assertNull($ref->fresh()->viewer(), 'a volume without page images has no viewer');
    }

    public function test_the_source_url_never_reaches_the_manuscript_page()
    {
        $ms = $this->manuscript();
        Ref::create(['manuscript_id' => $ms->id, 'catalog_volume_id' => $this->edition->id, 'volume_number' => 2, 'page_start' => 414, 'source' => Ref::SOURCE_AI_MATCH, 'confidence' => 90]);

        $html = $this->get(route('manuscripts.show', $ms))->assertOk()->getContent();
        $this->assertStringNotContainsString('scan-source', $html);
        $this->assertStringNotContainsString('504138', $html);
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $json) {
            $this->assertStringNotContainsString('scan-source', $json);
        }
    }

    public function test_fankha_citations_are_parsed_into_volume_and_page()
    {
        $cases = [
            '[ف: 2 - 224]' => ['ف', 2, 224],
            '[ف: ۲-۲۲۴]' => ['ف', 2, 224],
            '[ف مخ: 2 - 817]' => ['ف مخ', 2, 817],
            '[ف مخ - 3 - 1159]' => ['ف مخ', 3, 1159],
            '[رشت و همدان: ف: -1398]' => ['رشت و همدان', null, 1398],
            '[سنا: ف2-157]' => ['سنا', 2, 157],
            '[الفبائی: 9-]' => ['الفبائی', 9, null],
            '[ف: 47/1 - 15]' => ['ف', 47, 15],
            '[ف: 1/27-9]' => ['ف', 27, 9],
            '[اهدائی رهبر: 2 اخبار 1-112]' => ['اهدائی رهبر', 2, 112],
            '[تراثنا: س13ش1-123]' => ['تراثنا', null, 123],
            '[ف: 18-5؛ فهرست رایانه‌ای ج3-730]' => ['ف', 18, 5],
            '[مختصر ف: 10]' => ['مختصر ف', null, null],
            '[رایانه]' => ['رایانه', null, null],
            '[ف مخ]' => ['ف مخ', null, null],
        ];
        foreach ($cases as $citation => [$series, $volume, $page]) {
            $this->assertSame(['series' => $series, 'volume' => $volume, 'page' => $page], Import::parse($citation), $citation);
        }
        $this->assertSame('[ف مخ: 1]', Import::rawCitation(null, "متن نسخه [ف مخ: 1]"));
        $this->assertNull(Import::rawCitation('{"catalog_citation": ""}', 'بی‌ارجاع'));
    }

    public function test_the_import_adds_one_fankha_row_per_copy_and_never_duplicates()
    {
        $ms = $this->manuscript(['metadata' => ['catalog_citation' => '[ف مخ: 2 - 817]']]);
        $bare = $this->manuscript(['catalog_volume_id' => null, 'metadata' => []]);

        $this->artisan('catalogs:import-fankha-references', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, Ref::count());

        $this->artisan('catalogs:import-fankha-references')->assertSuccessful();
        $this->artisan('catalogs:import-fankha-references')->assertSuccessful();
        $rows = Ref::where('manuscript_id', $ms->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['[ف مخ: 2 - 817]', 2, 817, $this->fankhaVolume->id, Ref::SOURCE_FANKHA],
            [$rows[0]->raw_text, $rows[0]->volume_number, $rows[0]->page_start, $rows[0]->catalog_volume_id, $rows[0]->source]);
        $this->assertSame(0, Ref::where('manuscript_id', $bare->id)->count());

        // a later relink of the copy is followed on the next run
        $ms->update(['catalog_volume_id' => $this->edition->id]);
        $this->artisan('catalogs:import-fankha-references')->assertSuccessful();
        $this->assertSame([$this->edition->id], Ref::where('manuscript_id', $ms->id)->pluck('catalog_volume_id')->all());
    }

    public function test_editions_are_synced_by_natural_key()
    {
        $path = tempnam(sys_get_temp_dir(), 'editions');
        file_put_contents($path, json_encode(['editions' => [[
            'facsimile_code' => 'test-edition', 'volume_number' => 2, 'library_slug' => $this->library->slug, 'edition_of' => ['volume_number' => 2],
            'title' => 'فهرست آزمایشی - جلد دوم', 'edition_label' => '۱۳۶۵', 'page_first' => 1, 'page_last' => 10, 'source_url' => self::SECRET_URL,
        ]]], JSON_UNESCAPED_UNICODE));

        $this->artisan('catalogs:sync-editions', ['--data' => $path, '--dry-run' => true])->assertSuccessful();
        $this->assertNull(CatalogVolume::where('facsimile_code', 'test-edition')->first());
        $this->artisan('catalogs:sync-editions', ['--data' => $path])->assertSuccessful();
        $this->artisan('catalogs:sync-editions', ['--data' => $path])->expectsOutputToContain('unchanged')->assertSuccessful();
        unlink($path);

        $edition = CatalogVolume::where('facsimile_code', 'test-edition')->sole();
        $this->assertSame([$this->fankhaVolume->id, null, self::SECRET_URL, 10], [$edition->edition_of_id, $edition->catalog_id, $edition->source_url, $edition->page_last]);
        $this->assertCount(0, $edition->catalogers);
    }
}
