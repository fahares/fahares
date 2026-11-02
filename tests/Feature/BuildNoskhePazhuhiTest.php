<?php

namespace Tests\Feature;

use App\Console\Commands\BuildNoskhePazhuhiCommand as Build;
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
 * sources:build-noskhe-pazhuhi (step 13.153): the volume held as Allameh Tabatabaei's «(پزشکی)» catalogue becomes
 * «نسخه‌پژوهی» with three daftars and a part for each library; its copies go to their daftar and part; a backup puts
 * it all back.
 */
class BuildNoskhePazhuhiTest extends TestCase
{
    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'الکافی', 'clean_title' => 'الکافی', 'slug' => 'work-np-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function copy(Library $library, string $citation, ?SourceVolume $volume, ?Cataloger $cataloger = null): Manuscript
    {
        return Manuscript::create(['work_id' => $this->work->id, 'library_id' => $library->id, 'shelfmark' => (string) random_int(1, 99999),
            'catalog_volume_id' => $volume?->id, 'cataloger_id' => $cataloger?->id, 'volume_number' => 1, 'page_start' => 1, 'metadata' => ['catalog_citation' => $citation]]);
    }

    private function state(): array
    {
        $rows = fn ($table, $columns = ['*']) => DB::table($table)->orderBy('id')->get($columns)->map(fn ($r) => (array) $r)->all();

        return [
            'sources' => $rows('sources'),
            'source_volumes' => $rows('source_volumes'),
            'catalog_volumes' => $rows('catalog_volumes'),
            'pivot' => DB::table('catalog_volume_cataloger')->orderBy('catalog_volume_id')->orderBy('cataloger_id')->get(['catalog_volume_id', 'cataloger_id', 'role'])->map(fn ($r) => (array) $r)->all(),
            'parts' => $rows('catalog_volume_parts', ['id']),
            'entries' => $rows('source_entries', ['id', 'source_id', 'source_volume_id', 'volume_number', 'page_start']),
            'copies' => $rows('manuscripts', ['id', 'catalog_volume_id', 'catalog_volume_part_id', 'cataloger_id']),
            'catalogers' => $rows('catalogers', ['id', 'volumes_count', 'manuscripts_count', 'supervised_count']),
        ];
    }

