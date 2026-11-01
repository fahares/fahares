<?php

namespace Tests\Feature;

use App\Console\Commands\FillEmptyCataloguesCommand as Fill;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sources:retie-stray-copies (step 13.114): a library held twice folds into the one with its catalogue and its copies
 * go to the volume they cite; copies of any library cited from Tehran's microfilm catalogue go to its volumes and take
 * its cataloger; a volume of Tehran's theology catalogue takes the faculty's library.
 */
class RetieStrayCopiesTest extends TestCase
{
    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'الکافی', 'clean_title' => 'الکافی', 'slug' => 'work-retie-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function library(string $slug, string $name, string $city): Library
    {
        return Library::create(['name' => $name, 'city' => $city, 'slug' => $slug]);
    }

    /** A catalogue source with its volumes numbered 1…n, each a catalog volume under the same id. */
    private function catalogue(string $code, string $title, Library $library, int $volumes, ?Cataloger $cataloger = null): array
    {
        $source = Source::create(['code' => $code, 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => $title, 'library_id' => $library->id]);
        $ids = [];
        foreach (range(1, $volumes) as $n) {
            $cv = CatalogVolume::create(['title' => "{$title} - {$n}", 'volume_number' => $n, 'library_id' => $library->id]);
            DB::table('source_volumes')->updateOrInsert(['id' => $cv->id], ['source_id' => $source->id, 'title' => "{$title} - {$n}",
                'volume_number' => $n, 'created_at' => now(), 'updated_at' => now()]);
            if ($cataloger) {
                DB::table('catalog_volume_cataloger')->insert(['catalog_volume_id' => $cv->id, 'cataloger_id' => $cataloger->id, 'role' => 'cataloger']);
            }
            $ids[$n] = $cv->id;
        }

        return [$source, $ids];
    }

    private function copy(Library $library, string $citation, ?int $volume, ?Cataloger $cataloger = null): Manuscript
    {
        $copy = Manuscript::create(['work_id' => $this->work->id, 'library_id' => $library->id, 'shelfmark' => uniqid(),
            'catalog_volume_id' => $volume, 'volume_number' => 1, 'page_start' => 1, 'metadata' => ['catalog_citation' => $citation]]);
        DB::table('manuscripts')->where('id', $copy->id)->update(['cataloger_id' => $cataloger?->id]);

        return $copy;
    }

    private function entry(Manuscript $copy): ?SourceEntry
    {
        return SourceEntry::where('legacy_type', 'fankha_citation')->where('legacy_id', $copy->id)->first();
    }

