<?php

namespace Tests\Feature;

use App\Console\Commands\SplitEhyaPhotographicCatalogueCommand as Photographic;
use App\Console\Commands\SplitMohaddesArmaviCatalogueCommand as Armavi;
use App\Console\Commands\TieOddEhyaCitationsCommand as Tie;
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
 * sources:tie-odd-ehya-citations (step 13.131): Markaz-e Ehya's copies cited with volume and page reversed, a page
 * alone or «ف مخ» go to the volume the citation names, when all it gives agrees; a backup puts them back.
 */
class TieOddEhyaCitationsTest extends TestCase
{
    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'الکافی', 'clean_title' => 'الکافی', 'slug' => 'work-odd-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function volume(Source $source, int $number, array $columns): SourceVolume
    {
        $columns = ['title' => $source->title . ' - جلد ' . $number, 'volume_number' => $number] + $columns;
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
            'volumes' => $rows('catalog_volumes', ['id', 'manuscripts_count']),
            'entries' => $rows('source_entries', ['id', 'source_id', 'source_volume_id', 'volume_number', 'page_start']),
            'copies' => $rows('manuscripts', ['id', 'catalog_volume_id']),
            'catalogers' => $rows('catalogers', ['id', 'volumes_count', 'manuscripts_count']),
        ];
    }

    public function test_the_volume_a_citations_numbers_agree_on()
    {
        $this->assertSame([3, 1, 1, 1, 2, null, null, null], [Tie::armaviCited([1145, 3]), Tie::armaviCited([10, 404]), Tie::armaviCited([263]),
            Tie::armaviCited([1, 11]), Tie::armaviCited([2, 880]), Tie::armaviCited([2, 5]), Tie::armaviCited([1, 600]), Tie::armaviCited([])]);
        $this->assertSame([2, 5, 2, null, null, null], [Tie::photographicCited([412, 2]), Tie::photographicCited([17, 5]), Tie::photographicCited([2, 106]),
            Tie::photographicCited([10]), Tie::photographicCited([5, 6]), Tie::photographicCited([600, 2])]);
    }

    public function test_odd_citations_go_to_the_volume_they_name_and_revert()
    {
        $ehya = Library::create(['name' => 'مرکز احیاء', 'city' => 'قم', 'slug' => 'ehya-' . uniqid()])->id;
        $other = Library::create(['name' => 'مسجد اعظم', 'city' => 'قم', 'slug' => 'masjid-' . uniqid()])->id;
        $armavi = Source::create(['code' => Armavi::CODE, 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => Armavi::TITLE, 'library_id' => $ehya]);
        $a = [];
        foreach ([1, 2, 3] as $n) {
            $a[$n] = $this->volume($armavi, $n, ['citation_pattern' => 'محدث ارموی مخ: ' . $n]);
        }
        $photo = Source::create(['code' => Photographic::PHOTOGRAPHIC, 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => Photographic::TITLE, 'library_id' => $ehya]);
        $p = [];
        foreach (Photographic::VOLUMES as $n => [$mtif]) {
            $p[$n] = $this->volume($photo, $n, ['mtif_entry_id' => $mtif, 'citation_pattern' => 'عکسی ف: ' . $n]);
        }

        $expect = [
            [$this->copy($ehya, '[محدث ارموی مخ: 1145-3]', $a[3]), $a[3]],
            [$this->copy($ehya, '[محدث ارموی مخ: 34-1]', $a[3]), $a[1]],
            [$this->copy($ehya, '[محدث ارموی مخ: 10-404]', $a[3]), $a[1]],
            [$this->copy($ehya, '[محدث ارموی مخ: -264]', null), $a[1]],
            [$this->copy($ehya, '[محدث ارموی مخ: 2-5]', $a[3]), $a[3]],
            [$this->copy($ehya, '[محدث ارموی مخ: 1-600]', null), null],
            [$this->copy($ehya, '[محدث ارموی مخ]', null), null],
            [$this->copy($ehya, '[ف مخ: 1 - 330]', null), $a[1]],
            [$this->copy($ehya, '[ف مخ: 314-1]', null), $a[1]],
            [$this->copy($other, '[ف مخ: 1 - 330]', null), null],
            [$this->copy($ehya, '[عکسی ف: 412-2]', $p[7]), $p[2]],
            [$this->copy($ehya, '[عکسی ف: 242-7]', $p[7]), $p[7]],
            [$this->copy($ehya, '[در فهرست خوانساری کل این مجموعه با عنوان «تقریرات الفقه» آمده است؛ خط: نستعلیق [عکسی ف: 1 - 246]', $p[7]), $p[1]],
            [$this->copy($ehya, '[عکسی ف: 10]', $p[7]), $p[7]],
            [$this->copy($ehya, '[ف: 2 - 444 و عکسی ف: 350-5]', null), null],
        ];

        $before = $this->state();
        $this->artisan('sources:tie-odd-ehya-citations', ['--dry-run' => true])->assertSuccessful()->expectsOutput('7 copies tied anew.');
        $this->assertSame($before, $this->state(), 'a dry run saves nothing');

        $backup = storage_path('framework/testing/odd-' . uniqid() . '.json');
        $this->artisan('sources:tie-odd-ehya-citations', ['--backup' => $backup])->assertSuccessful();
        foreach ($expect as [$copy, $volume]) {
            $this->assertSame($volume?->id, $copy->fresh()->catalog_volume_id, $copy->metadata['catalog_citation']);
            if ($volume && $volume->source_id === $armavi->id) {
                $entry = SourceEntry::where('legacy_type', 'fankha_citation')->where('legacy_id', $copy->id)->firstOrFail();
                $this->assertSame([$armavi->id, $volume->id], [$entry->source_id, $entry->source_volume_id]);
            }
        }
        $this->assertSame([5, 2, 2], [CatalogVolume::find($a[1]->id)->manuscripts_count, CatalogVolume::find($a[3]->id)->manuscripts_count,
            CatalogVolume::find($p[7]->id)->manuscripts_count]);

        $after = $this->state();
        $this->artisan('sources:tie-odd-ehya-citations')->assertSuccessful()->expectsOutput('Nothing to do.');
        $this->assertSame($after, $this->state());

        $this->artisan('sources:tie-odd-ehya-citations', ['--revert' => $backup])->assertSuccessful();
        $this->assertEquals($before, $this->state());
        @unlink($backup);
    }
}
