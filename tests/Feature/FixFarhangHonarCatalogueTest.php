<?php

namespace Tests\Feature;

use App\Console\Commands\FixFarhangHonarCatalogueCommand as Fix;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceVolume;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FixFarhangHonarCatalogueTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_catalogue_goes_to_a_new_record_for_shakeri(): void
    {
        $binesh = Cataloger::create(['name' => 'تقی بینش', 'slug' => 'c-bn-' . uniqid(), 'mtif_entry_id' => Fix::BINESH]);
        $library = Library::create(['name' => 'فرهنگ و هنر', 'city' => 'مشهد', 'slug' => 'lib-fh-' . uniqid()]);
        $title = 'فهرست نسخه‌های خطی کتابخانه فرهنگ و هنر مشهد';
        $source = Source::create(['code' => 'catalogue-f72ec3ba3fbc', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => $title,
            'library_id' => $library->id, 'metadata' => ['group' => "catalogue|{$library->id}|{$title}"]]);
        $volume = CatalogVolume::create(['title' => $title, 'volume_number' => 1, 'library_id' => $library->id]);
        SourceVolume::where('id', $volume->id)->update(['source_id' => $source->id]);
        $volume->catalogers()->attach($binesh->id, ['role' => 'cataloger']);
        $work = Work::create(['primary_title' => 'اثر', 'clean_title' => 'اثر', 'slug' => 'work-fh-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $copy = Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'cataloger_id' => $binesh->id,
            'catalog_volume_id' => $volume->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);

        $this->artisan('catalogs:fix-farhang-honar', ['--dry-run' => true])->assertSuccessful();
        $this->assertNull(Cataloger::where('mtif_entry_id', Fix::SHAKERI)->first());

        $this->artisan('catalogs:fix-farhang-honar')->assertSuccessful();
        $this->artisan('catalogs:fix-farhang-honar')->assertSuccessful();

        $shakeri = Cataloger::where('mtif_entry_id', Fix::SHAKERI)->sole();
        $this->assertSame(['رمضانعلی شاکری', 'رمضانعلی-شاکری'], [$shakeri->name, $shakeri->slug]);
        $this->assertSame([$shakeri->id], $volume->catalogers()->pluck('catalogers.id')->all());
        $this->assertSame($shakeri->id, $copy->fresh()->cataloger_id);
        $this->assertSame([1, 1], [$shakeri->volumes_count, $shakeri->manuscripts_count]);
        $this->assertSame([Fix::TITLE, '1348'], [$source->fresh()->title, $source->fresh()->publication_year]);
        $this->assertSame(0, $binesh->fresh()->manuscripts_count);
    }
}
