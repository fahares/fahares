<?php

namespace Tests\Feature;

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
 * sources:fix-misnamed-isfahan (step 13.121): the volumes of Markaz-e Ehya's and Marashi's catalogues titled «فهرست کتب
 * خطی کتابخانه‌های اصفهان» take their series' title, publisher and catalogers, the series' empty record of each folds
 * into them, the two sources fold into their series, and a backup puts it all back.
 */
class FixMisnamedIsfahanCataloguesTest extends TestCase
{
    private const ISFAHAN = 'فهرست کتب خطی کتابخانه‌های اصفهان';

    private const EHYA = 'فهرست نسخه‌های خطی مرکز احیاء میراث اسلامی';

    private const MARASHI = 'فهرست نسخه‌های خطی کتابخانه عمومی آیت‌الله العظمی مرعشی نجفی';

    private Work $work;

    protected function setUp(): void
    {
        parent::setUp();
        $this->work = Work::create(['primary_title' => 'الکافی', 'clean_title' => 'الکافی', 'slug' => 'work-isf-' . uniqid(), 'volume_number' => 1, 'page_start' => 1]);
    }

    private function source(string $code, string $title, int $library, ?string $publisher = null): Source
    {
        return Source::create(['code' => $code, 'kind' => Source::KIND_LIBRARY_CATALOGUE, 'title' => $title, 'library_id' => $library, 'publisher' => $publisher]);
    }

    /** A volume of a source, with its catalog volume under the same id. */
    private function volume(Source $source, string $title, int $number, ?int $mtif, ?int $pages, ?string $publisher = null, ?string $year = null): SourceVolume
    {
        $columns = ['title' => $title, 'volume_number' => $number, 'mtif_entry_id' => $mtif, 'pages_count' => $pages, 'publisher' => $publisher, 'publication_year' => $year];
        $cv = CatalogVolume::create($columns + ['library_id' => $source->library_id]);
        DB::table('source_volumes')->updateOrInsert(['id' => $cv->id], $columns + ['source_id' => $source->id, 'created_at' => now(), 'updated_at' => now()]);

        return SourceVolume::findOrFail($cv->id);
    }

    private function copy(int $library, string $citation, SourceVolume $volume, ?Cataloger $cataloger = null): Manuscript
    {
        return Manuscript::create(['work_id' => $this->work->id, 'library_id' => $library, 'shelfmark' => (string) random_int(1, 99999),
            'catalog_volume_id' => $volume->id, 'cataloger_id' => $cataloger?->id, 'volume_number' => 1, 'page_start' => 1,
            'metadata' => ['catalog_citation' => $citation]]);
    }

    private function entry(Manuscript $copy): SourceEntry
    {
        return SourceEntry::where('legacy_type', 'fankha_citation')->where('legacy_id', $copy->id)->firstOrFail();
    }

    /** Every row the command may touch, to compare before and after a revert. */
    private function state(): array
    {
        $rows = fn ($table, $columns = ['*']) => DB::table($table)->orderBy('id')->get($columns)->map(fn ($r) => (array) $r)->all();

        return [
            'sources' => $rows('sources'),
            'source_volumes' => $rows('source_volumes'),
            'catalog_volumes' => $rows('catalog_volumes'),
            'pivot' => DB::table('catalog_volume_cataloger')->orderBy('catalog_volume_id')->orderBy('cataloger_id')
                ->get(['catalog_volume_id', 'cataloger_id', 'role'])->map(fn ($r) => (array) $r)->all(),
            'entries' => $rows('source_entries', ['id', 'source_id', 'source_volume_id', 'volume_number']),
            'copies' => $rows('manuscripts', ['id', 'catalog_volume_id']),
            'catalogers' => $rows('catalogers', ['id', 'volumes_count', 'manuscripts_count']),
        ];
    }

