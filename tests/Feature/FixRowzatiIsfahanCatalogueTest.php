<?php

namespace Tests\Feature;

use App\Console\Commands\FixRowzatiIsfahanCatalogueCommand as Fix;
use App\Models\Cataloger;
use App\Models\CatalogVolume;
use App\Models\Library;
use App\Models\Manuscript;
use App\Models\Source;
use App\Models\SourceEntry;
use App\Models\SourceVolume;
use App\Models\Work;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * sources:fix-rowzati-isfahan (step 13.137): Rowzati's Isfahan catalogue gets MTIF 21530 and «کتابخانه‌های اصفهان»,
 * and the copies of other catalogues on it go to theirs; a backup puts it all back.
 */
class FixRowzatiIsfahanCatalogueTest extends TestCase
{
    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'الکافی', 'clean_title' => 'الکافی', 'slug' => 'work-rowzati-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function library(string $name): int
    {
        return Library::create(['name' => $name, 'city' => 'اصفهان', 'slug' => 'lib-' . uniqid()])->id;
    }

    private function volume(Source $source, int $number, array $columns = []): SourceVolume
    {
        $columns = ['title' => $source->title, 'volume_number' => $number] + $columns;
        $cv = CatalogVolume::create($columns + ['library_id' => $source->library_id]);
        DB::table('source_volumes')->updateOrInsert(['id' => $cv->id], $columns + ['source_id' => $source->id, 'created_at' => now(), 'updated_at' => now()]);

        return SourceVolume::findOrFail($cv->id);
    }

    private function copy(int $library, string $citation, ?SourceVolume $volume): Manuscript
    {
        return Manuscript::create(['work_id' => $this->work->id, 'library_id' => $library, 'shelfmark' => (string) random_int(1, 99999),
            'catalog_volume_id' => $volume?->id, 'volume_number' => 1, 'page_start' => 1, 'metadata' => ['catalog_citation' => $citation]]);
    }

    private function state(): array
    {
        $rows = fn ($table, $columns) => DB::table($table)->orderBy('id')->get($columns)->map(fn ($r) => (array) $r)->all();

        return [
            'sources' => $rows('sources', ['id', 'library_id', 'metadata']),
            'source_volumes' => $rows('source_volumes', ['id', 'mtif_entry_id', 'pages_count', 'publication_year', 'citation_pattern']),
            'catalog_volumes' => $rows('catalog_volumes', ['id', 'library_id', 'mtif_entry_id', 'pages_count', 'publication_year', 'citation_pattern', 'manuscripts_count']),
            'entries' => $rows('source_entries', ['id', 'source_id', 'source_volume_id', 'volume_number', 'page_start']),
            'copies' => $rows('manuscripts', ['id', 'catalog_volume_id']),
            'catalogers' => $rows('catalogers', ['id', 'volumes_count', 'manuscripts_count']),
        ];
    }

