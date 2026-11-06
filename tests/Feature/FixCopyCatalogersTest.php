<?php

namespace Tests\Feature;

use App\Console\Commands\FixCopyCatalogersCommand as Fix;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceVolume;
use App\Models\Work;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class FixCopyCatalogersTest extends TestCase
{
    use DatabaseTransactions;

    private function catalogue(string $code, array $links, Cataloger $copiesTo): array
    {
        $library = Library::create(['name' => 'کتابخانه ' . $code, 'city' => 'شهر', 'slug' => 'lib-' . uniqid()]);
        $source = Source::create(['code' => $code, 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'فهرست ' . $code, 'library_id' => $library->id]);
        $volume = CatalogVolume::create(['title' => 'فهرست ' . $code, 'volume_number' => 1, 'library_id' => $library->id]);
        SourceVolume::where('id', $volume->id)->update(['source_id' => $source->id]);
        foreach ($links as [$cataloger, $role]) {
            $volume->catalogers()->attach($cataloger->id, ['role' => $role]);
        }
        $work = Work::create(['primary_title' => 'اثر', 'clean_title' => 'اثر', 'slug' => 'work-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
        $copy = Manuscript::create(['work_id' => $work->id, 'library_id' => $library->id, 'cataloger_id' => $copiesTo->id,
            'catalog_volume_id' => $volume->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1]);

        return [$volume, $copy];
    }

    private function cataloger(int $mtif): Cataloger
    {
        return Cataloger::create(['name' => 'فهرست‌نگار ' . $mtif, 'slug' => 'c-' . $mtif . '-' . uniqid(), 'mtif_entry_id' => $mtif]);
    }

    public function test_the_copies_go_to_the_cataloger_the_volume_names(): void
    {
        [$fazel, $hojjati, $rowzati, $ghasemi] = array_map(fn ($m) => $this->cataloger($m), [Fix::FAZEL, Fix::HOJJATI, Fix::ROWZATI, Fix::GHASEMI]);
        $reviewer = $this->cataloger(99999001);
        [$elahiyat, $elahiyatCopy] = $this->catalogue('catalogue-7006195fe18b', [[$hojjati, 'cataloger'], [$reviewer, 'supervisor'], [$fazel, 'cataloger']], $fazel);
        [$rowzatiLib, $rowzatiCopy] = $this->catalogue('catalogue-54ef008d33d5', [[$ghasemi, 'cataloger']], $rowzati);

        $this->artisan('catalogs:fix-copy-catalogers', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($fazel->id, $elahiyatCopy->fresh()->cataloger_id);

        $this->artisan('catalogs:fix-copy-catalogers')->assertSuccessful();
        $this->artisan('catalogs:fix-copy-catalogers')->assertSuccessful();

        $this->assertSame($hojjati->id, $elahiyatCopy->fresh()->cataloger_id);
        $this->assertSame($ghasemi->id, $rowzatiCopy->fresh()->cataloger_id);
        $this->assertEqualsCanonicalizing([$hojjati->id, $reviewer->id], $elahiyat->catalogers()->pluck('catalogers.id')->all());
        $this->assertSame([$ghasemi->id], $rowzatiLib->catalogers()->pluck('catalogers.id')->all());
        $this->assertSame([0, 0], [$fazel->fresh()->manuscripts_count, $fazel->fresh()->volumes_count]);
        $this->assertSame(1, $hojjati->fresh()->manuscripts_count);
        $this->assertSame(0, $rowzati->fresh()->manuscripts_count);
    }

    public function test_it_refuses_when_the_volume_lacks_the_right_cataloger(): void
    {
        [$fazel, , $rowzati, $ghasemi] = array_map(fn ($m) => $this->cataloger($m), [Fix::FAZEL, Fix::HOJJATI, Fix::ROWZATI, Fix::GHASEMI]);
        [, $copy] = $this->catalogue('catalogue-7006195fe18b', [[$fazel, 'cataloger']], $fazel);
        $this->catalogue('catalogue-54ef008d33d5', [[$ghasemi, 'cataloger']], $rowzati);

        $this->artisan('catalogs:fix-copy-catalogers')->assertFailed();
        $this->assertSame($fazel->id, $copy->fresh()->cataloger_id);
    }
}
