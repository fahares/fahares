<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\EntityRedirect;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class MergeCatalogDuplicatesTest extends TestCase
{
    use DatabaseTransactions;

    private Work $work;

    private string $dataPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'اثر آزمایشی', 'clean_title' => 'اثر آزمایشی', 'slug' => 'work-dup-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $this->dataPath = tempnam(sys_get_temp_dir(), 'dups') . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->dataPath);
        parent::tearDown();
    }

    public function test_duplicate_library_is_folded_and_logged_and_the_run_is_repeatable()
    {
        $target = Library::create(['name' => 'رضوی', 'city' => 'مشهد', 'slug' => 'keep-' . uniqid()]);
        $source = Library::create(['name' => 'رضوى', 'city' => 'مشهد', 'slug' => 'drop-' . uniqid()]);
        $copy = $this->manuscript($source, '[ف: 1-2]');
        $this->write(['libraries' => [['target' => $target->slug, 'sources' => [['slug' => $source->slug]]]]]);

        $this->artisan('catalogs:merge-duplicates', ['--data' => $this->dataPath, '--dry-run' => true])->assertSuccessful();
        $this->assertNotNull(Library::find($source->id));

        $this->artisan('catalogs:merge-duplicates', ['--data' => $this->dataPath])->assertSuccessful();
        $this->assertNull(Library::find($source->id));
        $this->assertSame($target->id, $copy->fresh()->library_id);
        $this->assertSame($target->id, EntityRedirect::resolveTargetId('libraries', $source->id));
        $this->assertTrue(AuditLog::where('auditable_type', Library::class)->where('auditable_id', $target->id)->where('action', 'merge')->exists());
        $this->assertTrue($target->fresh()->knowsName('رضوى'));

        $this->artisan('catalogs:merge-duplicates', ['--data' => $this->dataPath])->assertSuccessful();
    }

    public function test_misattributed_volume_gets_its_catalogue_and_a_split()
    {
        $library = Library::create(['name' => 'امام صادق', 'city' => 'اردکان', 'slug' => 'ardakan-' . uniqid()]);
        $wrong = Cataloger::create(['name' => 'نادرست', 'slug' => 'wrong-' . uniqid()]);
        $right = Cataloger::create(['name' => 'درست', 'slug' => 'right-' . uniqid(), 'mtif_entry_id' => 991101]);
        $volume = CatalogVolume::create(['title' => 'فهرست چالوس', 'volume_number' => 1, 'library_id' => $library->id]);
        $volume->catalogers()->attach($wrong->id, ['role' => 'cataloger']);
        $first = $this->manuscript($library, '[ف: 1-10]', $volume->id, $wrong->id);
        $second = $this->manuscript($library, '[ف: 2-20]', $volume->id, $wrong->id);
        $this->write(['volume_fixes' => [[
            'library' => $library->slug, 'titles' => ['فهرست چالوس', 'فهرست اردکان - دفتر اول'],
            'set' => ['title' => 'فهرست اردکان - دفتر اول', 'volume_number' => 1],
            'catalogers' => [['mtif_entry_id' => 991101, 'name' => 'درست']],
            'split' => [['number' => 2, 'title' => 'فهرست اردکان - دفتر دوم']],
            'source' => 'منبع آزمایشی',
        ]]]);

        $this->artisan('catalogs:merge-duplicates', ['--data' => $this->dataPath])->assertSuccessful();
        $volume->refresh();
        $split = CatalogVolume::where('title', 'فهرست اردکان - دفتر دوم')->firstOrFail();
        $this->assertSame('فهرست اردکان - دفتر اول', $volume->title);
        $this->assertSame([$right->id], $volume->catalogers()->pluck('catalogers.id')->all());
        $this->assertSame([$volume->id, $right->id], [$first->fresh()->catalog_volume_id, $first->fresh()->cataloger_id]);
        $this->assertSame([$split->id, $right->id], [$second->fresh()->catalog_volume_id, $second->fresh()->cataloger_id]);
        $this->assertSame('منبع آزمایشی', $volume->metadata['cataloger_note']);

        $this->artisan('catalogs:merge-duplicates', ['--data' => $this->dataPath])->assertSuccessful();
        $this->assertSame(1, CatalogVolume::where('title', 'فهرست اردکان - دفتر دوم')->count());
    }

    public function test_an_empty_cataloger_list_leaves_the_copies_without_one()
    {
        $library = Library::create(['name' => 'امام صادق', 'city' => 'قزوین', 'slug' => 'qazvin-' . uniqid()]);
        $wrong = Cataloger::create(['name' => 'نادرست', 'slug' => 'wrong-' . uniqid()]);
        $volume = CatalogVolume::create(['title' => 'فهرست دانشگاه - 2', 'volume_number' => 2, 'library_id' => $library->id]);
        $volume->catalogers()->attach($wrong->id, ['role' => 'cataloger']);
        $copy = $this->manuscript($library, '[ف: 2-5]', $volume->id, $wrong->id);
        $this->write(['volume_fixes' => [[
            'library' => $library->slug, 'titles' => ['فهرست دانشگاه - 2'],
            'set' => ['title' => 'فهرست قزوین - جلد دوم'], 'catalogers' => [], 'source' => 'نامعلوم',
        ]]]);

        $this->artisan('catalogs:merge-duplicates', ['--data' => $this->dataPath])->assertSuccessful();
        $this->assertNull($copy->fresh()->cataloger_id);
        $this->assertSame(0, $volume->catalogers()->count());
    }

    public function test_duplicate_volume_is_folded_into_the_kept_one()
    {
        $library = Library::create(['name' => 'ملک', 'city' => 'تهران', 'slug' => 'malek-' . uniqid()]);
        $a = Cataloger::create(['name' => 'الف', 'slug' => 'a-' . uniqid()]);
        $b = Cataloger::create(['name' => 'ب', 'slug' => 'b-' . uniqid()]);
        $keep = CatalogVolume::create(['title' => 'ملک - جلد اول', 'volume_number' => 1, 'library_id' => $library->id]);
        $drop = CatalogVolume::create(['title' => 'ملک - 1', 'volume_number' => 1, 'library_id' => $library->id, 'mtif_entry_id' => 881446]);
        $keep->catalogers()->attach($a->id, ['role' => 'cataloger']);
        $drop->catalogers()->attach($b->id, ['role' => 'cataloger']);
        $copy = $this->manuscript($library, '[ف: 1-3]', $drop->id, $b->id);
        $this->write(['volume_merges' => [['drop' => ['mtif' => 881446], 'keep' => ['library' => $library->slug, 'title' => 'ملک - جلد اول']]]]);

        $this->artisan('catalogs:merge-duplicates', ['--data' => $this->dataPath])->assertSuccessful();
        $this->assertNull(CatalogVolume::find($drop->id));
        $this->assertSame($keep->id, $copy->fresh()->catalog_volume_id);
        $this->assertSame($b->id, $copy->fresh()->cataloger_id);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $keep->catalogers()->pluck('catalogers.id')->all());
        $this->assertSame(881446, $keep->fresh()->mtif_entry_id);
        $this->assertSame(1, $keep->fresh()->manuscripts_count);

        // the kept volume now carries the MTIF id the duplicate was found by: a second run leaves it alone
        $this->artisan('catalogs:merge-duplicates', ['--data' => $this->dataPath])->assertSuccessful();
        $this->assertNotNull(CatalogVolume::find($keep->id));
        $this->assertSame($keep->id, $copy->fresh()->catalog_volume_id);
    }

    public function test_a_reassigning_merge_gives_the_copies_the_kept_volumes_cataloger()
    {
        $library = Library::create(['name' => 'فاضل', 'city' => 'خوانسار', 'slug' => 'fazel-' . uniqid()]);
        $right = Cataloger::create(['name' => 'درست', 'slug' => 'right-' . uniqid()]);
        $wrong = Cataloger::create(['name' => 'نادرست', 'slug' => 'wrong-' . uniqid()]);
        $keep = CatalogVolume::create(['title' => 'فاضل - 1', 'volume_number' => 1, 'library_id' => $library->id, 'mtif_entry_id' => 881384]);
        $drop = CatalogVolume::create(['title' => 'عمومی فاضل - جلد 1', 'volume_number' => 1, 'library_id' => $library->id]);
        $keep->catalogers()->attach($right->id, ['role' => 'cataloger']);
        $drop->catalogers()->attach($wrong->id, ['role' => 'cataloger']);
        $copy = $this->manuscript($library, '[ف: 1-3]', $drop->id, $wrong->id);
        $this->write(['volume_merges' => [['drop' => ['library' => $library->slug, 'title' => 'عمومی فاضل - جلد 1'], 'keep' => ['mtif' => 881384], 'reassign' => true]]]);

        $this->artisan('catalogs:merge-duplicates', ['--data' => $this->dataPath])->assertSuccessful();
        $this->assertSame([$keep->id, $right->id], [$copy->fresh()->catalog_volume_id, $copy->fresh()->cataloger_id]);
        $this->assertSame([$right->id], $keep->catalogers()->pluck('catalogers.id')->all());
    }

    private function manuscript(Library $library, string $citation, ?int $volumeId = null, ?int $catalogerId = null): Manuscript
    {
        return Manuscript::create([
            'work_id' => $this->work->id, 'library_id' => $library->id, 'catalog_volume_id' => $volumeId, 'cataloger_id' => $catalogerId,
            'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1, 'metadata' => ['catalog_citation' => $citation],
        ]);
    }

    private function write(array $data): void
    {
        file_put_contents($this->dataPath, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
