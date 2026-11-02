<?php

namespace Tests\Feature;

use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceVolume;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * catalogs:remove-catalogue (step 14.9): a catalogue that is not a book goes, its copies first taken to the books the
 * data file names; a backup puts it all back.
 */
class RemoveCatalogueTest extends TestCase
{
    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'الکافی', 'clean_title' => 'الکافی', 'slug' => 'work-rc-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function volume(Source $source, int $number, ?int $library = null): SourceVolume
    {
        $columns = ['title' => $source->title . ' - ' . $number, 'volume_number' => $number];
        $cv = CatalogVolume::create($columns + ['library_id' => $library]);
        DB::table('source_volumes')->updateOrInsert(['id' => $cv->id], $columns + ['source_id' => $source->id, 'created_at' => now(), 'updated_at' => now()]);

        return SourceVolume::findOrFail($cv->id);
    }

    private function copy(Library $library, string $shelfmark, string $citation, int $page, SourceVolume $volume): Manuscript
    {
        $copy = Manuscript::create(['work_id' => $this->work->id, 'library_id' => $library->id, 'shelfmark' => $shelfmark,
            'catalog_volume_id' => $volume->id, 'volume_number' => 1, 'page_start' => $page, 'metadata' => ['catalog_citation' => $citation]]);
        DB::table('manuscript_catalog_references')->insert(['manuscript_id' => $copy->id, 'catalog_volume_id' => $volume->id, 'volume_number' => 1,
            'page_start' => $page, 'raw_text' => $citation, 'source' => 'fankha', 'status' => 'unverified', 'created_at' => now(), 'updated_at' => now()]);
        \App\Sources\SourceEntrySync::syncCited(DB::table('manuscripts')->where('id', $copy->id)->get(['id', 'metadata', 'raw_text', 'catalog_volume_id']));

        return $copy;
    }

    private function state(): array
    {
        $rows = fn ($table, $columns = ['*']) => DB::table($table)->orderBy('id')->get($columns)->map(fn ($r) => (array) $r)->all();

        return [
            'sources' => $rows('sources'),
            'source_volumes' => $rows('source_volumes'),
            'catalog_volumes' => $rows('catalog_volumes'),
            'pivot' => $rows('catalog_volume_cataloger', ['id', 'catalog_volume_id', 'cataloger_id', 'role']),
            'parts' => $rows('catalog_volume_parts', ['id', 'manuscripts_count']),
            'entries' => $rows('source_entries', ['id', 'source_id', 'source_volume_id', 'volume_number', 'page_start']),
            'references' => $rows('manuscript_catalog_references', ['id', 'catalog_volume_id']),
            'copies' => $rows('manuscripts', ['id', 'catalog_volume_id', 'catalog_volume_part_id', 'cataloger_id']),
            'catalogers' => $rows('catalogers', ['id', 'volumes_count', 'manuscripts_count', 'supervised_count']),
        ];
    }

    private function data(array $copies): string
    {
        $file = storage_path('framework/testing/remove-' . uniqid() . '.json');
        file_put_contents($file, json_encode(['code' => 'catalogue-zanjan', 'copies' => $copies], JSON_UNESCAPED_UNICODE));

        return $file;
    }

