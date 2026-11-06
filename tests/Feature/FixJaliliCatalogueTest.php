<?php

namespace Tests\Feature;

use App\Console\Commands\FixJaliliCatalogueCommand as Fix;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceVolume;
use App\Models\Work;
use App\Sources\SourceEntrySync as Sync;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FixJaliliCatalogueTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_jalili_catalogue_gets_vafadar_moradi_and_its_printing(): void
    {
        $rowzati = Cataloger::create(['name' => 'روضاتی', 'slug' => 'c-rz-' . uniqid(), 'mtif_entry_id' => Fix::ROWZATI]);
        $vafadar = Cataloger::create(['name' => 'وفادار مرادی', 'slug' => 'c-vm-' . uniqid(), 'mtif_entry_id' => Fix::VAFADAR]);
        $library = Library::create(['name' => 'جلیلی', 'city' => 'کرمانشاه', 'slug' => 'lib-jalili-' . uniqid()]);
        $title = 'فهرست نسخه‌های خطی کتابخانه خاندان جلیلی کرمانشاه';
        $source = Source::create(['code' => 'catalogue-508df9df7cd6', 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => $title,
            'library_id' => $library->id, 'metadata' => ['group' => "catalogue|{$library->id}|{$title}"]]);
        $volume = CatalogVolume::create(['title' => $title, 'volume_number' => 1, 'library_id' => $library->id]);
        SourceVolume::where('id', $volume->id)->update(['source_id' => $source->id]);
        $volume->catalogers()->attach($rowzati->id, ['role' => 'cataloger']);
        $work = Work::create(['primary_title' => 'اثر', 'clean_title' => 'اثر', 'slug' => 'work-jl-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $copy = Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'cataloger_id' => $rowzati->id,
            'catalog_volume_id' => $volume->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);

        $this->artisan('catalogs:fix-jalili')->assertSuccessful();
        $this->artisan('catalogs:fix-jalili')->assertSuccessful();

        $this->assertSame([$vafadar->id], $volume->catalogers()->pluck('catalogers.id')->all());
        $this->assertSame($vafadar->id, $copy->fresh()->cataloger_id);
        $source->refresh();
        $this->assertSame([Fix::TITLE, Fix::PUBLISHER, '1383'], [$source->title, $source->publisher, $source->publication_year]);
        $this->assertSame(Sync::groupKey($volume->fresh()), $source->metadata['group']);
        $this->assertSame(372, SourceVolume::find($volume->id)->pages_count);
        $this->assertSame(0, $rowzati->fresh()->manuscripts_count);
        $this->assertSame(1, $vafadar->fresh()->manuscripts_count);
    }
}