    public function test_the_daftars_their_parts_and_copies_and_revert()
    {
        $libraries = [];
        foreach (array_unique(array_column(Build::PARTS, 1)) as $slug) {
            $libraries[$slug] = Library::create(['name' => $slug, 'city' => 'شیراز', 'slug' => $slug]);
        }
        $other = Library::create(['name' => 'دیانی', 'city' => 'بیرجند', 'slug' => 'byrgnd-dyany-86']);
        $tabatabaei = $libraries['shyraz-aalamh-tbatbayy-64'];
        $hafezian = Cataloger::create(['name' => 'ابوالفضل حافظیان بابلی', 'slug' => 'hafezian', 'mtif_entry_id' => Build::HAFEZIAN]);
        $barakat = Cataloger::create(['name' => 'محمد برکت', 'slug' => 'barakat', 'mtif_entry_id' => Build::BARAKAT]);

        $source = Source::create(['code' => Build::CODE, 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'فهرست نسخه‌های خطی کتابخانه علامه طباطبائی شیراز (پزشکی)',
            'library_id' => $tabatabaei->id, 'publisher' => 'خانه پژوهش (نسخه‌پژوهی)']);
        $columns = ['title' => $source->title, 'volume_number' => 1, 'publication_year' => '1383', 'citation_pattern' => 'نسخه‌پژوهی'];
        $cv = CatalogVolume::create($columns + ['library_id' => $tabatabaei->id]);
        DB::table('source_volumes')->updateOrInsert(['id' => $cv->id], $columns + ['source_id' => $source->id, 'created_at' => now(), 'updated_at' => now()]);
        $old = SourceVolume::findOrFail($cv->id);
        $old->catalogers()->attach($barakat->id, ['role' => 'cataloger']);

        $copies = [
            'first' => [$this->copy($tabatabaei, '[نسخه پژوهی: 1 - 30]', $old, $barakat), 1, 'shyraz-aalamh-tbatbayy-64'],
            'second' => [$this->copy($tabatabaei, '[نسخه‌پژوهی: 2 - 145]', $old, $barakat), 2, 'shyraz-aalamh-tbatbayy-64'],
            'reversed' => [$this->copy($tabatabaei, '[نسخه پژوهی: 218-2]', $old, $barakat), 2, 'shyraz-aalamh-tbatbayy-64'],
            'dashed' => [$this->copy($tabatabaei, '[نسخه‌پژوهی: 3 – 133]', $old, $barakat), 3, 'shyraz-aalamh-tbatbayy-64'],
            'numbered' => [$this->copy($tabatabaei, '[نسخه‌پژوهی 3: -108]', null), 3, 'shyraz-aalamh-tbatbayy-64'],
            'page' => [$this->copy($tabatabaei, '[نسخه‌پژوهی: -200]', null), 2, 'shyraz-aalamh-tbatbayy-64'],
            'rowzati' => [$this->copy($libraries['asfhan-rodaty-syd-mhmd-aaly-100'], '[نسخه‌پژوهی: 1 - 150]', null), 1, 'asfhan-rodaty-syd-mhmd-aaly-100'],
            'hakim' => [$this->copy($libraries['shyraz-mdrsh-hkym-266'], '[نسخه پژوهی: 47-1]', null), 1, 'shyraz-mdrsh-hkym-266'],
            'no part' => [$this->copy($other, '[نسخه‌پژوهی: 1 - 70]', null), 1, null],
        ];
        $ambiguous = $this->copy($tabatabaei, '[نسخه پژوهی: 129]', null);
        $elsewhere = $this->copy($tabatabaei, '[ف: 1 - 20؛ نسخه پژوهی: 2 - 150]', null);

        $before = $this->state();
        $this->artisan('sources:build-noskhe-pazhuhi', ['--dry-run' => true])->assertSuccessful()->expectsOutput('8 copies tied anew.');
        $this->assertSame($before, $this->state(), 'a dry run saves nothing');

        $backup = storage_path('framework/testing/np-' . uniqid() . '.json');
        $this->artisan('sources:build-noskhe-pazhuhi', ['--backup' => $backup])->assertSuccessful();

        $source->refresh();
        $this->assertSame([Build::TITLE, null, 3], [$source->title, $source->library_id, $source->volumes_count]);
        $daftars = $source->volumes()->get()->keyBy('volume_number');
        $this->assertSame($old->id, $daftars[1]->id, 'the volume it had is daftar 1');
        $this->assertSame([21811, 22500, 30202], $daftars->pluck('mtif_entry_id')->values()->all());
        $this->assertNull(CatalogVolume::find($old->id)->library_id);
        foreach ($daftars as $daftar) {
            $this->assertSame([[$hafezian->id, 'supervisor']], $daftar->catalogers()->get()->map(fn ($c) => [$c->id, $c->pivot->role])->all());
        }
        $this->assertSame(9, DB::table('catalog_volume_parts')->whereIn('catalog_volume_id', $daftars->pluck('id'))->count());
        $zadhoush = Cataloger::where('mtif_entry_id', Build::ZADHOUSH)->firstOrFail();

        foreach ($copies as $label => [$copy, $daftar, $slug]) {
            $copy = $copy->fresh();
            $this->assertSame($daftars[$daftar]->id, $copy->catalog_volume_id, $label);
            $part = $copy->catalogVolumePart;
            $this->assertSame($slug, $part?->library?->slug, $label);
            if ($part) {
                $this->assertSame($slug === 'asfhan-rodaty-syd-mhmd-aaly-100' ? $zadhoush->id : $barakat->id, $copy->cataloger_id, $label);
            }
        }
        $this->assertNull($ambiguous->fresh()->catalog_volume_id, 'a page two parts of its library hold is left');
        $this->assertNull($elsewhere->fresh()->catalog_volume_id, 'a copy whose first citation is another source is left');
        $this->assertSame([0, 1], [$hafezian->fresh()->manuscripts_count, $zadhoush->fresh()->manuscripts_count]);
        $this->assertSame(9, $hafezian->fresh()->supervised_count);
        $this->assertSame(7, $barakat->fresh()->manuscripts_count);

        $after = $this->state();
        $this->artisan('sources:build-noskhe-pazhuhi')->assertSuccessful()->expectsOutput('Nothing to do.');
        $this->assertSame($after, $this->state());

        $this->artisan('sources:build-noskhe-pazhuhi', ['--revert' => $backup])->assertSuccessful();
        $this->assertEquals($before, $this->state());
        $this->assertNull(Cataloger::where('mtif_entry_id', Build::ZADHOUSH)->first(), 'the cataloger it made is removed');
        @unlink($backup);
    }
}
