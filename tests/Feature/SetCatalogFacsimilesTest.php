<?php

namespace Tests\Feature;

use App\Models\Catalog;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\Work;
use Tests\TestCase;

/** catalogs:set-facsimiles (step 14.122): page images on the volumes Fankha cites, parts told apart by title. */
class SetCatalogFacsimilesTest extends TestCase
{
    private Library $library;

    private CatalogVolume $volume2;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->library = Library::create(['name' => 'مجلس', 'city' => 'تهران', 'slug' => 'majlis-' . uniqid()]);
        $fankha = Catalog::firstOrCreate(['code' => 'fankha'], ['name' => 'فهرستگان نسخه‌های خطی ایران', 'short_name' => 'فنخا']);
        $volume = fn (int $n, string $title) => CatalogVolume::create(['title' => "فهرست کتابخانه مجلس آزمایشی - {$title}", 'volume_number' => $n,
            'library_id' => $this->library->id, 'catalog_id' => $fankha->id]);
        $this->volume2 = $volume(2, 'جلد دوم');
        $volume(24, 'جلد ۲/۲۴');
        $volume(24, 'جلد ۳/۲۴');
        $this->path = tempnam(sys_get_temp_dir(), 'facsimiles');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function data(array $volumes): string
    {
        file_put_contents($this->path, json_encode(['volumes' => array_map(fn ($v) => $v + ['library_slug' => $this->library->slug], $volumes)],
            JSON_UNESCAPED_UNICODE));

        return $this->path;
    }

    public function test_the_cited_volume_gets_its_images_and_its_references_open_the_page()
    {
        $work = Work::create(['primary_title' => 'دیوان', 'clean_title' => 'دیوان', 'slug' => 'work-fx-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $copy = Manuscript::create(['work_id' => $work->id, 'library_id' => $this->library->id, 'shelfmark' => '4001/10',
            'catalog_volume_id' => $this->volume2->id, 'volume_number' => 1, 'page_start' => 900, 'sequence_number' => 1]);
        $source = SourceVolume::findOrFail($this->volume2->id);
        $entry = SourceEntry::create(['citable_type' => Manuscript::class, 'citable_id' => $copy->id, 'source_id' => $source->source_id,
            'source_volume_id' => $source->id, 'origin' => SourceEntry::ORIGIN_FANKHA_CITATION, 'page_start' => 63, 'text_status' => SourceEntry::TEXT_NONE]);
        $this->assertNull($entry->viewer());

        $path = $this->data([['volume_number' => 2, 'facsimile_code' => 'majlis-test', 'page_first' => 2, 'page_last' => 539,
            'metadata' => ['facsimile' => ['leaf_maps' => ['majlis/v02.map.json']]]]]);
        $this->artisan('catalogs:set-facsimiles', ['--data' => $path, '--dry-run' => true])->expectsOutputToContain('update: majlis-test/v2')->assertSuccessful();
        $this->assertNull($this->volume2->fresh()->facsimile_code);

        $this->artisan('catalogs:set-facsimiles', ['--data' => $path])->assertSuccessful();
        $this->artisan('catalogs:set-facsimiles', ['--data' => $path])->expectsOutputToContain('0 created, 0 updated, 1 unchanged')->assertSuccessful();

        $this->assertSame(['majlis-test', 2, 539], [$this->volume2->fresh()->facsimile_code, $this->volume2->fresh()->page_first, $this->volume2->fresh()->page_last]);
        $this->assertSame('majlis-test', SourceVolume::find($this->volume2->id)->facsimile_code);
        $this->assertSame(['majlis/v02.map.json'], $this->volume2->fresh()->metadata['facsimile']['leaf_maps']);
        // the page of the catalogue (63), not the copy's page in Fankha (900)
        $this->assertSame(['catalog' => 'majlis-test', 'volume' => 2, 'page' => 63], array_intersect_key($entry->fresh()->viewer(), array_flip(['catalog', 'volume', 'page'])));
    }