    public function test_the_catalogue_goes_its_copies_to_their_books_and_revert()
    {
        $zanjan = Library::create(['name' => 'امام جمعه', 'city' => 'زنجان', 'slug' => 'zngan-amam-gmaah-66']);
        $hazrati = Cataloger::create(['name' => 'صادق حضرتی آشتیانی', 'slug' => 'hazrati']);
        $ashkevari = Cataloger::create(['name' => 'سید جعفر حسینی اشکوری', 'slug' => 'ashkevari']);

        $fake = Source::create(['code' => 'catalogue-zanjan', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'فهرست مدرسه امام جمعه زنجان', 'library_id' => $zanjan->id]);
        $fakeVolume = $this->volume($fake, 1, $zanjan->id);
        $fakeVolume->catalogers()->attach([$hazrati->id => ['role' => 'cataloger']]);
        $awraq = Source::create(['code' => 'catalogue-awraq', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'اوراق عتیق']);
        $awraqVolume = $this->volume($awraq, 1);
        $part = DB::table('catalog_volume_parts')->insertGetId(['catalog_volume_id' => $awraqVolume->id, 'library_id' => $zanjan->id,
            'title' => 'کتابخانه امام جمعه زنجان', 'page_first' => 145, 'page_last' => 200, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('catalog_volume_part_cataloger')->insert(['catalog_volume_part_id' => $part, 'cataloger_id' => $ashkevari->id, 'created_at' => now(), 'updated_at' => now()]);
        $dalil = Source::create(['code' => 'catalogue-dalil', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'دلیل المخطوطات']);
        $dalilVolume = $this->volume($dalil, 1);

        $numbered = $this->copy($zanjan, '114', '[ف: 1 – 171]', 171, $fakeVolume);
        $collection = $this->copy($zanjan, '12مجموعه بدون شماره', '[: 1-151]', 151, $fakeVolume);
        $copies = [
            ['library' => 'zngan-amam-gmaah-66', 'shelfmark' => '114', 'page' => 171, 'to' => 'catalogue-awraq', 'volume' => 1],
            ['library' => 'zngan-amam-gmaah-66', 'shelfmark' => '12مجموعه بدون شماره', 'page' => 151, 'to' => 'catalogue-dalil', 'volume' => 1],
        ];

        $before = $this->state();
        $this->artisan('catalogs:remove-catalogue', ['--data' => $this->data([$copies[0]])])->assertFailed();
        $this->artisan('catalogs:remove-catalogue', ['--data' => $this->data($copies), '--dry-run' => true])->assertSuccessful();
        $this->assertSame($before, $this->state(), 'a dry run, or a copy left unnamed, saves nothing');

        $backup = storage_path('framework/testing/remove-backup-' . uniqid() . '.json');
        $this->artisan('catalogs:remove-catalogue', ['--data' => $this->data($copies), '--backup' => $backup])->assertSuccessful();

        $this->assertNull(Source::find($fake->id));
        $this->assertNull(CatalogVolume::find($fakeVolume->id));
        $this->assertSame(0, DB::table('catalog_volume_cataloger')->where('catalog_volume_id', $fakeVolume->id)->count());
        $this->assertSame([$awraqVolume->id, $part, $ashkevari->id], [$numbered->fresh()->catalog_volume_id, $numbered->fresh()->catalog_volume_part_id, $numbered->fresh()->cataloger_id]);
        $this->assertSame($dalilVolume->id, $collection->fresh()->catalog_volume_id);
        $this->assertEquals([[$awraq->id, $awraqVolume->id, 171], [$dalil->id, $dalilVolume->id, 151]], DB::table('source_entries')->whereIn('legacy_id', [$numbered->id, $collection->id])
            ->where('legacy_type', 'fankha_citation')->orderBy('page_start', 'desc')->get()->map(fn ($e) => [$e->source_id, $e->source_volume_id, $e->page_start])->all());
        $this->assertSame([$awraqVolume->id, $dalilVolume->id], DB::table('manuscript_catalog_references')->whereIn('manuscript_id', [$numbered->id, $collection->id])
            ->orderBy('page_start', 'desc')->pluck('catalog_volume_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([0, 0], [$hazrati->fresh()->volumes_count, $hazrati->fresh()->manuscripts_count]);
        $this->assertSame(1, DB::table('catalog_volume_parts')->where('id', $part)->value('manuscripts_count'));

        $this->artisan('catalogs:remove-catalogue', ['--data' => $this->data($copies)])->assertSuccessful()->expectsOutputToContain('nothing to do');

        $this->artisan('catalogs:remove-catalogue', ['--revert' => $backup])->assertSuccessful();
        $this->assertSame($before, $this->state(), 'the revert puts back every row');
    }
}
