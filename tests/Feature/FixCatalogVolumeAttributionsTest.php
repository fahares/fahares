<?php

namespace Tests\Feature;

use App\Console\Commands\FixCatalogVolumeAttributionsCommand as Fix;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\EntityRedirect;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FixCatalogVolumeAttributionsTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, Cataloger> keyed by MTIF entry id */
    private array $c = [];

    /** @var array<string, CatalogVolume> keyed by "slug#number" */
    private array $v = [];

    /** @var array<string, Manuscript> */
    private array $m = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([Fix::BARAKAT, Fix::BEHROUZI, Fix::FAQIR, Fix::MAQSUD, Fix::AZKAEI, Fix::SHIRVANI] as $mtif) {
            $this->c[$mtif] = Cataloger::create(['name' => 'فهرست‌نگار ' . $mtif, 'slug' => 'c-' . $mtif . '-' . uniqid(), 'mtif_entry_id' => $mtif]);
        }
        $this->c[Fix::FAQIR]->update(['name' => 'محمدصادق فقیری']);
        $work = Work::create(['primary_title' => 'اثر آزمایشی', 'clean_title' => 'اثر آزمایشی', 'slug' => 'work-fix-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);

        $wrong = [
            'shyraz-shahchragh-97#3' => [Fix::BEHROUZI, Fix::FAQIR],
            'yzd-ozyry-32#4' => [Fix::FAQIR],
            'yzd-ozyry-32#5' => [Fix::FAQIR],
            'hmdan-mdrsh-ghrb-29#1' => [Fix::AZKAEI, Fix::MAQSUD],
            'shyraz-mly-91#1' => [Fix::BEHROUZI],
            'shyraz-mly-91#2' => [Fix::BEHROUZI],
        ];
        $libraries = [];
        foreach ($wrong as $key => $mtifIds) {
            [$slug, $number] = explode('#', $key);
            $library = $libraries[$slug] ??= Library::create(['name' => 'آزمایشی ' . $slug, 'city' => 'شهر', 'slug' => $slug]);
            $volume = $this->v[$key] = CatalogVolume::create(['title' => "جلد {$number} {$slug}", 'volume_number' => (int) $number, 'library_id' => $library->id]);
            foreach ($mtifIds as $mtif) {
                $volume->catalogers()->attach($this->c[$mtif]->id, ['role' => 'cataloger']);
            }
            $first = $this->c[$mtifIds[0]]->id;
            $this->m[$key] = Manuscript::create([
                'work_id' => $work->id, 'library_id' => $library->id, 'catalog_volume_id' => $volume->id,
                'cataloger_id' => $first, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1,
            ]);
        }
    }

    private function catalogerIds(string $key): array
    {
        return $this->v[$key]->catalogers()->pluck('catalogers.id')->sort()->values()->all();
    }

    public function test_dry_run_changes_nothing()
    {
        $this->artisan('catalogs:fix-volume-attributions --dry-run')->assertSuccessful();

        $this->assertCount(2, $this->catalogerIds('shyraz-shahchragh-97#3'));
        $this->assertSame($this->c[Fix::BEHROUZI]->id, $this->m['shyraz-shahchragh-97#3']->fresh()->cataloger_id);
    }

    public function test_volumes_and_copies_move_to_the_sourced_catalogers()
    {
        $this->artisan('catalogs:fix-volume-attributions')->assertSuccessful();

        $expect = [
            'shyraz-shahchragh-97#3' => Fix::BARAKAT,
            'yzd-ozyry-32#4' => Fix::SHIRVANI,
            'yzd-ozyry-32#5' => Fix::SHIRVANI,
            'hmdan-mdrsh-ghrb-29#1' => Fix::MAQSUD,
        ];
        foreach ($expect as $key => $mtif) {
            $this->assertSame([$this->c[$mtif]->id], $this->catalogerIds($key), $key);
            $this->assertSame($this->c[$mtif]->id, $this->m[$key]->fresh()->cataloger_id, $key);
            $this->assertArrayHasKey('cataloger_source', $this->v[$key]->fresh()->metadata);
        }
        $this->assertSame(1, $this->c[Fix::MAQSUD]->fresh()->volumes_count);
    }

    public function test_azkaei_is_removed_with_a_redirect_to_the_gharb_library()
    {
        $azkaeiId = $this->c[Fix::AZKAEI]->id;
        $this->artisan('catalogs:fix-volume-attributions')->assertSuccessful();

        $this->assertNull(Cataloger::find($azkaeiId));
        $this->assertSame(
            $this->v['hmdan-mdrsh-ghrb-29#1']->library_id,
            EntityRedirect::resolveTargetId('cataloger_library', $azkaeiId)
        );
    }

    public function test_faqir_is_renamed_and_credited_with_the_fars_volumes_only()
    {
        $this->artisan('catalogs:fix-volume-attributions')->assertSuccessful();

        $faqir = $this->c[Fix::FAQIR]->fresh();
        $this->assertSame(Fix::FAQIR_NAME, $faqir->name);
        $this->assertSame(2, $faqir->volumes_count);
        $this->assertContains($faqir->id, $this->catalogerIds('shyraz-mly-91#1'));
        $this->assertContains($faqir->id, $this->catalogerIds('shyraz-mly-91#2'));
        // Behrouzi stays on the Fars volumes: he is a co-compiler there
        $this->assertContains($this->c[Fix::BEHROUZI]->id, $this->catalogerIds('shyraz-mly-91#1'));
    }

    public function test_rerun_is_harmless_and_missing_data_fails()
    {
        $this->artisan('catalogs:fix-volume-attributions')->assertSuccessful();
        $this->artisan('catalogs:fix-volume-attributions')->assertSuccessful();
        $this->assertSame([$this->c[Fix::SHIRVANI]->id], $this->catalogerIds('yzd-ozyry-32#4'));

        $this->v['yzd-ozyry-32#5']->delete();
        $this->artisan('catalogs:fix-volume-attributions')->assertFailed();
    }

    public function test_follow_ups_fix_library_year_publisher_and_hasanzadeh_name()
    {
        $razavi = Library::create(['name' => 'رضوی', 'city' => 'مشهد', 'slug' => 'mshhd-rdoy-test-' . uniqid()]);
        $qom = Library::create(['name' => 'مدرسه رضویه', 'city' => 'قم', 'slug' => Fix::QOM_RAZAVIYYE_LIBRARY_SLUG]);
        $razaviyye = CatalogVolume::create(['title' => 'فهرست مدرسه رضویه قم', 'library_id' => $razavi->id, 'mtif_entry_id' => Fix::QOM_RAZAVIYYE_MTIF_VOLUME]);
        $ashnayi = CatalogVolume::create(['title' => 'آشنایی با چند نسخه خطی', 'citation_pattern' => Fix::ASHNAYI_CITATION, 'publication_year' => Fix::ASHNAYI_GUESSED_YEAR]);
        $hasanzadeh = Cataloger::create(['name' => Fix::HASANZADEH_OLD_NAME, 'slug' => Fix::HASANZADEH_SLUG]);
        $this->v['shyraz-shahchragh-97#3']->update(['publisher' => 'کتابخانه آستانه شیراز']);

        $this->artisan('catalogs:fix-volume-attributions')->assertSuccessful();

        $this->assertSame($qom->id, $razaviyye->fresh()->library_id);
        $this->assertSame(Fix::ASHNAYI_YEAR, $ashnayi->fresh()->publication_year);
        $this->assertSame(Fix::HASANZADEH_NAME, $hasanzadeh->fresh()->name);
        $this->assertSame(Fix::SHAHCHRAGH_PUBLISHER, $this->v['shyraz-shahchragh-97#3']->fresh()->publisher);
    }
}
