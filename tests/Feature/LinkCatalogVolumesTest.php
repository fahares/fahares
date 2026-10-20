<?php

namespace Tests\Feature;

use App\Console\Commands\LinkCatalogVolumesCommand as Linker;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LinkCatalogVolumesTest extends TestCase
{
    use DatabaseTransactions;

    private Work $work;

    private string $dataPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'اثر آزمایشی', 'clean_title' => 'اثر آزمایشی', 'slug' => 'work-link-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $this->dataPath = tempnam(sys_get_temp_dir(), 'links') . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->dataPath);
        parent::tearDown();
    }

    public function test_citations_are_parsed_by_series()
    {
        $this->assertSame(['volume' => 2], Linker::parseCitation(Linker::normalize('[ف: ۲-۴۵]'), 'ف'));
        $this->assertSame(['volume' => null], Linker::parseCitation('ف: -50', 'ف'));
        $this->assertNull(Linker::parseCitation('ف مخ: 1', 'ف'));
        $this->assertNull(Linker::parseCitation('فهرست عراقی: 1-136', 'ف'));
        $this->assertSame(['volume' => null], Linker::parseCitation('الفبائی: -512', 'الفبائی'));
        $this->assertSame(['volume' => null], Linker::parseCitation(Linker::normalize('کتابخانه‌های قائن - ف: - 170'), 'کتابخانه‌های قائن'));
        $this->assertSame(['volume' => null], Linker::parseCitation('ف مخ: 1', 'ف مخ'));
        $this->assertNull(Linker::parseCitation('ف مخطوطات: 1', 'ف مخ'));
        $this->assertSame(Linker::SOURCE_LIBRARY_DATABASE, Linker::nonCatalogSource('رایانه'));
        $this->assertSame(Linker::SOURCE_REGISTER, Linker::nonCatalogSource('د.ث. مجلس'));
        $this->assertNull(Linker::nonCatalogSource('ف: 1-2'));
    }

    public function test_citation_falls_back_to_the_last_bracket_of_the_raw_text()
    {
        $this->assertSame('الفبائی: -512', Linker::citationOf('{"catalog_citation": null}', "۱. مشهد؛ رضوی؛ شماره نسخه: 1\nخط: نسخ [الفبائی: -512]"));
        $this->assertSame('ف: 3-10', Linker::citationOf('{"catalog_citation": "[ف: 3-10]"}', 'متن [رایانه]'));
    }

    public function test_manuscripts_are_linked_by_natural_key_and_the_run_is_repeatable()
    {
        $library = Library::create(['name' => 'کتابخانه آزمایشی', 'city' => 'یزد', 'slug' => 'test-lib-' . uniqid()]);
        $existing = Cataloger::create(['name' => 'فهرست‌نگار موجود', 'slug' => 'existing-' . uniqid(), 'mtif_entry_id' => 990001]);
        $v1 = $this->manuscript($library, '[ف: 1-10]');
        $v2 = $this->manuscript($library, '[ف: 2-20]');
        $v9 = $this->manuscript($library, '[ف: 9-20]');
        $other = $this->manuscript($library, '[الفبائی: -5]');
        $database = $this->manuscript($library, null, "متن [رایانه]");

        $this->writeData([[
            'library' => $library->slug, 'series' => 'ف', 'source' => 'mtif.org/book/1', 'source_text' => 'نگارش آزمایشی',
            'volumes' => [
                ['number' => 1, 'mtif_entry_id' => 880001, 'title' => 'فهرست آزمایشی - 1'],
                ['number' => 2, 'mtif_entry_id' => 880002, 'title' => 'فهرست آزمایشی - 2'],
            ],
            'catalogers' => [
                ['mtif_entry_id' => 990001, 'name' => 'فهرست‌نگار موجود'],
                ['mtif_entry_id' => 990002, 'name' => 'فهرست‌نگار تازه', 'new' => true],
            ],
        ]]);

        $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath, '--dry-run' => true])->assertSuccessful();
        $this->assertNull($v1->fresh()->cataloger_id);
        $this->assertNull(Cataloger::where('mtif_entry_id', 990002)->first());

        $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath])->assertSuccessful();
        $vol1 = CatalogVolume::where('mtif_entry_id', 880001)->firstOrFail();
        $vol2 = CatalogVolume::where('mtif_entry_id', 880002)->firstOrFail();
        $new = Cataloger::where('mtif_entry_id', 990002)->firstOrFail();
        $this->assertSame([$vol1->id, $existing->id], [$v1->fresh()->catalog_volume_id, $v1->fresh()->cataloger_id]);
        $this->assertSame($vol2->id, $v2->fresh()->catalog_volume_id);
        $this->assertNull($v9->fresh()->catalog_volume_id);
        $this->assertNull($other->fresh()->catalog_volume_id);
        $this->assertEqualsCanonicalizing([$existing->id, $new->id], $vol1->catalogers()->pluck('catalogers.id')->all());
        $this->assertSame(1, $vol1->fresh()->manuscripts_count);
        $this->assertSame(2, $existing->fresh()->manuscripts_count);
        $this->assertSame('library_database', $database->fresh()->metadata['catalog_source']);

        $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath])->assertSuccessful();
        $this->assertSame(1, CatalogVolume::where('mtif_entry_id', 880001)->count());
        $this->assertSame(1, Cataloger::where('mtif_entry_id', 990002)->count());
    }

    public function test_unknown_cataloger_not_marked_new_stops_the_run()
    {
        $library = Library::create(['name' => 'کتابخانه آزمایشی', 'city' => 'یزد', 'slug' => 'test-lib-' . uniqid()]);
        $ms = $this->manuscript($library, '[ف: -10]');
        $this->writeData([[
            'library' => $library->slug, 'series' => 'ف', 'source' => 's', 'source_text' => 's',
            'volumes' => [['number' => 1, 'mtif_entry_id' => 880003, 'title' => 'فهرست']],
            'catalogers' => [['mtif_entry_id' => 990009, 'name' => 'ناشناخته']],
        ]]);

        try {
            $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath])->run();
            $this->fail('Expected the run to stop');
        } catch (\RuntimeException) {
        }
        $this->assertNull($ms->fresh()->catalog_volume_id);
    }

    public function test_razavi_copies_on_malek_volumes_go_to_the_cited_razavi_volume()
    {
        $razavi = Library::create(['name' => 'رضوی آزمایشی', 'city' => 'مشهد', 'slug' => 'razavi-' . uniqid()]);
        $malek = Library::create(['name' => 'ملک آزمایشی', 'city' => 'تهران', 'slug' => 'malek-' . uniqid()]);
        $oktayi = Cataloger::create(['name' => 'فهرست‌نگار رضوی', 'slug' => 'rz-' . uniqid()]);
        $malekVol = CatalogVolume::create(['title' => 'فهرست ملک - 2', 'volume_number' => 2, 'library_id' => $razavi->id, 'mtif_entry_id' => 870002]);
        $razaviVol = CatalogVolume::create(['title' => 'فهرست رضوی - 2', 'volume_number' => 2, 'library_id' => $razavi->id, 'mtif_entry_id' => 870102]);
        $razaviVol->catalogers()->attach($oktayi->id, ['role' => 'cataloger']);
        $cited = $this->manuscript($razavi, '[ف: 2-30]', null, $malekVol->id);
        $donated = $this->manuscript($razavi, '[اهدائی ف: 26]', null, $malekVol->id);

        file_put_contents($this->dataPath, json_encode(['links' => [], 'malek' => [
            'library' => $malek->slug, 'volumes' => [870002],
            'razavi_library' => $razavi->slug, 'razavi_volumes' => ['2' => 870102],
        ]], JSON_UNESCAPED_UNICODE));
        $this->artisan('catalogs:link-volumes', ['--data' => $this->dataPath])->assertSuccessful();

        $this->assertSame($malek->id, $malekVol->fresh()->library_id);
        $this->assertSame([$razaviVol->id, $oktayi->id], [$cited->fresh()->catalog_volume_id, $cited->fresh()->cataloger_id]);
        $this->assertNull($donated->fresh()->catalog_volume_id);
    }

    private function manuscript(Library $library, ?string $citation, ?string $raw = null, ?int $volumeId = null): Manuscript
    {
        return Manuscript::create([
            'work_id' => $this->work->id, 'library_id' => $library->id, 'catalog_volume_id' => $volumeId,
            'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1,
            'metadata' => ['catalog_citation' => $citation], 'raw_text' => $raw,
        ]);
    }

    private function writeData(array $links): void
    {
        file_put_contents($this->dataPath, json_encode(['links' => $links], JSON_UNESCAPED_UNICODE));
    }
}
