<?php

namespace Tests\Feature;

use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceVolume;
use App\Models\Work;
use App\Sources\SourceEntrySync;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * catalogs:set-volumes (step 14.15): a catalogue held as one volume takes the volumes its copies cite, a volume
 * renamed and renumbered by title, and the copies go to the volume they cite; a backup puts it all back.
 */
class SetCatalogueVolumesTest extends TestCase
{
    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'الکافی', 'clean_title' => 'الکافی', 'slug' => 'work-scv-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function volume(Source $source, int $number, string $title, int $library): SourceVolume
    {
        $columns = ['title' => $title, 'volume_number' => $number];
        $cv = CatalogVolume::create($columns + ['library_id' => $library]);
        DB::table('source_volumes')->updateOrInsert(['id' => $cv->id], $columns + ['source_id' => $source->id, 'created_at' => now(), 'updated_at' => now()]);

        return SourceVolume::findOrFail($cv->id);
    }

    private function copy(Library $library, string $citation, SourceVolume $volume, ?int $cataloger): Manuscript
    {
        $copy = Manuscript::create(['work_id' => $this->work->id, 'library_id' => $library->id, 'shelfmark' => (string) random_int(1, 99999), 'catalog_volume_id' => $volume->id,
            'cataloger_id' => $cataloger, 'volume_number' => 1, 'page_start' => 1, 'metadata' => ['catalog_citation' => $citation]]);
        DB::table('manuscript_catalog_references')->insert(['manuscript_id' => $copy->id, 'catalog_volume_id' => $volume->id, 'raw_text' => $citation,
            'source' => 'fankha', 'status' => 'unverified', 'created_at' => now(), 'updated_at' => now()]);
        SourceEntrySync::syncCited(DB::table('manuscripts')->where('id', $copy->id)->get(['id', 'metadata', 'raw_text', 'catalog_volume_id']));

        return $copy;
    }

    private function state(): array
    {
        $rows = fn ($table, $columns = ['*']) => DB::table($table)->orderBy('id')->get($columns)->map(fn ($r) => (array) $r)->all();

        return [
            'sources' => $rows('sources', ['id', 'volumes_count']),
            'source_volumes' => $rows('source_volumes'),
            'catalog_volumes' => $rows('catalog_volumes'),
            'pivot' => DB::table('catalog_volume_cataloger')->orderBy('catalog_volume_id')->orderBy('cataloger_id')->get(['catalog_volume_id', 'cataloger_id', 'role'])->map(fn ($r) => (array) $r)->all(),
            'copies' => $rows('manuscripts', ['id', 'catalog_volume_id', 'catalog_volume_part_id', 'cataloger_id']),
            'references' => $rows('manuscript_catalog_references', ['id', 'catalog_volume_id']),
            'entries' => $rows('source_entries', ['id', 'source_id', 'source_volume_id', 'volume_number', 'page_start']),
            'catalogers' => $rows('catalogers', ['id', 'volumes_count', 'manuscripts_count', 'supervised_count']),
        ];
    }

    public function test_the_catalogue_takes_its_volumes_and_its_copies_go_to_them_and_revert()
    {
        $library = Library::create(['name' => 'مرعشی', 'city' => 'قم', 'slug' => 'qm-marashi-2']);
        $haeri = Cataloger::create(['name' => 'محمدعلی حائری', 'slug' => 'scv-haeri', 'mtif_entry_id' => 37191]);
        $hafezian = Cataloger::create(['name' => 'ابوالفضل حافظیان بابلی', 'slug' => 'scv-hafezian', 'mtif_entry_id' => 56965]);
        $source = Source::create(['code' => 'catalogue-scv', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'فهرست عکسی مرعشی', 'library_id' => $library->id, 'volumes_count' => 1]);
        $held = $this->volume($source, 1, 'فهرست عکسی مرعشی - عکسی جلد 5', $library->id);
        $held->catalogers()->attach([$hafezian->id => ['role' => 'cataloger']]);

        $first = $this->copy($library, '[عکسی ف: 1-200]', $held, $hafezian->id);
        $third = $this->copy($library, '[عکسی ف: 3-400]', $held, $hafezian->id);
        $none = $this->copy($library, '[عکسی ف: 27-300]', $held, $hafezian->id);

        $data = ['sources' => [['code' => 'catalogue-scv', 'volumes' => [
            ['match' => 'فهرست عکسی مرعشی - عکسی جلد 5', 'number' => 1, 'title' => 'فهرست عکسی مرعشی - جلد 1', 'mtif' => 21357, 'pages' => 457,
                'people' => [['name' => 'محمدعلی حائری', 'mtif' => 37191, 'role' => 'cataloger']]],
            ['number' => 3, 'title' => 'فهرست عکسی مرعشی - جلد 3', 'mtif' => 34883, 'pages' => 713,
                'people' => [['name' => 'ابوالفضل حافظیان بابلی', 'mtif' => 56965, 'role' => 'cataloger']]],
        ]]]];
        $file = storage_path('framework/testing/volumes-' . uniqid() . '.json');
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));

        $before = $this->state();
        $this->artisan('catalogs:set-volumes', ['--data' => $file, '--dry-run' => true])->assertSuccessful();
        $this->assertSame($before, $this->state(), 'a dry run saves nothing');

        $backup = storage_path('framework/testing/volumes-backup-' . uniqid() . '.json');
        $this->artisan('catalogs:set-volumes', ['--data' => $file, '--backup' => $backup])->assertSuccessful();

        $volumes = DB::table('source_volumes')->where('source_id', $source->id)->orderBy('volume_number')->get()->keyBy('volume_number');
        $this->assertSame([1, 3], $volumes->keys()->all());
        $this->assertSame([$held->id, 'فهرست عکسی مرعشی - جلد 1', 21357], [(int) $volumes[1]->id, $volumes[1]->title, (int) $volumes[1]->mtif_entry_id]);
        $this->assertSame($library->id, (int) DB::table('catalog_volumes')->where('id', $volumes[3]->id)->value('library_id'));
        $this->assertSame([[$held->id, $haeri->id], [(int) $volumes[3]->id, $hafezian->id], [$held->id, $haeri->id]],
            collect([$first, $third, $none])->map(fn ($c) => [(int) $c->fresh()->catalog_volume_id, (int) $c->fresh()->cataloger_id])->all(),
            'a copy goes to the volume it cites with its cataloger; one citing a volume the file has not stays, with its volume\'s cataloger');
        $this->assertSame((int) $volumes[3]->id, (int) DB::table('manuscript_catalog_references')->where('manuscript_id', $third->id)->value('catalog_volume_id'));
        $this->assertSame((int) $volumes[3]->id, (int) DB::table('source_entries')->where('legacy_type', 'fankha_citation')->where('legacy_id', $third->id)->value('source_volume_id'));
        $this->assertSame([2, 1], [(int) DB::table('catalog_volumes')->where('id', $held->id)->value('manuscripts_count'), (int) DB::table('catalog_volumes')->where('id', $volumes[3]->id)->value('manuscripts_count')]);
        $this->assertSame(2, (int) DB::table('sources')->where('id', $source->id)->value('volumes_count'));

        $this->artisan('catalogs:set-volumes', ['--data' => $file])->assertSuccessful()->expectsOutput('Nothing to do.');

        $this->artisan('catalogs:set-volumes', ['--revert' => $backup])->assertSuccessful();
        $this->assertSame($before, $this->state(), 'the revert puts back every row and removes the volume made');
    }
}