    public function test_the_catalogue_keeps_its_own_copies_and_the_others_go_to_theirs_and_revert()
    {
        $rowzati = $this->library('روضاتی');
        $isfahan = $this->library(Fix::LIBRARY[0]);
        $mohammadAli = $this->library('روضاتی، سید محمد علی');
        $allameh = $this->library('علامه روضاتی (کتابخانه)');
        $compiler = Cataloger::create(['name' => 'سید محمدعلی روضاتی', 'slug' => 'rowzati-' . uniqid()]);

        $source = Source::create(['code' => Fix::CODE, 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'فهرست کتب خطی کتابخانه‌های اصفهان (نفایس مخطوطات)',
            'library_id' => $rowzati, 'metadata' => ['group' => "catalogue|{$rowzati}|فهرست کتب خطی کتابخانه‌های اصفهان (نفایس مخطوطات)"]]);
        $volume = $this->volume($source, 1, ['publication_year' => '1340', 'citation_pattern' => 'نفایس مخطوطات']);
        $volume->catalogers()->attach([$compiler->id => ['role' => 'cataloger']]);
        $ahmad = $this->volume(Source::create(['code' => Fix::AHMAD, 'kind' => Source::KIND_LIBRARY_CATALOGUE,
            'title' => 'فهرست نسخه‌های خطی کتابخانه سید احمد روضاتی', 'library_id' => $this->library('روضاتی، سید احمد')]), 1);
        $nashriyeh = Source::create(['code' => Fix::NASHRIYEH, 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => 'نشریه نسخه‌های خطی کتابخانه مرکزی دانشگاه تهران']);
        $fifth = $this->volume($nashriyeh, 5, ['citation_pattern' => 'نشریه: 5']);
        $this->volume($nashriyeh, 1, ['citation_pattern' => 'نشریه: 1']);

        $expect = [
            [$this->copy($isfahan, '[فهرست روضاتی: - 123]', $volume), $volume],
            [$this->copy($isfahan, '[فهرست روضاتی]', $volume), $volume],
            [$this->copy($rowzati, '[ف: - 102]', $volume), $ahmad],
            [$this->copy($rowzati, '[روضاتی - ف: 121]', $volume), $ahmad],
            [$this->copy($mohammadAli, '[نشریه: 5 - 132]', $volume), $fifth],
            [$this->copy($allameh, '[نسخه پژوهی: 1 - 146]', $volume), null],
            [$this->copy($allameh, '[نسخه‌پژوهی: 1 - 145]', $volume), null],
            [$this->copy($rowzati, '[ف: - 40]', null), null],
        ];

        $before = $this->state();
        $this->artisan('sources:fix-rowzati-isfahan', ['--dry-run' => true])->assertSuccessful()->expectsOutput('5 copies tied anew.');
        $this->assertSame($before, $this->state(), 'a dry run saves nothing');

        $backup = storage_path('framework/testing/rowzati-' . uniqid() . '.json');
        $this->artisan('sources:fix-rowzati-isfahan', ['--backup' => $backup])->assertSuccessful();
        foreach ($expect as [$copy, $target]) {
            $this->assertSame($target?->id, $copy->fresh()->catalog_volume_id, $copy->metadata['catalog_citation']);
            $entry = SourceEntry::where('legacy_type', 'fankha_citation')->where('legacy_id', $copy->id)->firstOrFail();
            $this->assertSame([$target?->source_id, $target?->id], [$entry->source_id, $entry->source_volume_id]);
        }
        $cv = CatalogVolume::find($volume->id);
        $this->assertSame([$isfahan, 21530, 408, '۱۳۳۷-۱۳۴۱', 'فهرست روضاتی', 2], [$cv->library_id, $cv->mtif_entry_id, $cv->pages_count,
            $cv->publication_year, $cv->citation_pattern, $cv->manuscripts_count]);
        $this->assertSame([21530, 'فهرست روضاتی'], [$volume->fresh()->mtif_entry_id, $volume->fresh()->citation_pattern]);
        $this->assertSame($isfahan, $source->fresh()->library_id);
        $this->assertSame("catalogue|{$isfahan}|فهرست کتب خطی کتابخانه‌های اصفهان (نفایس مخطوطات)", $source->fresh()->metadata['group']);
        $this->assertSame([2, 1], [CatalogVolume::find($ahmad->id)->manuscripts_count, CatalogVolume::find($fifth->id)->manuscripts_count]);
        $this->assertSame(2, $compiler->fresh()->manuscripts_count);

        $after = $this->state();
        $this->artisan('sources:fix-rowzati-isfahan')->assertSuccessful()->expectsOutput('Nothing to do.');
        $this->assertSame($after, $this->state());

        $this->artisan('sources:fix-rowzati-isfahan', ['--revert' => $backup])->assertSuccessful();
        $this->assertEquals($before, $this->state());
        @unlink($backup);
    }
}
