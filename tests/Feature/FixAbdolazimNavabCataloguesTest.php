<?php

namespace Tests\Feature;

use App\Console\Commands\FixAbdolazimNavabCataloguesCommand as Fix;
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

class FixAbdolazimNavabCataloguesTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, Cataloger> keyed by MTIF entry id */
    private array $c = [];

    /** @var array<string, array<int, CatalogVolume>> source code => volume number => volume */
    private array $v = [];

    /** @var array<string, Manuscript> */
    private array $m = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([Fix::HAFEZIAN, Fix::SADRAEI, Fix::FAZEL, Fix::BINESH] as $mtif) {
            $this->c[$mtif] = Cataloger::create(['name' => 'فهرست‌نگار ' . $mtif, 'slug' => 'c-' . $mtif . '-' . uniqid(), 'mtif_entry_id' => $mtif]);
        }
        $work = Work::create(['primary_title' => 'اثر آزمایشی', 'clean_title' => 'اثر آزمایشی', 'slug' => 'work-an-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);

        $setup = [
            'catalogue-b89f67efe34f' => ['فهرست نسخه‌های خطی کتابخانه آستان حضرت عبدالعظیم حسنی (ع)', Fix::SADRAEI, [1, 2]],
            'catalogue-41322fd8f0f4' => ['فهرست نسخه‌های خطی کتابخانه مدرسه نواب مشهد', Fix::BINESH, [1]],
        ];
        foreach ($setup as $code => [$title, $wrong, $numbers]) {
            $library = Library::create(['name' => 'آزمایشی ' . $code, 'city' => 'شهر', 'slug' => 'lib-' . $code]);
            $source = Source::create(['code' => $code, 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => $title, 'library_id' => $library->id,
                'publisher' => 'فرهنگ ایران زمین', 'metadata' => ['group' => "catalogue|{$library->id}|{$title}"]]);
            foreach ($numbers as $n) {
                $volTitle = count($numbers) > 1 ? "{$title} - جلد {$n}" : $title;
                $volume = CatalogVolume::create(['title' => $volTitle, 'volume_number' => $n, 'library_id' => $library->id, 'publisher' => 'فرهنگ ایران زمین', 'publication_year' => '1344']);
                // saving a catalog volume makes its source volume (SourceEntrySync::syncVolume)
                SourceVolume::where('id', $volume->id)->update(['source_id' => $source->id]);
                $volume->catalogers()->attach($this->c[$wrong]->id, ['role' => 'cataloger']);
                $this->v[$code][$n] = $volume;
                $this->m["{$code}-{$n}"] = Manuscript::create([
                    'work_id' => $work->id, 'library_id' => $library->id, 'cataloger_id' => $this->c[$wrong]->id,
                    'catalog_volume_id' => $volume->id, 'sequence_number' => 1, 'volume_number' => 1, 'page_start' => 1,
                ]);
            }
        }
    }

    public function test_each_catalogue_gets_its_real_cataloger_and_printing(): void
    {
        $this->artisan('catalogs:fix-abdolazim-navab')->assertSuccessful();

        foreach ([['catalogue-b89f67efe34f', [1, 2], Fix::HAFEZIAN], ['catalogue-41322fd8f0f4', [1], Fix::FAZEL]] as [$code, $numbers, $right]) {
            foreach ($numbers as $n) {
                $linked = $this->v[$code][$n]->catalogers()->get();
                $this->assertSame([$this->c[$right]->id], $linked->pluck('id')->all());
                $this->assertSame($this->c[$right]->id, $this->m["{$code}-{$n}"]->fresh()->cataloger_id);
            }
        }

        $abdolazim = Source::where('code', 'catalogue-b89f67efe34f')->first();
        $this->assertSame('سازمان چاپ و نشر دار الحدیث', $abdolazim->publisher);
        $this->assertSame('1382', $abdolazim->publication_year);
        $second = SourceVolume::find($this->v['catalogue-b89f67efe34f'][2]->id);
        $this->assertSame([449, 678, '1382'], [$second->page_first, $second->page_last, $second->publication_year]);

        $navab = Source::where('code', 'catalogue-41322fd8f0f4')->first();
        $this->assertSame('فهرست نسخه‌های خطی مدرسه علمیه عالی نواب', $navab->title);
        $this->assertSame(['عروج اندیشه', '1388'], [$navab->publisher, $navab->publication_year]);
        $volume = $this->v['catalogue-41322fd8f0f4'][1]->fresh();
        $this->assertSame(['فهرست نسخه‌های خطی مدرسه علمیه عالی نواب', 'عروج اندیشه', '1388', 639],
            [$volume->title, $volume->publisher, $volume->publication_year, $volume->pages_count]);
        $this->assertSame('عروج اندیشه', SourceVolume::find($volume->id)->publisher);
        // sources:backfill finds the same source by the group of the new title
        $this->assertSame(Sync::groupKey($volume), $navab->metadata['group']);

        $this->assertSame(0, $this->c[Fix::SADRAEI]->fresh()->manuscripts_count);
        $this->assertSame(2, $this->c[Fix::HAFEZIAN]->fresh()->manuscripts_count);
        $this->assertSame(2, $this->c[Fix::HAFEZIAN]->fresh()->volumes_count);
        $this->assertSame(1, $this->c[Fix::FAZEL]->fresh()->manuscripts_count);
        $this->assertSame(0, $this->c[Fix::BINESH]->fresh()->volumes_count);
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->artisan('catalogs:fix-abdolazim-navab')->assertSuccessful();
        $this->artisan('catalogs:fix-abdolazim-navab')->assertSuccessful();

        $this->assertSame(2, $this->c[Fix::HAFEZIAN]->fresh()->manuscripts_count);
        $this->assertSame(1, $this->c[Fix::FAZEL]->fresh()->manuscripts_count);
    }

    public function test_it_refuses_a_volume_with_another_cataloger(): void
    {
        $this->v['catalogue-41322fd8f0f4'][1]->catalogers()->attach($this->c[Fix::HAFEZIAN]->id, ['role' => 'cataloger']);

        $this->artisan('catalogs:fix-abdolazim-navab')->assertFailed();
        $this->assertSame($this->c[Fix::SADRAEI]->id, $this->m['catalogue-b89f67efe34f-1']->fresh()->cataloger_id);
    }

    public function test_dry_run_saves_nothing(): void
    {
        $this->artisan('catalogs:fix-abdolazim-navab', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($this->c[Fix::BINESH]->id, $this->m['catalogue-41322fd8f0f4-1']->fresh()->cataloger_id);
        $this->assertSame('فهرست نسخه‌های خطی کتابخانه مدرسه نواب مشهد', Source::where('code', 'catalogue-41322fd8f0f4')->value('title'));
    }
}