    public function test_parts_are_told_apart_by_title_and_a_missing_part_is_created_in_the_same_source()
    {
        $path = $this->data([
            ['volume_number' => 24, 'title' => 'فهرست کتابخانه مجلس آزمایشی - جلد ۳/۲۴', 'facsimile_code' => 'majlis-test-p3', 'page_first' => 7, 'page_last' => 146],
            ['volume_number' => 24, 'create' => ['title' => 'فهرست کتابخانه مجلس آزمایشی - جلد ۱/۲۴'], 'facsimile_code' => 'majlis-test-p1', 'page_first' => 1, 'page_last' => 400],
        ]);
        $this->artisan('catalogs:set-facsimiles', ['--data' => $path])->expectsOutputToContain('1 created, 1 updated')->assertSuccessful();
        $this->artisan('catalogs:set-facsimiles', ['--data' => $path])->expectsOutputToContain('0 created, 0 updated, 2 unchanged')->assertSuccessful();

        $parts = CatalogVolume::where('library_id', $this->library->id)->where('volume_number', 24)->pluck('facsimile_code', 'title');
        $this->assertSame([null, 'majlis-test-p3', 'majlis-test-p1'], array_values($parts->all()));
        $made = CatalogVolume::where('facsimile_code', 'majlis-test-p1')->sole();
        $this->assertNull($made->catalog_id);
        $this->assertSame(SourceVolume::find($this->volume2->id)->source_id, SourceVolume::find($made->id)->source_id);
    }

    public function test_a_volume_of_no_single_library_is_found_by_its_exact_title()
    {
        $title = 'نشریه آزمایشی ' . uniqid() . ' - دفتر 3';
        $daftar = CatalogVolume::create(['title' => $title, 'volume_number' => 3, 'library_id' => null]);
        $other = CatalogVolume::create(['title' => 'نشریه دیگر ' . uniqid() . ' - دفتر 3', 'volume_number' => 3, 'library_id' => null]);

        $path = $this->data([['library_slug' => null, 'volume_number' => 3, 'title' => $title, 'facsimile_code' => 'nashriyye-test', 'page_first' => 3, 'page_last' => 424]]);
        $this->artisan('catalogs:set-facsimiles', ['--data' => $path])->expectsOutputToContain('0 created, 1 updated')->assertSuccessful();
        $this->artisan('catalogs:set-facsimiles', ['--data' => $path])->expectsOutputToContain('0 created, 0 updated, 1 unchanged')->assertSuccessful();
        $this->assertSame(['nashriyye-test', 3, 424], [$daftar->fresh()->facsimile_code, $daftar->fresh()->page_first, $daftar->fresh()->page_last]);
        $this->assertNull($other->fresh()->facsimile_code);

        // without its title, or with «create», such a spec stops before any change
        $path = $this->data([['library_slug' => null, 'volume_number' => 3, 'facsimile_code' => 'nashriyye-test-2', 'page_first' => 1, 'page_last' => 9]]);
        $this->artisan('catalogs:set-facsimiles', ['--data' => $path])->expectsOutputToContain('needs its exact title')->assertFailed();
        $this->assertNull($other->fresh()->facsimile_code);
    }

    public function test_an_ambiguous_or_doubled_spec_stops_before_any_change()
    {
        $path = $this->data([['volume_number' => 24, 'facsimile_code' => 'majlis-test', 'page_first' => 1, 'page_last' => 10]]);
        $this->artisan('catalogs:set-facsimiles', ['--data' => $path])->expectsOutputToContain('found 2 rows')->assertFailed();

        $path = $this->data([
            ['volume_number' => 2, 'facsimile_code' => 'majlis-test', 'page_first' => 1, 'page_last' => 10],
            ['volume_number' => 2, 'facsimile_code' => 'majlis-test', 'page_first' => 1, 'page_last' => 10],
        ]);
        $this->artisan('catalogs:set-facsimiles', ['--data' => $path])->expectsOutputToContain('share an image folder')->assertFailed();
        $this->assertNull($this->volume2->fresh()->facsimile_code);
    }
}
