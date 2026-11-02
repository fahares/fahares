<?php

namespace Tests\Feature;

use App\Console\Commands\ImportVolumePartsCommand as Import;
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
 * catalogs:import-volume-parts (step 13.159): a catalogue of several libraries takes the volumes held as a library's
 * catalogue, its copies their volume, and its parts from the data file; a backup puts it all back.
 */
class ImportVolumePartsTest extends TestCase
{
    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'الکافی', 'clean_title' => 'الکافی', 'slug' => 'work-ivp-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function volume(Source $source, int $number, ?int $library = null): SourceVolume
    {
        $columns = ['title' => $source->title . ' - ' . $number, 'volume_number' => $number];
        $cv = CatalogVolume::create($columns + ['library_id' => $library]);
        DB::table('source_volumes')->updateOrInsert(['id' => $cv->id], $columns + ['source_id' => $source->id, 'created_at' => now(), 'updated_at' => now()]);

        return SourceVolume::findOrFail($cv->id);
    }

    private function copy(Library $library, ?string $citation, ?SourceVolume $volume, ?string $raw = null): Manuscript
    {
        return Manuscript::create(['work_id' => $this->work->id, 'library_id' => $library->id, 'shelfmark' => (string) random_int(1, 99999), 'raw_text' => $raw,
            'catalog_volume_id' => $volume?->id, 'volume_number' => 1, 'page_start' => 1, 'metadata' => $citation ? ['catalog_citation' => $citation] : null]);
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
            'catalogers' => $rows('catalogers', ['id', 'name', 'volumes_count', 'manuscripts_count', 'supervised_count']),
        ];
    }

    public function test_the_volume_a_citation_names()
    {
        $this->assertSame([[9, 74], [5, 600], null], [Import::numbers('[میراث اسلامی: دفتر نهم - 74]', 'میراث اسلامی'),
            Import::numbers('[میراث اسلامی ایران: 5 - 600]', ['میراث اسلامی', 'میراث اسلامی ایران']), Import::numbers('[ف: 1 - 20]', 'میراث اسلامی')]);
        $this->assertSame([5, 5, 7, null, 2, 4, null], [Import::volume([5, 132], [4, 5, 7]), Import::volume([132, 5], [4, 5, 7]), Import::volume([7], [4, 5, 7]),
            Import::volume([200], [4, 5, 7]), Import::volume([2, 5], [2, 5]), Import::volume([4, 478, 11, 935], [4, 11]), Import::volume([], [4])]);
    }

    public function test_the_catalogue_takes_its_volumes_copies_and_parts_and_revert()
    {
        $molavi = Library::create(['name' => 'مولوی', 'city' => 'مشهد', 'slug' => 'mshhd-moloy-357']);
        $mofatah = Library::create(['name' => 'مفتاح', 'city' => 'تهران', 'slug' => 'thran-mftah-16']);
        $tabriz = Library::create(['name' => 'ثقة الاسلام', 'city' => 'تبریز', 'slug' => 'tbryz-thkh-alaslam-119']);
        $isfahan = Library::create(['name' => 'دانشگاه', 'city' => 'اصفهان', 'slug' => 'asfhan-danshgah-30']);
        $afshar = Cataloger::create(['name' => 'ایرج افشار', 'slug' => 'afshar', 'mtif_entry_id' => 37239]);
        $daneshPajouh = Cataloger::create(['name' => 'محمدتقی دانش‌پژوه', 'slug' => 'dp', 'mtif_entry_id' => 37279]);
        $tabatabaei = Cataloger::create(['name' => 'سید عبدالعزیز طباطبائی', 'slug' => 'tabatabaei', 'mtif_entry_id' => 37227]);

        $nashriyeh = Source::create(['code' => 'catalogue-nashriyeh', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'نشریه نسخه‌های خطی کتابخانه مرکزی دانشگاه تهران']);
        $fourth = $this->volume($nashriyeh, 4);
        $fifth = $this->volume($nashriyeh, 5);
        $fifth->catalogers()->attach([$afshar->id => ['role' => 'cataloger'], $daneshPajouh->id => ['role' => 'cataloger']]);
        $seventhSource = Source::create(['code' => 'catalogue-seventh', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'قاضی طباطبائی (نشریه ۷)', 'library_id' => $tabriz->id]);
        $seventh = $this->volume($seventhSource, 7, $tabriz->id);
        $seventh->catalogers()->attach([$tabatabaei->id => ['role' => 'cataloger']]);
        $fourthSource = Source::create(['code' => 'catalogue-fourth', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'ثقة الاسلام (نشریه ۴)', 'library_id' => $tabriz->id]);
        $heldFourth = $this->volume($fourthSource, 4, $tabriz->id);
        $zanjan = Library::create(['name' => 'امام جمعه', 'city' => 'زنجان', 'slug' => 'zngan-amam-gmaah-66']);
        $zanjanSource = Source::create(['code' => 'catalogue-zanjan', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'فهرست مدرسه امام جمعه زنجان', 'library_id' => $zanjan->id]);
        $zanjanVolume = $this->volume($zanjanSource, 1, $zanjan->id);

        $copies = [
            'on the catalogue' => [$this->copy($molavi, '[نشریه: 5 - 40]', $fifth), 5, 'mshhd-moloy-357'],
            'reversed, untied' => [$this->copy($molavi, '[نشریه: 41-5]', null), 5, 'mshhd-moloy-357'],
            'on the seventh' => [$this->copy($mofatah, '[نشریه: 7 - 200]', $seventh), 7, 'thran-mftah-16'],
            'fourth held as seventh' => [$this->copy($tabriz, '[نشریه: 7 - 535]', $heldFourth), 7, 'tbryz-thkh-alaslam-119'],
            'no bracket' => [$this->copy($tabriz, null, $heldFourth, 'بی‌کا تا قرن 10 نشریه: 7 - 537]'), 7, 'tbryz-thkh-alaslam-119'],
            'on the held fourth' => [$this->copy($tabriz, '[نشریه: 4 - 326]', $heldFourth), 4, 'tbryz-thkh-alaslam-119'],
            'on a kept source' => [$this->copy($zanjan, '[نشریه: 5 - 150]', $zanjanVolume), 5, 'zngan-amam-gmaah-66'],
            'untied, no entry' => [$this->copy($molavi, null, null, 'بی‌کا نشریه: 5 - 60]'), 5, 'mshhd-moloy-357'],
        ];
        $zanjanOwn = $this->copy($zanjan, '[ف: 1 - 20]', $zanjanVolume);
        $stray = $this->copy($isfahan, '[پیش‌تر در دو جای دیگر معرفی شده: نشریه: 4-478 و نشریه: 11-935]', $heldFourth);
        $noVolume = $this->copy($molavi, '[نشریه: 9 - 10]', null);

        $data = ['source' => $nashriyeh->code, 'series' => 'نشریه', 'volume_title' => 'نشریه - دفتر %d',
            'folds' => [['code' => 'catalogue-seventh', 'volume' => 7], ['code' => 'catalogue-fourth'], ['code' => 'catalogue-zanjan', 'keep' => true]],
            'editors' => [['name' => 'ایرج افشار', 'mtif' => 37239], ['name' => 'محمدتقی دانش‌پژوه', 'mtif' => 37279]],
            'parts' => [
                ['daftar' => 5, 'library' => 'mshhd-moloy-357', 'title' => 'کتابخانه عبدالمجید مولوی', 'pages' => [8, 107], 'catalogers' => [['name' => 'مهدی والئی']], 'fankha_row' => 532],
                ['daftar' => 7, 'library' => 'thran-mftah-16', 'title' => 'کتابخانه محمد حسین مفتاح', 'pages' => [95, 345], 'catalogers' => [['name' => 'محمد تقی دانش پژوه', 'mtif' => 37279]], 'fankha_row' => 244],
                ['daftar' => 7, 'library' => 'tbryz-thkh-alaslam-119', 'title' => 'کتابخانه ثقة الاسلام', 'pages' => [531, 543], 'catalogers' => [['name' => 'سید عبدالعزیز طباطبائی', 'mtif' => 37227]], 'fankha_row' => 66],
                ['daftar' => 4, 'library' => 'tbryz-thkh-alaslam-119', 'title' => 'کتابخانه ثقة الاسلام', 'pages' => [325, 328], 'catalogers' => [['name' => 'محمد تقی دانش پژوه', 'mtif' => 37279]], 'fankha_row' => 65],
                ['daftar' => 5, 'library' => 'zngan-amam-gmaah-66', 'title' => 'کتابخانه امام جمعه زنجان', 'pages' => [145, 200], 'catalogers' => [], 'key' => 'zanjan'],
                ['daftar' => 13, 'library' => null, 'title' => 'کتابخانه مجلس شورا', 'pages' => [405, 446], 'catalogers' => [['name' => 'سیمین صیدیه']], 'fankha_row' => 135],
            ]];
        $file = storage_path('framework/testing/parts-' . uniqid() . '.json');
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));

        $before = $this->state();
        $this->artisan('catalogs:import-volume-parts', ['--data' => $file, '--dry-run' => true])->assertSuccessful()->expectsOutput('6 copies tied anew.');
        $this->assertSame($before, $this->state(), 'a dry run saves nothing');

        $backup = storage_path('framework/testing/parts-backup-' . uniqid() . '.json');
        $this->artisan('catalogs:import-volume-parts', ['--data' => $file, '--backup' => $backup])->assertSuccessful();

        $volumes = $nashriyeh->volumes()->get()->keyBy('volume_number');
        $this->assertSame([4, 5, 7], $volumes->keys()->all());
        $this->assertSame($seventh->id, $volumes[7]->id, 'the folded source\'s volume is the seventh');
        $this->assertNull(CatalogVolume::find($seventh->id)->library_id);
        $this->assertNull(Source::where('code', 'catalogue-seventh')->first());
        $this->assertNull(Source::where('code', 'catalogue-fourth')->first());
        $this->assertNull(CatalogVolume::find($heldFourth->id), 'the emptied volume goes');
        $this->assertSame($nashriyeh->id, Source::mergedInto('catalogue-fourth')?->id);
        foreach ($volumes as $volume) {
            $this->assertEqualsCanonicalizing([[$afshar->id, 'supervisor'], [$daneshPajouh->id, 'supervisor']],
                $volume->catalogers()->get()->map(fn ($c) => [$c->id, $c->pivot->role])->all());
        }
        foreach ($copies as $label => [$copy, $number, $slug]) {
            $copy = $copy->fresh();
            $this->assertSame($volumes[$number]->id, $copy->catalog_volume_id, $label);
            $this->assertSame($slug, $copy->catalogVolumePart?->library?->slug, $label);
        }
        $this->assertNull($stray->fresh()->catalog_volume_id, 'another source\'s copy on a folded volume is untied');
        $this->assertNull($noVolume->fresh()->catalog_volume_id);
        $this->assertSame([$zanjanVolume->id, 1], [$zanjanOwn->fresh()->catalog_volume_id, CatalogVolume::find($zanjanVolume->id)->manuscripts_count], 'a kept source keeps its own copies');
        $this->assertNotNull(Source::where('code', 'catalogue-zanjan')->first());
        $valaei = Cataloger::where('name', 'مهدی والئی')->firstOrFail();
        $this->assertSame([3, 0], [$valaei->manuscripts_count, $valaei->supervised_count]);
        $this->assertSame([2, 8], [$tabatabaei->fresh()->manuscripts_count, $afshar->fresh()->supervised_count]);
        $this->assertNull(Cataloger::where('name', 'سیمین صیدیه')->first(), 'a part of a volume the catalogue has not is not made, nor its cataloger');

        $after = $this->state();
        $this->artisan('catalogs:import-volume-parts', ['--data' => $file])->assertSuccessful()->expectsOutput('Nothing to do.');
        $this->assertSame($after, $this->state());

        $this->artisan('catalogs:import-volume-parts', ['--revert' => $backup])->assertSuccessful();
        $this->assertEquals($before, $this->state());
        @unlink($backup);
        @unlink($file);
    }

    public function test_a_catalogue_the_registry_has_not_is_made_and_its_revert_removes_it()
    {
        $soleimaniyeh = Library::create(['name' => 'مدرسه سلیمانیه', 'city' => 'مشهد', 'slug' => 'mshhd-mdrsh-slymanyh-193']);
        $tehran = Library::create(['name' => 'دانشگاه', 'city' => 'تهران', 'slug' => 'thran-danshgah-3']);
        $microfilms = Source::create(['code' => 'catalogue-microfilms', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'فهرست میکروفیلم‌ها', 'library_id' => $tehran->id]);
        $held = $this->volume($microfilms, 1, $tehran->id);
        $onMicrofilms = $this->copy($soleimaniyeh, '[چهار کتابخانه مشهد-ف: 12]', $held);
        $own = $this->copy($tehran, '[ف: 1 - 5]', $held);
        $untied = $this->copy($soleimaniyeh, '[چهار کتابخانه مشهد: 3]', null);

        $data = ['source' => 'catalogue-four-mashhad', 'series' => 'چهار کتابخانه مشهد', 'aliases' => ['چهار کتابخانه مشهد - ف'],
            'volume_title' => 'فهرست نسخه‌های خطی چهار کتابخانه مشهد - جلد %d',
            'create' => ['title' => 'فهرست نسخه‌های خطی چهار کتابخانه مشهد', 'publisher' => 'انتشارات فرهنگ ایران زمین', 'year' => '1351',
                'volumes' => [['number' => 1, 'mtif' => 32537, 'pages' => 435]]],
            'folds' => [['code' => 'catalogue-microfilms', 'keep' => true]],
            'editors' => [['name' => 'محمدتقی دانش‌پژوه', 'mtif' => 37279]],
            'parts' => [['daftar' => 1, 'library' => 'mshhd-mdrsh-slymanyh-193', 'title' => 'کتابخانه مدرسه سلیمانیه', 'pages' => [1, 24],
                'catalogers' => [['name' => 'تقی بینش', 'mtif' => 37278]], 'fankha_row' => 524]]];
        Cataloger::create(['name' => 'محمدتقی دانش‌پژوه', 'slug' => 'dp', 'mtif_entry_id' => 37279]);
        $file = storage_path('framework/testing/parts-' . uniqid() . '.json');
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE));

        $before = $this->state();
        $this->artisan('catalogs:import-volume-parts', ['--data' => $file, '--dry-run' => true])->assertSuccessful()->expectsOutput('2 copies tied anew.');
        $this->assertSame($before, $this->state());
        $backup = storage_path('framework/testing/parts-backup-' . uniqid() . '.json');
        $this->artisan('catalogs:import-volume-parts', ['--data' => $file, '--backup' => $backup])->assertSuccessful();

        $made = Source::where('code', 'catalogue-four-mashhad')->firstOrFail();
        $volume = $made->volumes()->firstOrFail();
        $this->assertSame([1, 32537, 'چهار کتابخانه مشهد: 1'], [$volume->volume_number, $volume->mtif_entry_id, $volume->citation_pattern]);
        $this->assertSame($volume->id, CatalogVolume::findOrFail($volume->id)->id, 'a catalog volume under the same id');
        foreach ([$onMicrofilms, $untied] as $copy) {
            $this->assertSame([$volume->id, 'mshhd-mdrsh-slymanyh-193'], [$copy->fresh()->catalog_volume_id, $copy->fresh()->catalogVolumePart?->library?->slug]);
        }
        $this->assertSame($held->id, $own->fresh()->catalog_volume_id);
        $this->get(route('sources.show', $made->code))->assertOk()->assertSee('data-volume-part>', false)->assertSee('کتابخانه مدرسه سلیمانیه');
        $this->artisan('catalogs:import-volume-parts', ['--data' => $file])->assertSuccessful()->expectsOutput('Nothing to do.');

        $this->artisan('catalogs:import-volume-parts', ['--revert' => $backup])->assertSuccessful();
        $this->assertEquals($before, $this->state());
        $this->assertNull(Source::where('code', 'catalogue-four-mashhad')->first());
        @unlink($backup);
        @unlink($file);
    }
}
