<?php

namespace Tests\Feature;

use App\Models\Cataloger;
use App\Models\Library;
use App\Models\Source;
use App\Models\SourceVolume;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A cataloger's printed catalogues on their page (step 13.109): each catalogue once, with its cover and a link to its
 * source's page, its volumes named under it, even when the registry holds each volume as a source of its own.
 */
class CatalogerCataloguesTest extends TestCase
{
    public function test_a_catalogues_volumes_are_one_card_linking_its_source()
    {
        $library = Library::create(['name' => 'گلپایگانی', 'city' => 'قم', 'slug' => 'g-' . uniqid()]);
        $cataloger = Cataloger::create(['name' => 'سید احمد حسینی اشکوری', 'slug' => 'ashkevari-' . uniqid()]);
        $sources = [];
        foreach (['اول: آ - ج', 'دوم: ح - خ'] as $i => $volume) {
            $title = "فهرست نسخ خطی کتابخانه عمومی آیت‌الله گلپایگانی - جلد {$volume}";
            $sources[] = $source = Source::create(['code' => 'g-test-' . $i, 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'rank' => 10, 'title' => $title, 'library_id' => $library->id]);
            $id = DB::table('catalog_volumes')->insertGetId(['title' => $title, 'volume_number' => 1, 'library_id' => $library->id]);
            DB::table('source_volumes')->updateOrInsert(['id' => $id], ['source_id' => $source->id, 'volume_number' => 1]);
            DB::table('catalog_volume_cataloger')->insert(['catalog_volume_id' => $id, 'cataloger_id' => $cataloger->id, 'role' => 'cataloger']);
        }
        // a catalogue no source has stands alone
        $id = DB::table('catalog_volumes')->insertGetId(['title' => 'فهرست دیگر', 'volume_number' => 3]);
        DB::table('catalog_volume_cataloger')->insert(['catalog_volume_id' => $id, 'cataloger_id' => $cataloger->id, 'role' => 'cataloger']);
        DB::table('source_volumes')->where('id', $id)->delete();

        $html = $this->get(route('catalogers.show', $cataloger))->assertOk()
            ->assertSee('نسخه‌های فهرست‌شده', false)
            ->assertSee('href="' . route('sources.show', $sources[0]->code) . '"', false)
            ->assertSee('جلدهای اول، دوم', false)
            ->assertSee('فهرست دیگر', false)
            ->getContent();
        $this->assertSame(2, substr_count($html, 'data-cataloger-catalogue>'));
        $this->assertStringNotContainsString(route('sources.show', $sources[1]->code) . '"', $html);
    }
}