    public function test_stray_copies_go_to_the_volume_they_cite()
    {
        $danesh = Cataloger::create(['name' => 'محمدتقی دانش‌پژوه', 'slug' => 'danesh-' . uniqid()]);
        $monzavi = Cataloger::create(['name' => 'علینقی منزوی', 'slug' => 'monzavi-' . uniqid()]);

        $central = $this->library(Fill::MICROFILMS_LIBRARY, 'دانشگاه', 'تهران');
        $twice = $this->library('thran-danshgah-thran-365', 'دانشگاه تهران', 'تهران');
        $mashhad = $this->library('mshhd-alhyat-38', 'الهیات', 'مشهد');
        $faculty = $this->library('mshhd-danshkdh-alhyat-mshhd-341', 'دانشکده الهیات مشهد', 'مشهد');
        $tehranTheology = $this->library('thran-alhyat-40', 'الهیات', 'تهران');
        $majlis = $this->library('majlis-retie-' . uniqid(), 'مجلس', 'تهران');

        [, $mashhadVolumes] = $this->catalogue('catalogue-mashhad-test', 'فهرست نسخه‌های خطی کتابخانه دانشکده الهیات مشهد', $mashhad, 2);
        [, $theology] = $this->catalogue('catalogue-theology-test', 'فهرست نسخه‌های خطی کتابخانه دانشکده الهیات', $tehranTheology, 1);
        $second = CatalogVolume::create(['title' => 'فهرست نسخه های خطی کتابخانه دانشکده الهیات - 2', 'volume_number' => 2,
            'library_id' => $central->id, 'mtif_entry_id' => 21490]);
        [$centralSource, $centralVolumes] = $this->catalogue('catalogue-central-test', 'فهرست نسخه های خطی کتابخانه مرکزی دانشگاه تهران', $central, 16);
        [$films, $filmVolumes] = $this->catalogue(Fill::MICROFILMS, 'فهرست میکروفیلم‌های کتابخانه مرکزی دانشگاه تهران', $central, 3, $danesh);
        [, $majlisVolumes] = $this->catalogue('catalogue-majlis-test', 'فهرست کتابخانه مجلس شورای اسلامی', $majlis, 3);

        foreach (range(1, 9) as $i) {
            $this->copy($mashhad, "[ف: 1-{$i}]", $mashhadVolumes[1]);
            $this->copy($central, "[ف: 16-{$i}]", $centralVolumes[16]);
        }
        $this->copy($mashhad, '[ف: 2-1]', $mashhadVolumes[2]);
        $onTehran = $this->copy($faculty, '[ف: 1-50]', $theology[1]);
        $untied = $this->copy($faculty, '[ف: 2-88]', null);
        $cited16 = $this->copy($twice, '[ف: 16-115]', null);
        $filmOfTwice = $this->copy($twice, '[فیلمها ف: 2-237]', null);
        $majlisFilm = $this->copy($majlis, '[فیلمها ف: 3-89]', $majlisVolumes[3]);
        $majlisOwn = $this->copy($majlis, '[ف: 3-12]', $majlisVolumes[3]);
        $meshkatLeft = $this->copy($central, '[فیلمها ف: 1-10]', $filmVolumes[1], $monzavi);

        $this->artisan('sources:retie-stray-copies', ['--dry-run' => true])->assertSuccessful();
        $this->assertNotNull(Library::find($faculty->id), 'a dry run saves nothing');
        $this->assertSame($theology[1], $onTehran->fresh()->catalog_volume_id);

        $this->artisan('sources:retie-stray-copies')->assertSuccessful();

        // the libraries held twice are gone behind a redirect, their names kept
        $this->assertNull(Library::find($faculty->id));
        $this->assertNull(Library::find($twice->id));
        $this->assertContains('دانشکده الهیات مشهد', $mashhad->fresh()->aliases);
        $this->get('/libraries/' . $faculty->id)->assertMovedPermanently();
        $this->assertSame([12, 12], [$mashhad->fresh()->manuscripts_count, $central->fresh()->manuscripts_count]);

        // each copy on the volume it cites, its entry with it
        foreach ([[$onTehran, $mashhadVolumes[1], $mashhad], [$untied, $mashhadVolumes[2], $mashhad], [$cited16, $centralVolumes[16], $central],
            [$filmOfTwice, $filmVolumes[2], $central], [$majlisFilm, $filmVolumes[3], $majlis]] as [$copy, $volume, $library]) {
            $copy = $copy->fresh();
            $this->assertSame([$library->id, $volume], [$copy->library_id, $copy->catalog_volume_id]);
            $this->assertSame($volume, $this->entry($copy)->source_volume_id);
        }
        $this->assertSame($centralSource->id, $this->entry($cited16)->source_id);
        $this->assertSame($films->id, $this->entry($majlisFilm)->source_id);
        $this->assertSame($majlisVolumes[3], $majlisOwn->fresh()->catalog_volume_id);

        // the microfilm catalogue's cataloger, the theology volume's library, the counts
        $this->assertSame([$danesh->id, $danesh->id], [$majlisFilm->fresh()->cataloger_id, $meshkatLeft->fresh()->cataloger_id]);
        $this->assertSame($tehranTheology->id, $second->fresh()->library_id);
        $this->assertSame([0, 10], [CatalogVolume::find($theology[1])->manuscripts_count, CatalogVolume::find($mashhadVolumes[1])->manuscripts_count]);

        // again: nothing moves
        $state = fn () => [Library::count(), Manuscript::orderBy('id')->get(['library_id', 'catalog_volume_id', 'cataloger_id'])->toArray(),
            SourceEntry::orderBy('id')->get(['source_id', 'source_volume_id'])->toArray()];
        $before = $state();
        $this->artisan('sources:retie-stray-copies')->assertSuccessful()->expectsOutput('0 copies tied anew.');
        $this->assertSame($before, $state());
    }
}
