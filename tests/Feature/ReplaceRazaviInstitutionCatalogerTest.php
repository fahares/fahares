<?php

namespace Tests\Feature;

use App\Console\Commands\ReplaceRazaviInstitutionCatalogerCommand as Fix;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class ReplaceRazaviInstitutionCatalogerTest extends TestCase
{
    use DatabaseTransactions;

    private Library $library;

    private Cataloger $institution;

    /** @var array<int, CatalogVolume> keyed by MTIF entry id */
    private array $volumes = [];

    /** @var array<int, Manuscript> keyed by MTIF entry id */
    private array $manuscripts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->library = Library::create(['name' => 'رضوی آزمایشی', 'city' => 'مشهد', 'slug' => 'razavi-test-' . uniqid()]);
        $this->institution = Cataloger::create(['name' => 'آستان قدس رضوی', 'slug' => Fix::INSTITUTION_SLUG, 'title_prefix' => 'نهاد مرجع']);
        $work = Work::create(['primary_title' => 'اثر آزمایشی رضوی', 'clean_title' => 'اثر آزمایشی رضوی', 'slug' => 'work-razavi-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);

        foreach ([...Fix::OKTAYI_VOLUMES, ...Fix::UNKNOWN_VOLUMES] as $i => $mtifId) {
            $volume = $this->volumes[$mtifId] = CatalogVolume::create([
                'title' => 'فهرست کتب خطی کتابخانه مرکزی آستان قدس رضوی - جلد ' . ($i + 2),
                'volume_number' => $i + 2,
                'library_id' => $this->library->id,
                'mtif_entry_id' => $mtifId,
            ]);
            $volume->catalogers()->attach($this->institution->id, ['role' => 'cataloger']);
            $this->manuscripts[$mtifId] = Manuscript::create([
                'work_id' => $work->id, 'library_id' => $this->library->id, 'catalog_volume_id' => $volume->id,
                'cataloger_id' => $this->institution->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1,
            ]);
        }
    }

    public function test_volumes_move_to_oktayi_and_the_institution_is_removed()
    {
        $this->artisan('catalogers:replace-razavi-institution')->assertSuccessful();

        $this->assertNull(Cataloger::where('slug', Fix::INSTITUTION_SLUG)->first());
        $oktayi = Cataloger::where('slug', Fix::OKTAYI_SLUG)->firstOrFail();
        $this->assertSame('اوکتایی', $oktayi->name);
        $this->assertNull($oktayi->birth_year_solar);
        $this->assertEqualsCanonicalizing(
            collect(Fix::OKTAYI_VOLUMES)->map(fn ($id) => $this->volumes[$id]->id)->all(),
            $oktayi->catalogVolumes()->pluck('catalog_volumes.id')->all()
        );
        $this->assertSame(3, $oktayi->volumes_count);
        $this->assertSame(3, $oktayi->manuscripts_count);

        foreach (Fix::OKTAYI_VOLUMES as $id) {
            $this->assertSame($oktayi->id, $this->manuscripts[$id]->fresh()->cataloger_id);
            $this->assertSame(Fix::SOURCE_NOTE, $this->volumes[$id]->fresh()->metadata['cataloger_note']);
        }
        foreach (Fix::UNKNOWN_VOLUMES as $id) {
            $this->assertNull($this->manuscripts[$id]->fresh()->cataloger_id);
            $this->assertSame(0, $this->volumes[$id]->catalogers()->count());
            $this->assertSame(Fix::UNKNOWN_NOTE, $this->volumes[$id]->fresh()->metadata['cataloger_note']);
        }
    }

    public function test_old_cataloger_link_redirects_to_the_library_and_rerun_is_harmless()
    {
        $oldKey = $this->institution->getRouteKey();
        $this->artisan('catalogers:replace-razavi-institution')->assertSuccessful();
        $this->artisan('catalogers:replace-razavi-institution')->assertSuccessful();

        $this->get(route('catalogers.show', ['id' => $oldKey]))
            ->assertStatus(301)
            ->assertRedirect(route('libraries.show', ['id' => $this->library->getRouteKey()]));
        $this->assertSame(1, Cataloger::where('slug', Fix::OKTAYI_SLUG)->count());
        $this->assertSame(3, Cataloger::where('slug', Fix::OKTAYI_SLUG)->value('manuscripts_count'));
    }

    public function test_an_unknown_cataloger_id_is_still_a_404()
    {
        $this->get(route('catalogers.show', ['id' => '999999999']))->assertNotFound();
    }
}