    public function test_the_isfahan_volumes_fold_into_ehya_and_marashi_and_revert()
    {
        $ehya = Library::create(['name' => 'مرکز احیاء', 'city' => 'قم', 'slug' => 'ehya-' . uniqid()])->id;
        $marashi = Library::create(['name' => 'مرعشی', 'city' => 'قم', 'slug' => 'marashi-' . uniqid()])->id;
        $eshkevari = Cataloger::create(['name' => 'سید احمد حسینی اشکوری', 'slug' => 'eshkevari-' . uniqid()]);
        $marashiSupervisor = Cataloger::create(['name' => 'سید محمود مرعشی', 'slug' => 'marashi-' . uniqid()]);
        $rowzati = Cataloger::create(['name' => 'سید محمدعلی روضاتی', 'slug' => 'rowzati-' . uniqid()]);

        // Ehya: volume 1 with the photographic catalogue's record, the empty second record of volume 2
        $ehyaSeries = $this->source('catalogue-0fc97a930b14', 'فهرست نسخه های خطی مرکز احیاء میراث اسلامی', $ehya, 'مرکز احیاء میراث اسلامی');
        $ehya1 = $this->volume($ehyaSeries, self::EHYA . ' - جلد 1', 1, 21319, 488, 'مرکز احیاء میراث اسلامی', '۱۳۷۷-۱۳۸۲');
        $ehya1->catalogers()->attach([$eshkevari->id => ['role' => 'cataloger']]);
        $ehyaRecord = $this->volume($ehyaSeries, 'فهرست نسخه های خطی مرکز احیاء میراث اسلامی - 2', 2, 21324, 467);
        $ehyaRecord->catalogers()->attach([$eshkevari->id => ['role' => 'cataloger']]);
        $stray = $this->copy($ehya, '[عکسی مرکز احیاء: ف: 7-341]', $ehyaRecord, $eshkevari);
        $ehyaIsfahan = $this->source('catalogue-f619df859857', self::ISFAHAN, $ehya, 'نشر نفایس مخطوطات اصفهان');
        $ehya2 = $this->volume($ehyaIsfahan, self::ISFAHAN . ' - جلد 2', 2, 21320, 496, 'نشر نفایس مخطوطات اصفهان', '۱۳۳۷-۱۳۴۱');
        $ehya2->catalogers()->attach([$rowzati->id => ['role' => 'cataloger']]);
        $ehyaCopy = $this->copy($ehya, '[ف: 2 - 90]', $ehya2, $eshkevari);

        // Marashi: volume 4 with its cataloger and supervisor, the empty second record of volume 12
        $marashiSeries = $this->source('catalogue-4c7c0fc2e037', self::MARASHI, $marashi, 'کتابخانه مرعشی');
        $marashi4 = $this->volume($marashiSeries, self::MARASHI . ' - جلد 4', 4, 21330, 414, 'کتابخانه مرعشی', '۱۳۵۴-۱۳۷۵');
        $marashi4->catalogers()->attach([$eshkevari->id => ['role' => 'cataloger'], $marashiSupervisor->id => ['role' => 'supervisor']]);
        $marashiRecord = $this->volume($marashiSeries, 'فهرست نسخه های خطی کتابخانه عمومی حضرت آیة الله العظمی مرعشی نجفی - 12', 12, 31541, 379);
        $marashiIsfahan = $this->source('catalogue-f80eb658096d', self::ISFAHAN, $marashi, 'نشر نفایس مخطوطات اصفهان');
        $marashi12 = $this->volume($marashiIsfahan, self::ISFAHAN . ' - جلد 12', 12, 21338, 378118, 'نشر نفایس مخطوطات اصفهان', '۱۳۳۷-۱۳۴۱');
        $marashi12->catalogers()->attach([$rowzati->id => ['role' => 'cataloger']]);
        $marashiCopy = $this->copy($marashi, '[ف: 12 - 5]', $marashi12, $eshkevari);
        $this->assertSame($marashiIsfahan->id, $this->entry($marashiCopy)->source_id);

        $before = $this->state();
        $this->artisan('sources:fix-misnamed-isfahan', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($before, $this->state(), 'a dry run saves nothing');

        $backup = storage_path('framework/testing/isfahan-' . uniqid() . '.json');
        $this->artisan('sources:fix-misnamed-isfahan', ['--backup' => $backup])->assertSuccessful();

        // the two sources are folded, their codes redirecting
        $this->assertNull(Source::find($ehyaIsfahan->id));
        $this->assertNull(Source::find($marashiIsfahan->id));
        $this->get('/sources/catalogue-f80eb658096d')->assertMovedPermanently()->assertRedirect(route('sources.show', 'catalogue-4c7c0fc2e037'));
        $this->assertSame('مرکز احیاء میراث اسلامی', $ehyaSeries->fresh()->publisher, 'Rowzati\'s publisher is not taken');

        // Ehya's volume 2 keeps its id and copies, under its series' title, record and cataloger; the empty record is gone
        $this->assertSame([$ehya1->id, $ehya2->id], $ehyaSeries->volumes()->pluck('id')->all());
        $this->assertNull(SourceVolume::find($ehyaRecord->id));
        $this->assertNull(CatalogVolume::find($ehyaRecord->id));
        $volume = $ehya2->fresh();
        $this->assertSame([self::EHYA . ' - جلد 2', 'مرکز احیاء میراث اسلامی', '۱۳۷۷-۱۳۸۲', 21324, 467],
            [$volume->title, $volume->publisher, $volume->publication_year, $volume->mtif_entry_id, $volume->pages_count]);
        $this->assertSame([self::EHYA . ' - جلد 2', 21324], [CatalogVolume::find($ehya2->id)->title, CatalogVolume::find($ehya2->id)->mtif_entry_id]);
        $this->assertSame([$eshkevari->id], $volume->catalogers()->pluck('catalogers.id')->all());
        $this->assertSame([21323, 534], [$ehya1->fresh()->mtif_entry_id, $ehya1->fresh()->pages_count], 'volume 1 takes the manuscript catalogue\'s record');
        foreach ([$ehyaCopy, $stray] as $copy) {
            $this->assertSame($ehya2->id, $copy->fresh()->catalog_volume_id);
            $this->assertSame([$ehyaSeries->id, $ehya2->id], [$this->entry($copy)->source_id, $this->entry($copy)->source_volume_id]);
        }
        $this->assertSame(2, $this->entry($ehyaCopy)->volume_number);

        // Marashi's volume 12: its own record, the second record's page count for MTIF's typo, cataloger and supervisor
        $volume = $marashi12->fresh();
        $this->assertSame([$marashiSeries->id, self::MARASHI . ' - جلد 12', 'کتابخانه مرعشی', 21338, 379],
            [$volume->source_id, $volume->title, $volume->publisher, $volume->mtif_entry_id, $volume->pages_count]);
        $this->assertNull(SourceVolume::find($marashiRecord->id));
        $this->assertEqualsCanonicalizing([[$eshkevari->id, 'cataloger'], [$marashiSupervisor->id, 'supervisor']],
            $volume->catalogers()->get()->map(fn ($c) => [$c->id, $c->pivot->role])->all());
        $this->assertSame([$marashiSeries->id, $marashi12->id, 12], [$this->entry($marashiCopy)->source_id, $this->entry($marashiCopy)->source_volume_id, $this->entry($marashiCopy)->volume_number]);
        $this->assertSame([0, 0], [$rowzati->fresh()->volumes_count, $rowzati->fresh()->manuscripts_count]);
        $this->assertSame(1, CatalogVolume::find($marashi12->id)->manuscripts_count);
        $this->get('/sources/catalogue-4c7c0fc2e037?list=manuscripts')->assertOk()->assertSee('الکافی', false);

        // again: nothing to do
        $after = $this->state();
        $this->artisan('sources:fix-misnamed-isfahan')->assertSuccessful()->expectsOutput('Nothing to do.');
        $this->assertSame($after, $this->state());

        // the backup puts every row back
        $this->artisan('sources:fix-misnamed-isfahan', ['--revert' => $backup])->assertSuccessful();
        $this->assertEquals($before, $this->state());
        @unlink($backup);
    }

    public function test_revert_refuses_a_file_it_did_not_write()
    {
        $file = storage_path('framework/testing/other-' . uniqid() . '.json');
        file_put_contents($file, json_encode(['command' => 'sources:merge-split']));
        $this->artisan('sources:fix-misnamed-isfahan', ['--revert' => $file])->assertFailed();
        @unlink($file);
    }
}
