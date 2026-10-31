<?php

namespace Tests\Feature;

use App\Console\Commands\FixAwraqAtiqCatalogersCommand as Fix;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FixAwraqAtiqCatalogersTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, Cataloger> keyed by MTIF entry id */
    private array $c = [];

    /** @var array<string, Manuscript> keyed by library slug */
    private array $m = [];

    private CatalogVolume $first;

    private CatalogVolume $second;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([Fix::HAKIM, Fix::SADRAEI, Fix::BARAKAT] as $mtif) {
            $this->c[$mtif] = Cataloger::create(['name' => 'فهرست‌نگار ' . $mtif, 'slug' => 'c-' . $mtif . '-' . uniqid(), 'mtif_entry_id' => $mtif]);
        }
        $work = Work::create(['primary_title' => 'اثر آزمایشی', 'clean_title' => 'اثر آزمایشی', 'slug' => 'work-awraq-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $this->first = CatalogVolume::create(['title' => 'اوراق عتیق - دفتر 1', 'volume_number' => 1, 'citation_pattern' => 'اوراق عتیق: 1']);
        $this->second = CatalogVolume::create(['title' => 'اوراق عتیق - دفتر 2', 'volume_number' => 2, 'citation_pattern' => 'اوراق عتیق: 2']);
        foreach ([$this->first, $this->second] as $volume) {
            $volume->catalogers()->attach([$this->c[Fix::HAKIM]->id => ['role' => 'cataloger'], $this->c[Fix::SADRAEI]->id => ['role' => 'cataloger']]);
        }

        $slugs = [...array_keys(Fix::ARTICLES['اوراق عتیق: 1']), 'asfhan-adbyat-36', 'asfhan-hady-ngfy-974'];
        foreach ($slugs as $slug) {
            $library = Library::create(['name' => 'آزمایشی ' . $slug, 'city' => 'شهر', 'slug' => $slug]);
            $this->m[$slug] = Manuscript::create([
                'work_id' => $work->id, 'library_id' => $library->id, 'cataloger_id' => $this->c[Fix::SADRAEI]->id,
                'catalog_volume_id' => ($slug === 'asfhan-hady-ngfy-974' ? $this->second : $this->first)->id,
                'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1,
            ]);
        }
    }

    public function test_each_copy_goes_to_the_author_of_its_article(): void
    {
        $this->artisan('catalogs:fix-awraq-atiq')->assertSuccessful();

        $ghofrani = Cataloger::where('slug', Fix::GHOFRANI_SLUG)->first();
        $this->assertSame('عبدالله غفرانی', $ghofrani->name);
        $of = fn ($slug) => $this->m[$slug]->fresh()->cataloger_id;
        $this->assertSame($this->c[Fix::SADRAEI]->id, $of('tbryz-khadm-hsyny-85'));
        $this->assertSame($this->c[Fix::SADRAEI]->id, $of('khoy-mla-aaly-khoyy-shryf-171'));
        $this->assertSame($this->c[Fix::BARAKAT]->id, $of('shyraz-thamny-mhmd-rda-172'));
        $this->assertSame($ghofrani->id, $of('mshhd-mdrsh-ayh-allh-khoyy-74'));
        // an article no source names the author of
        $this->assertNull($of('asfhan-adbyat-36'));
        $this->assertNull($of('asfhan-hady-ngfy-974'));

        foreach ([$this->first, $this->second] as $volume) {
            $linked = $volume->catalogers()->get();
            $this->assertSame([$this->c[Fix::HAKIM]->id], $linked->pluck('id')->all());
            $this->assertSame('supervisor', $linked->first()->pivot->role);
        }
        $this->assertSame(2, $this->c[Fix::SADRAEI]->fresh()->manuscripts_count);
        $this->assertSame(0, $this->c[Fix::SADRAEI]->fresh()->volumes_count);
        $this->assertSame(6, $this->c[Fix::HAKIM]->fresh()->manuscripts_count);
    }

    public function test_a_second_run_changes_nothing(): void
    {
        $this->artisan('catalogs:fix-awraq-atiq')->assertSuccessful();
        $this->artisan('catalogs:fix-awraq-atiq')->assertSuccessful();

        $this->assertSame(1, Cataloger::where('slug', Fix::GHOFRANI_SLUG)->count());
        $this->assertSame($this->c[Fix::BARAKAT]->id, $this->m['shyraz-thamny-mhmd-rda-172']->fresh()->cataloger_id);
    }

    public function test_dry_run_saves_nothing(): void
    {
        $this->artisan('catalogs:fix-awraq-atiq', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($this->c[Fix::SADRAEI]->id, $this->m['shyraz-thamny-mhmd-rda-172']->fresh()->cataloger_id);
        $this->assertFalse(Cataloger::where('slug', Fix::GHOFRANI_SLUG)->exists());
    }
}
